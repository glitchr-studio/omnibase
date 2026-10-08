<?php

namespace Base\Service;

use Base\Repository\Thread\CommentRepository;
use Omniguard\Exception\InvalidKeyException;
use Omniguard\Exception\ProviderException;
use Omniguard\Model\Identity;
use Omniguard\Registry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * What stands between a form and a robot, in the order it is asked
 * (docs/20-architecture/guard.md):
 *
 *   1. the trap - a field off-screen for people, filled by robots;
 *   2. the time - a form sent faster than a person reads it, by a stamp
 *      signed with the application's secret when it was shown;
 *   3. the lists - whoever sends it (address, e-mail, name) asked of the
 *      gateways of base.guard.reputation: disposable e-mail domains,
 *      StopForumSpam;
 *   4. the captcha - a field of glitchr/omniguard (ChallengeType) on the
 *      gateway of base.guard.challenge, checked by its own constraint;
 *   5. what was written - the classifier behind SpamChecker (Akismet), for
 *      the data that implement SpamProtectionInterface.
 *
 * Without glitchr/omniguard, or without a gateway, the trap and the time
 * alone. The option `guard` of a form asks for it
 * (Base\Form\Extension\FormTypeGuardExtension); CommentGuard is the comment
 * forms' face of it, with their flood interval.
 *
 * Each answer is a reason, a translation key of the "forms" domain
 * (guard.<reason>) - but the trap, which a controller may rather thank and
 * forget, so that the robot learns nothing.
 */
class FormGuard
{
    public const TRAPPED = 'trapped';
    public const TOO_FAST = 'too_fast';
    public const STALE = 'stale';
    public const FLOOD = 'flood';
    public const KNOWN = 'known';
    public const DISPOSABLE = 'disposable';
    public const UNREACHABLE = 'unreachable';
    public const SPAM = 'spam';

    /** The captcha's answers: no token, a token refused, a provider that did not answer. */
    public const CHALLENGE_MISSING = 'challenge_missing';
    public const CHALLENGE_FAILED = 'challenge_failed';
    public const CHALLENGE_UNREACHABLE = 'challenge_unreachable';

    /** What they say: glitchr/omniguard's own sentences (PassesChallenge), translated in the "validators" domain. */
    public const CHALLENGE_MESSAGES = [
        self::CHALLENGE_MISSING => 'Please confirm that you are not a robot.',
        self::CHALLENGE_FAILED => 'The check that you are not a robot did not pass. Please try again.',
        self::CHALLENGE_UNREACHABLE => 'The check that you are not a robot could not be done just now. Please try again in a moment.',
    ];

    /** The fields the option `guard` adds to a form: the trap, the stamp, the captcha. */
    public const TRAP_FIELD = 'guard_website';
    public const STAMP_FIELD = 'guard_opened';
    public const CHALLENGE_FIELD = 'guard_captcha';
    /** The captcha that reaches nobody, shown while the visitor has not agreed to the one that does. */
    public const FALLBACK_FIELD = 'guard_captcha_fallback';

    /**
     * @param array{challenge?: string|bool|null, fallback?: ?string, reputation?: list<string>, classifier?: ?string, unreachable?: string, min_delay?: int, sign_in_after?: int} $config base.guard
     * @param string|null $defaultChallenge omniguard.challenge.gateway, when glitchr/omniguard is installed (GuardPass)
     */
    public function __construct(
        #[Autowire('%kernel.secret%')] #[\SensitiveParameter] protected readonly string $secret,
        #[Autowire('%base.guard%')] protected readonly array $config = [],
        protected readonly ?Registry $registry = null,
        protected readonly ?CommentRepository $comments = null,
        protected readonly ?string $defaultChallenge = null,
        protected readonly ?LoggerInterface $logger = null,
        protected readonly bool $acceptUnreachableChallenge = false,
    ) {
    }

    /** Whether glitchr/omniguard is here, with its gateways. */
    public function hasOmniguard(): bool
    {
        return null !== $this->registry;
    }

    public function getRegistry(): ?Registry
    {
        return $this->registry;
    }

    public function minDelay(): int
    {
        return (int) ($this->config['min_delay'] ?? 3);
    }

    /** Failed sign-ins from an address before the sign-in form asks the captcha. 0: never. */
    public function signInAfter(): int
    {
        return (int) ($this->config['sign_in_after'] ?? 3);
    }

    /**
     * The captcha's gateway: base.guard.challenge, else omniguard's default -
     * null when there is none, or when omniguard's form field is missing.
     */
    public function challengeGateway(): ?string
    {
        $name = $this->config['challenge'] ?? null;
        if (false === $name || 'false' === $name || '' === $name) {
            return null;
        }
        $name ??= $this->defaultChallenge;

        return null !== $name && $this->registry?->has((string) $name) && class_exists(\Omniguard\Bridge\Symfony\Form\ChallengeType::class) ? (string) $name : null;
    }

    /** The captcha shown to a visitor who refused the third party the default one reaches. */
    public function fallbackGateway(): ?string
    {
        $name = $this->config['fallback'] ?? null;

        return \is_string($name) && '' !== $name && $this->registry?->has($name) ? $name : null;
    }

    /** @return list<string> the lists asked about whoever submits, those configured in omniguard */
    public function reputationGateways(): array
    {
        return array_values(array_filter((array) ($this->config['reputation'] ?? []), fn ($name) => \is_string($name) && $this->registry?->has($name)));
    }

    public function classifierGateway(): ?string
    {
        $name = $this->config['classifier'] ?? null;

        return \is_string($name) && '' !== $name ? $name : null;
    }

    public function rejectsUnreachable(): bool
    {
        return 'reject' === ($this->config['unreachable'] ?? 'accept');
    }

    /** When the form was shown, signed: "<time>.<signature>". */
    public function stamp(?int $time = null): string
    {
        $time ??= time();

        return $time.'.'.$this->sign((string) $time);
    }

    /** Seconds since the stamp was made; null for one that is not ours (forged, cut, missing). */
    public function elapsed(?string $stamp): ?int
    {
        if (null === $stamp || !preg_match('/^(\d{1,12})\.([A-Za-z0-9_-]{16,})$/', trim($stamp), $parts)) {
            return null;
        }

        return hash_equals($this->sign($parts[1]), $parts[2]) ? time() - (int) $parts[1] : null;
    }

    /**
     * The trap and the time of a guarded form (the fields the option `guard`
     * added), then the lists. Null when the form may go on to its captcha
     * and its classifier.
     *
     * @return array{0: string, 1: ?string}|null the reason, and the field it is about (null: the form)
     */
    public function inspect(FormInterface $form, ?Request $request, ?int $minDelay = null, string $emailField = 'email', string $nameField = 'name', bool $lists = true): ?array
    {
        if ($form->has(self::TRAP_FIELD) && '' !== trim((string) $form->get(self::TRAP_FIELD)->getData())) {
            return [self::TRAPPED, null];
        }

        $minDelay ??= $this->minDelay();
        if ($form->has(self::STAMP_FIELD)) {
            $elapsed = $this->elapsed((string) $form->get(self::STAMP_FIELD)->getData());
            if (null === $elapsed) {
                return [self::STALE, null];
            }
            if ($minDelay > 0 && $elapsed < $minDelay) {
                return [self::TOO_FAST, null];
            }
        }

        // No list to ask - none configured, or glitchr/omniguard (suggested, not required) not installed:
        // the trap and the time alone. Its Identity is not even built then: without the family the class
        // does not exist, and the first guarded form sent answered 500.
        if (!$lists || [] === $this->reputationGateways()) {
            return null;
        }
        $email = $this->value($form, $emailField);
        $reason = $this->reputation(new Identity($request?->getClientIp(), $email, $this->value($form, $nameField)));

        return null === $reason ? null : [$reason, self::DISPOSABLE === $reason && $form->has($emailField) ? $emailField : null];
    }

    /**
     * What the lists of base.guard.reputation say of whoever submits: null
     * when none knows them; DISPOSABLE for an e-mail of a throwaway domain;
     * KNOWN otherwise; UNREACHABLE for a list that did not answer when
     * base.guard.unreachable is "reject".
     */
    public function reputation(Identity $identity): ?string
    {
        if ($identity->isEmpty()) {
            return null;
        }
        foreach ($this->reputationGateways() as $name) {
            try {
                $reputation = $this->registry->reputation($name)->lookup($identity);
            } catch (InvalidKeyException $e) {
                // The site's key, not the visitor's fault: let them through, and say it where the site reads.
                $this->logger?->error('Form guard: the list "{list}" refused the site\'s key: {message}', ['list' => $name, 'message' => $e->getMessage()]);
                continue;
            } catch (ProviderException $e) {
                $this->logger?->warning('Form guard: the list "{list}" did not answer: {message}', ['list' => $name, 'message' => $e->getMessage()]);
                if ($this->rejectsUnreachable()) {
                    return self::UNREACHABLE;
                }
                continue;
            }
            if ($reputation->known) {
                return \in_array('disposable', $reputation->reasons, true) ? self::DISPOSABLE : self::KNOWN;
            }
        }

        return null;
    }

    /**
     * The captcha's token, asked of its gateway - once, after the trap, the
     * time and the lists: a robot they caught spends nothing at the provider.
     * Null when it holds; otherwise one of CHALLENGE_*. A provider that does
     * not answer follows omniguard.challenge.unreachable; a key refused is
     * the site's error, let through and logged.
     */
    public function challenge(string $gateway, ?string $token, ?Request $request, ?string $action = null): ?string
    {
        $token = trim((string) $token);
        if ('' === $token) {
            return self::CHALLENGE_MISSING;
        }
        try {
            $verdict = $this->registry->challenge($gateway)->verify(new \Omniguard\Model\Attempt($token, $request?->getClientIp(), $action));
        } catch (InvalidKeyException $e) {
            $this->logger?->error('Form guard: the captcha "{gateway}" refused the site\'s key: {message}', ['gateway' => $gateway, 'message' => $e->getMessage()]);

            return null;
        } catch (ProviderException $e) {
            $this->logger?->warning('Form guard: the captcha "{gateway}" did not answer: {message}', ['gateway' => $gateway, 'message' => $e->getMessage()]);

            return $this->acceptUnreachableChallenge ? null : self::CHALLENGE_UNREACHABLE;
        }
        if ($verdict->passed) {
            return null;
        }

        return $verdict->failedFor(\Omniguard\Model\Verdict::MISSING) ? self::CHALLENGE_MISSING : self::CHALLENGE_FAILED;
    }

    /**
     * The comment forms' check (Base\Form\Type\CommentType: its `url` trap,
     * its `opened` time; or the fields the option `guard` adds), and a second
     * comment from the same address within $floodInterval. Null when the
     * comment may go on to its classifier.
     */
    public function check(FormInterface $form, Request $request, ?int $minDelay = null, int $floodInterval = 0): ?string
    {
        $minDelay ??= $this->minDelay();

        if ($form->has('url') && '' !== trim((string) $form->get('url')->getData())) {
            return self::TRAPPED;
        }
        if ($form->has('opened')) {
            $opened = (int) $form->get('opened')->getData();
            if ($minDelay > 0 && ($opened <= 0 || time() - $opened < $minDelay)) {
                return self::TOO_FAST;
            }
        }
        if (null !== $found = $this->inspect($form, $request, $minDelay, lists: false)) {
            return self::STALE === $found[0] ? self::TOO_FAST : $found[0];
        }

        $ip = $request->getClientIp();
        if ($floodInterval > 0 && $ip && $this->comments) {
            $last = $this->comments->findLastFromIp($ip);
            if ($last && $last->getCreatedAt() && time() - $last->getCreatedAt()->getTimestamp() < $floodInterval) {
                return self::FLOOD;
            }
        }

        return null;
    }

    /** A field's text, or the form data's property of that name. */
    protected function value(FormInterface $form, string $field): ?string
    {
        if ($form->has($field)) {
            $value = $form->get($field)->getData();
        } else {
            $data = $form->getData();
            $getter = 'get'.ucfirst($field);
            $value = \is_object($data) && method_exists($data, $getter) ? $data->$getter() : (\is_array($data) ? ($data[$field] ?? null) : null);
        }
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private function sign(string $value): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'base.guard|'.$value, $this->secret, true)), '+/', '-_'), '=');
    }
}
