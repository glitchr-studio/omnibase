<?php

namespace Base\Service;

use Base\Enum\SpamApi;
use Base\Enum\SpamScore;
use Base\Routing\AdvancedRouterInterface;
use Base\Service\Model\SpamProtectionInterface;
use Omnishield\Akismet\AkismetGatewayFactory;
use Omnishield\ClassifierInterface;
use Omnishield\Exception\InvalidKeyException;
use Omnishield\Exception\OmnishieldException;
use Omnishield\Exception\ProviderException;
use Omnishield\Model\Label;
use Omnishield\Model\Submission;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Is what a visitor wrote spam? The classifier of glitchr/omnishield behind
 * omnibase's SpamCheckerInterface (check, score), which the forms
 * (spam_protection), the comments and omnibase/faq's questions ask:
 *
 *   - base.guard.classifier, a gateway of omnishield.gateways;
 *   - else Akismet (omnishield/akismet), with the key typed in the back
 *     office (api.spam.akismet, admin's SpamKeySection) or configured
 *     (base.spam.akismet);
 *   - else nothing is asked: NOT_SPAM, as without a key before.
 *
 * What is sent is built here from the request and the candidate: the
 * visitor's address, browser and referrer, the page, the site's home page
 * (not the current page), its language, the author's name and e-mail as
 * text, the date. A provider that does not answer is base.guard.unreachable:
 * accept (NOT_SPAM) or reject (MAYBE_SPAM, held for a person); a key refused
 * is the site's error, let through and logged. Nothing is swallowed silently.
 */
class SpamChecker implements SpamCheckerInterface
{
    protected ?ClassifierInterface $classifier = null;
    protected bool $resolved = false;

    public function __construct(
        protected RequestStack $requestStack,
        protected SettingBagInterface $settingBag,
        protected ParameterBagInterface $parameterBag,
        protected TranslatorInterface $translator,
        protected HttpClientInterface $client,
        protected bool $debug,
        protected ?FormGuard $guard = null,
        protected ?AdvancedRouterInterface $router = null,
        protected ?LoggerInterface $logger = null,
    ) {
    }

    public function getLang(): ?string
    {
        $defaultLocale = $this->parameterBag->get("kernel.default_locale");
        $fallbacks = $this->translator->getFallbackLocales();
        $locale = $this->translator->getLocale();

        return (string) (\in_array($locale, $fallbacks) ? $locale : $defaultLocale);
    }

    /** The site's home page: what Akismet calls the blog - not the page the form is on. */
    public function getUrl(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        try {
            $home = $this->router?->getUrlIndex();
        } catch (\Throwable) {
            $home = null;
        }
        if ($home && preg_match('#^https?://#', $home)) {
            return $home;
        }

        return $request ? $request->getSchemeAndHttpHost().'/'.ltrim((string) $home, '/') : null;
    }

    /**
     * The key: typed in the back office (api.spam.akismet), else configured (base.spam.akismet).
     *
     * @deprecated $api: a classifier is named by base.guard.classifier now
     */
    public function getKey($api = SpamApi::AKISMET): ?string
    {
        $key = $this->settingBag->getScalar("api.spam.akismet") ?: $this->parameterBag->get("base.spam.akismet");

        return \is_string($key) && '' !== trim($key) ? trim($key) : null;
    }

    /** The classifier asked, or null when there is none. */
    public function classifier(): ?ClassifierInterface
    {
        if ($this->resolved) {
            return $this->classifier;
        }
        $this->resolved = true;

        $registry = $this->guard?->getRegistry();
        $name = $this->guard?->classifierGateway();
        try {
            if (null !== $name && $registry?->has($name)) {
                $gateway = $registry->classifier($name);
                // Akismet configured by name: a key typed in the back office still wins.
                if ('akismet' === $gateway->getName() && null !== $key = $this->settingBag->getScalar("api.spam.akismet")) {
                    $gateway = $registry->create($name, ['api_key' => $key] + array_filter(['site' => $this->getUrl()]));
                }

                return $this->classifier = $gateway instanceof ClassifierInterface ? $gateway : null;
            }
            if (class_exists(AkismetGatewayFactory::class) && null !== $key = $this->getKey()) {
                $gateway = (new AkismetGatewayFactory($this->client))->create(['api_key' => $key, 'site' => $this->getUrl(), 'language' => $this->getLang(), 'test' => $this->debug]);

                return $this->classifier = $gateway instanceof ClassifierInterface ? $gateway : null;
            }
        } catch (OmnishieldException $e) {
            $this->logger?->error('Spam checker: the classifier could not be built: {message}', ['message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * What the classifier is told of a candidate.
     *
     * @param array<string, mixed> $context what the caller knows better: comment_author, comment_author_email, comment_author_url, comment_type (Akismet's names), permalink
     */
    public function submission(SpamProtectionInterface $candidate, array $context = []): Submission
    {
        $request = $this->requestStack->getCurrentRequest();
        $user = $candidate->getSpamBlameable();
        $date = $candidate->getSpamDate();

        return new Submission(
            (string) $candidate->getSpamText(),
            $this->text($context['comment_author'] ?? $this->read($candidate, 'name') ?? ($user ? (string) $user : null)),
            $this->text($context['comment_author_email'] ?? $this->read($candidate, 'email') ?? $user?->getEmail()),
            $this->text($context['comment_author_url'] ?? $this->read($candidate, 'website')),
            $this->text($context['user_ip'] ?? $request?->getClientIp() ?? $this->read($candidate, 'ip')),
            $this->text($context['user_agent'] ?? $request?->headers->get('user-agent') ?? $this->read($candidate, 'userAgent')),
            $this->text($context['referrer'] ?? $request?->headers->get('referer')),
            $this->text($context['permalink'] ?? $request?->getUri()),
            $this->getUrl(),
            $this->getLang(),
            (string) ($context['comment_type'] ?? Submission::COMMENT),
            \DateTimeImmutable::createFromInterface($date),
        );
    }

    public function check(SpamProtectionInterface $candidate, array $context = [], $api = SpamApi::AKISMET): int
    {
        $score = $this->score($candidate, $context, $api);
        $candidate->getSpamCallback($score);

        return $score;
    }

    /** @return int 0: not spam, 1: maybe spam (held for a person), 2: blatant spam; -1: no text */
    public function score(SpamProtectionInterface $candidate, array $context = [], $api = SpamApi::AKISMET): int
    {
        $enum = SpamScore::__toInt();
        if (empty($candidate->getSpamText())) {
            return $enum[SpamScore::NO_TEXT];
        }
        if (null === $classifier = $this->classifier()) {
            return $enum[SpamScore::NOT_SPAM];
        }

        try {
            $classification = $classifier->classify($this->submission($candidate, $context));
        } catch (InvalidKeyException $e) {
            $this->logger?->error('Spam checker: {classifier} refused the site\'s key - nothing is classified until it is fixed: {message}', ['classifier' => $classifier->getName(), 'message' => $e->getMessage()]);

            return $enum[SpamScore::NOT_SPAM];
        } catch (ProviderException $e) {
            $this->logger?->warning('Spam checker: {classifier} did not answer: {message}', ['classifier' => $classifier->getName(), 'message' => $e->getMessage()]);

            return $enum[$this->guard?->rejectsUnreachable() ? SpamScore::MAYBE_SPAM : SpamScore::NOT_SPAM];
        } catch (OmnishieldException $e) {
            // A submission the classifier cannot take (no visitor's address, from the command line): not asked.
            $this->logger?->warning('Spam checker: nothing asked of {classifier}: {message}', ['classifier' => $classifier->getName(), 'message' => $e->getMessage()]);

            return $enum[SpamScore::NOT_SPAM];
        }

        return $enum[match ($classification->label) {
            Label::FLAGRANT => SpamScore::BLATANT_SPAM,
            Label::SPAM => SpamScore::MAYBE_SPAM,
            default => SpamScore::NOT_SPAM,
        }];
    }

    /**
     * Teach the classifier: a submission it let through that was spam
     * ($spam true), one it held that was not. False when there is no
     * classifier to tell; a provider's error is thrown.
     */
    public function report(Submission $submission, bool $spam): bool
    {
        $classifier = $this->classifier();
        if (null === $classifier) {
            return false;
        }
        $classifier->report($submission, $spam);

        return true;
    }

    private function read(object $candidate, string $field): mixed
    {
        $getter = 'get'.ucfirst($field);
        if (method_exists($candidate, $getter)) {
            return $candidate->$getter();
        }

        return property_exists($candidate, $field) && (new \ReflectionProperty($candidate, $field))->isPublic() ? $candidate->$field : null;
    }

    private function text(mixed $value): ?string
    {
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }
}
