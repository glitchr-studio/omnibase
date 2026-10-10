<?php

namespace Tests\Base\Service;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Base\Form\Extension\FormTypeGuardExtension;
use Base\Routing\AdvancedRouterInterface;
use Base\Service\FormGuard;
use Base\Service\SpamCheckerInterface;
use Omnishield\Altcha\AltchaGateway;
use Omnishield\Altcha\AltchaGatewayFactory;
use Omnishield\Bridge\Symfony\Form\ChallengeType;
use Omnishield\Bridge\Symfony\Validator\PassesChallenge;
use Omnishield\Bridge\Symfony\Validator\PassesChallengeValidator;
use Omnishield\Disposable\DisposableGatewayFactory;
use Omnishield\Exception\UnreachableException;
use Omnishield\GatewayFactoryInterface;
use Omnishield\GatewayInterface;
use Omnishield\Model\Capabilities;
use Omnishield\Model\Identity;
use Omnishield\Model\Reputation;
use Omnishield\Registry;
use Omnishield\Replay\InMemoryReplayStore;
use Omnishield\ReputationInterface;
use Omnishield\Testing\FixedGateway;
use Omnishield\Testing\FixedGatewayFactory;
use Omnishield\WidgetPrinter;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

/**
 * The option `guard` of a form, as a browser and a robot send it: the trap,
 * the time, the lists, the captcha - in a form factory of its own, on
 * glitchr/omnishield's real pieces (its registry, ALTCHA, the disposable
 * domains, the fixed gateway, the captcha's field and constraint).
 */
class FormGuardTest extends TestCase
{
    private const SECRET = 'the-harness-secret';

    private RequestStack $requests;
    private ?Registry $registry = null;
    private InMemoryReplayStore $replays;

    protected function setUp(): void
    {
        if (!class_exists(Registry::class) || !class_exists(AltchaGatewayFactory::class)) {
            self::markTestSkipped('glitchr/omnishield and omnishield/altcha are not installed.');
        }
        $this->requests = new RequestStack();
        $this->replays = new InMemoryReplayStore();
    }

    /** @param array<string, array{factory: string, options?: array<string, mixed>}> $gateways */
    private function registry(array $gateways): Registry
    {
        return $this->registry = new Registry([
            new FixedGatewayFactory(),
            new AltchaGatewayFactory($this->replays),
            new DisposableGatewayFactory(),
            new DownFactory(),
            new ThirdPartyFactory(),
        ], $gateways);
    }

    /** @param array<string, mixed> $config base.guard */
    private function forms(array $config = [], ?string $defaultChallenge = 'forms', bool $omnishield = true, ?CacheItemPoolInterface $cache = null): FormFactoryInterface
    {
        $registry = $omnishield ? ($this->registry ?? $this->registry(['forms' => ['factory' => 'fixed']])) : null;
        $guard = new FormGuard(self::SECRET, $config + ['min_delay' => 3], $registry, null, $defaultChallenge, cache: $cache);
        $router = $this->createMock(AdvancedRouterInterface::class);
        $router->method('isAdmin')->willReturn(false);

        $validators = new class($registry, $this->requests, $defaultChallenge) extends ConstraintValidatorFactory {
            public function __construct(private readonly ?Registry $registry, private readonly RequestStack $requests, private readonly ?string $gateway)
            {
                parent::__construct();
            }

            public function getInstance(Constraint $constraint): ConstraintValidatorInterface
            {
                return $constraint instanceof PassesChallenge ? new PassesChallengeValidator($this->registry, $this->requests, $this->gateway) : parent::getInstance($constraint);
            }
        };

        $builder = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidatorBuilder()->setConstraintValidatorFactory($validators)->getValidator()))
            ->addTypeExtension(new FormTypeGuardExtension($guard, $this->createMock(SpamCheckerInterface::class), $router, null, $this->requests))
            ->addTypeExtension(new UxGoogleOption());
        if ($registry) {
            $builder->addType(new ChallengeType($registry, new WidgetPrinter(), $this->requests, $defaultChallenge));
        }

        return $builder->getFormFactory();
    }

    /** @param array<string, mixed> $guard */
    private function form(FormFactoryInterface $forms, array|bool $guard = true): FormInterface
    {
        return $forms->createNamedBuilder('contact', FormType::class, null, ['guard' => $guard, 'spam_protection' => false])
            ->add('name', TextType::class)
            ->add('email', EmailType::class)
            ->getForm();
    }

    /**
     * The form sent as a browser sends it: the fields typed, the stamp of when it was shown ($ago seconds
     * before), the trap left empty, and what else the page posted ($post: the captcha's token, outside the form).
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $post
     */
    private function send(FormInterface $form, array $fields = [], int $ago = 10, array $post = [], string $ip = '203.0.113.7'): FormInterface
    {
        $data = $fields + ['name' => 'Anne', 'email' => 'anne@example.org', FormGuard::TRAP_FIELD => '', FormGuard::STAMP_FIELD => (new FormGuard(self::SECRET))->stamp(time() - $ago)];
        $this->requests->push(Request::create('/contact', 'POST', $post + [$form->getName() => $data], [], [], ['REMOTE_ADDR' => $ip]));
        $form->submit($data);

        return $form;
    }

    /** @return array<string, list<string>> what is wrong, by field ('' for the form), each as its reason or its message */
    private function errors(FormInterface $form): array
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            /** @var FormError $error */
            $field = $error->getOrigin() === $form ? '' : $error->getOrigin()->getName();
            $errors[$field][] = \is_string($error->getCause()) ? $error->getCause() : $error->getMessage();
        }

        return $errors;
    }

    private function token(): array
    {
        return [FixedGateway::FIELD => FixedGateway::TOKEN];
    }

    public function testAFormSentAsAPersonSendsItIsAccepted(): void
    {
        $form = $this->send($this->form($this->forms()), post: $this->token());

        $this->assertTrue($form->isValid(), json_encode($this->errors($form)));
        $this->assertTrue($form->has(FormGuard::TRAP_FIELD) && $form->has(FormGuard::STAMP_FIELD) && $form->has(FormGuard::CHALLENGE_FIELD));
    }

    public function testTheTrapFilledIsRefused(): void
    {
        $form = $this->send($this->form($this->forms()), [FormGuard::TRAP_FIELD => 'https://spam.example'], post: $this->token());

        $this->assertSame(['' => [FormGuard::TRAPPED]], $this->errors($form));
    }

    public function testAFormSentTooFastIsRefused(): void
    {
        $this->assertSame(['' => [FormGuard::TOO_FAST]], $this->errors($this->send($this->form($this->forms()), ago: 1, post: $this->token())));

        // A stamp that is not the site's - forged, cut - is refused as well.
        $forged = $this->send($this->form($this->forms()), [FormGuard::STAMP_FIELD => (time() - 60).'.forged-signature-of-sixteen'], post: $this->token());
        $this->assertSame(['' => [FormGuard::STALE]], $this->errors($forged));
        $this->assertNull((new FormGuard(self::SECRET))->elapsed(null));
        $this->assertNull((new FormGuard('another-secret'))->elapsed((new FormGuard(self::SECRET))->stamp()));
    }

    public function testTheCaptchaMissingOrFalseIsAnErrorOnItsField(): void
    {
        $missing = $this->send($this->form($this->forms()));
        $this->assertSame([FormGuard::CHALLENGE_FIELD => [FormGuard::CHALLENGE_MISSING]], $this->errors($missing));
        $this->assertSame('Please confirm that you are not a robot.', $missing->get(FormGuard::CHALLENGE_FIELD)->getErrors()[0]->getMessage(), 'omnishield\'s sentence, translated in "validators"');

        $this->registry(['forms' => ['factory' => 'fixed', 'options' => ['pass' => false]]]);
        $false = $this->send($this->form($this->forms()), post: $this->token());
        $this->assertSame([FormGuard::CHALLENGE_FIELD => [FormGuard::CHALLENGE_FAILED]], $this->errors($false));
    }

    public function testAnAltchaSolutionPassesOnceAndIsRefusedWhenPostedAgain(): void
    {
        $this->registry(['forms' => ['factory' => 'altcha', 'options' => ['hmac_key' => 'a-long-random-secret-of-the-site', 'cost' => 10]]]);
        $gateway = $this->registry->challenge('forms');
        $this->assertInstanceOf(AltchaGateway::class, $gateway);

        // What the widget does in the browser: the challenge of the page, solved, posted under its field.
        $challenge = Challenge::fromArray($gateway->issue('contact'));
        $solution = (new Altcha())->solveChallenge(new SolveChallengeOptions(algorithm: AltchaGatewayFactory::algorithm('PBKDF2/SHA-256'), challenge: $challenge));
        $post = ['altcha' => (new Payload($challenge, $solution))->toBase64()];

        $first = $this->send($this->form($this->forms()), post: $post);
        $this->assertTrue($first->isValid(), json_encode($this->errors($first)));

        $again = $this->send($this->form($this->forms()), post: $post);
        $this->assertSame([FormGuard::CHALLENGE_FIELD => [FormGuard::CHALLENGE_FAILED]], $this->errors($again), 'a token is spent once');
    }

    public function testTheCaptchaIsCheckedWhateverTheValidationGroupsAndAfterTheTrap(): void
    {
        // A form validated in a group of its own (the sign-up: "new"): the captcha is asked all the same.
        $form = $this->forms()->createNamedBuilder('signup', FormType::class, null, ['guard' => true, 'spam_protection' => false, 'validation_groups' => ['new']])
            ->add('name', TextType::class)->add('email', EmailType::class)->getForm();
        $this->assertSame([FormGuard::CHALLENGE_FIELD => [FormGuard::CHALLENGE_MISSING]], $this->errors($this->send($form)));

        // Caught by the trap: the form's only error - the captcha is not asked.
        $trapped = $this->send($this->form($this->forms()), [FormGuard::TRAP_FIELD => 'x']);
        $this->assertSame(['' => [FormGuard::TRAPPED]], $this->errors($trapped));
    }

    public function testADisposableAddressIsRefusedOnItsField(): void
    {
        $this->registry(['forms' => ['factory' => 'fixed'], 'emails' => ['factory' => 'disposable']]);
        $forms = $this->forms(['reputation' => ['emails']]);

        $form = $this->send($this->form($forms), ['email' => 'someone@mailinator.com'], post: $this->token());
        $this->assertSame(['email' => [FormGuard::DISPOSABLE]], array_intersect_key($this->errors($form), ['email' => true]));
        $this->assertSame([], array_diff_key($this->errors($form), ['email' => true, FormGuard::CHALLENGE_FIELD => true]));

        $this->assertTrue($this->send($this->form($forms), post: $this->token())->isValid(), 'an address of a real domain goes through');
        $this->assertTrue($this->send($this->form($forms, ['reputation' => false]), ['email' => 'someone@mailinator.com'], post: $this->token())->isValid(), 'a form that does not ask the lists');
    }

    public function testAListThatDoesNotAnswerIsTheConductConfigured(): void
    {
        $this->registry(['forms' => ['factory' => 'fixed'], 'down' => ['factory' => 'down']]);

        $accepted = $this->send($this->form($this->forms(['reputation' => ['down'], 'unreachable' => 'accept'])), post: $this->token());
        $this->assertTrue($accepted->isValid(), 'accept: the form goes on');

        $rejected = $this->send($this->form($this->forms(['reputation' => ['down'], 'unreachable' => 'reject'])), post: $this->token());
        $this->assertSame(['' => [FormGuard::UNREACHABLE]], $this->errors($rejected));
    }

    public function testWhereUxGoogleGuardsTheFormNoSecondCaptchaIsAdded(): void
    {
        $forms = $this->forms();
        $form = $forms->createNamedBuilder('contact', FormType::class, null, ['guard' => true, 'spam_protection' => false, 'captcha_protection' => true])->getForm();

        $this->assertFalse($form->has(FormGuard::CHALLENGE_FIELD), 'ux-google\'s captcha is the one');
        $this->assertTrue($form->has(FormGuard::TRAP_FIELD) && $form->has(FormGuard::STAMP_FIELD), 'the trap and the time stay');
    }

    public function testACaptchaThatReachesAThirdPartyWaitsForConsentBesideItsFallback(): void
    {
        $this->registry(['third' => ['factory' => 'third'], 'local' => ['factory' => 'fixed']]);
        $forms = $this->forms(['challenge' => 'third', 'fallback' => 'local'], 'third');

        // Printed inert, in a <template> omnibase/consent opens (feature CAPTCHA); the fallback beside it.
        $view = $this->form($forms)->createView();
        $this->assertStringStartsWith('<template data-guard-consent=', $view[FormGuard::CHALLENGE_FIELD]->vars['omnishield_html']);
        $this->assertStringContainsString("Consent.use('CAPTCHA'", $view[FormGuard::CHALLENGE_FIELD]->vars['omnishield_html']);
        $this->assertStringContainsString('data-guard-fallback=', $view[FormGuard::FALLBACK_FIELD]->vars['omnishield_html']);

        // The visitor who refused solved the fallback: it holds. Nothing solved: refused.
        $this->assertTrue($this->send($this->form($forms), post: $this->token())->isValid(), 'the fallback\'s token');
        $this->assertTrue($this->send($this->form($forms), post: ['third-token' => 'from-the-third-party'])->isValid(), 'the third party\'s, once agreed');
        $this->assertSame([FormGuard::CHALLENGE_FIELD => [FormGuard::CHALLENGE_MISSING]], $this->errors($this->send($this->form($forms))));

        // A captcha that reaches nobody is printed as it is.
        $plain = $this->form($this->forms(['challenge' => 'local'], 'local'))->createView();
        $this->assertStringNotContainsString('<template', $plain[FormGuard::CHALLENGE_FIELD]->vars['omnishield_html']);
    }

    public function testWithoutOmnishieldTheTrapAndTheTimeAlone(): void
    {
        $forms = $this->forms(['reputation' => ['emails']], null, omnishield: false);

        $form = $this->send($this->form($forms), ['email' => 'someone@mailinator.com']);
        $this->assertTrue($form->isValid(), json_encode($this->errors($form)));
        $this->assertFalse($form->has(FormGuard::CHALLENGE_FIELD));

        $this->assertSame(['' => [FormGuard::TRAPPED]], $this->errors($this->send($this->form($forms), [FormGuard::TRAP_FIELD => 'x'])));
        $this->assertSame(['' => [FormGuard::TOO_FAST]], $this->errors($this->send($this->form($forms), ago: 0)));
    }

    public function testAFormWithoutTheOptionIsLeftAlone(): void
    {
        $form = $this->form($this->forms(), false);

        $this->assertFalse($form->has(FormGuard::TRAP_FIELD) || $form->has(FormGuard::STAMP_FIELD) || $form->has(FormGuard::CHALLENGE_FIELD));
        $form->submit(['name' => 'Anne', 'email' => 'anne@example.org']);
        $this->assertTrue($form->isValid());
    }

    /** The page as the visitor at $ip reads it: whether the form prints its captcha. */
    private function shows(FormInterface $form, string $ip = '203.0.113.7'): bool
    {
        $this->requests->push(Request::create('/contact', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]));
        $captcha = $form->createView()[FormGuard::CHALLENGE_FIELD];
        $this->requests->pop();

        return !$captcha->isRendered() && '' !== $captcha->vars['omnishield_html'];
    }

    public function testBelowCaptchaAfterTheCaptchaIsNeitherShownNorAsked(): void
    {
        $forms = $this->forms(['captcha_after' => 3], cache: new ArrayAdapter());

        $this->assertFalse($this->shows($this->form($forms)), 'no widget on the first visit');
        $form = $this->send($this->form($forms));
        $this->assertTrue($form->isValid(), 'sent without a token: '.json_encode($this->errors($form)));

        // The trap and the time stay, below the threshold too.
        $this->assertSame(['' => [FormGuard::TRAPPED]], $this->errors($this->send($this->form($forms), [FormGuard::TRAP_FIELD => 'x'])));
        $this->assertSame(['' => [FormGuard::TOO_FAST]], $this->errors($this->send($this->form($forms), ago: 0)));
        $this->assertFalse($this->shows($this->form($forms)), 'two refusals: still below three');
    }

    public function testFromCaptchaAfterRefusedTriesTheCaptchaIsShownAndAsked(): void
    {
        $forms = $this->forms(['captcha_after' => 3], cache: new ArrayAdapter());
        for ($i = 0; $i < 3; ++$i) {
            $this->assertSame(['' => [FormGuard::TOO_FAST]], $this->errors($this->send($this->form($forms), ago: 0)));
        }

        // The form refused the third time, printed again as it is: the widget is there now.
        $refused = $this->form($forms);
        $this->send($refused, ago: 0);
        $this->requests->push(Request::create('/contact', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']));
        $view = $refused->createView()[FormGuard::CHALLENGE_FIELD];
        $this->assertFalse($view->isRendered());
        $this->assertNotSame('', $view->vars['omnishield_html']);
        $this->assertTrue($this->shows($this->form($forms)));

        $this->assertSame([FormGuard::CHALLENGE_FIELD => [FormGuard::CHALLENGE_MISSING]], $this->errors($this->send($this->form($forms))), 'the token is asked');
        $this->assertTrue($this->send($this->form($forms), post: $this->token())->isValid(), 'with it, sent');

        // A form sent forgets the tries.
        $this->assertFalse($this->shows($this->form($forms)));
        $this->assertTrue($this->send($this->form($forms))->isValid());
    }

    public function testTriesAreCountedPerFormAndVisitorAndAnInvalidFormIsOne(): void
    {
        $forms = $this->forms(['captcha_after' => 1], cache: new ArrayAdapter());
        $required = fn (string $name) => $forms->createNamedBuilder($name, FormType::class, null, ['guard' => true, 'spam_protection' => false])
            ->add('name', TextType::class, ['constraints' => [new NotBlank()]])->add('email', EmailType::class)->getForm();

        // An invalid form - no guard's refusal - is a try.
        $this->assertArrayHasKey('name', $this->errors($this->send($required('contact'), ['name' => ''])));
        $this->assertTrue($this->shows($required('contact')));
        $this->assertFalse($this->shows($required('newsletter')), 'another form');
        $this->assertFalse($this->shows($required('contact'), '198.51.100.4'), 'another visitor');
    }

    public function testAFormSetsItsOwnThresholdAndZeroAlwaysShows(): void
    {
        $cache = new ArrayAdapter();
        $forms = $this->forms(['captcha_after' => 3], cache: $cache);

        // `captcha_after: 1`, the newsletter's: one refusal is enough.
        $this->send($this->form($forms, ['captcha_after' => 1]), ago: 0);
        $this->assertTrue($this->shows($this->form($forms, ['captcha_after' => 1])));
        $this->assertFalse($this->shows($this->form($forms)), 'the site\'s three: not yet');

        // 0: always, as before the threshold - so is base.guard.captcha_after: 0, and a guard without a cache.
        $this->assertTrue($this->shows($this->form($this->forms(cache: new ArrayAdapter()), ['captcha_after' => 0])));
        $this->assertTrue($this->shows($this->form($this->forms(['captcha_after' => 0], cache: new ArrayAdapter()))));
        $this->assertTrue($this->shows($this->form($this->forms(['captcha_after' => 3]))));
        $this->assertSame(3, (new FormGuard(self::SECRET))->captchaAfter(), 'three by default');
    }

    public function testTheListsAreAskedAboutTheSender(): void
    {
        $this->registry(['emails' => ['factory' => 'disposable'], 'listed' => ['factory' => 'fixed', 'options' => ['pass' => false]]]);
        $guard = new FormGuard(self::SECRET, ['reputation' => ['emails', 'listed', 'not-configured']], $this->registry);

        $this->assertSame(['emails', 'listed'], $guard->reputationGateways());
        $this->assertSame(FormGuard::DISPOSABLE, $guard->reputation(new Identity(null, 'x@mailinator.com')));
        $this->assertSame(FormGuard::KNOWN, $guard->reputation(new Identity('203.0.113.9', 'x@example.org')));
        $this->assertNull($guard->reputation(new Identity()));
    }
}

/** glitchr/ux-google's option, as its FormTypeCaptchaExtension declares it (google.recaptcha.enable). */
final class UxGoogleOption extends \Symfony\Component\Form\AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['captcha_protection' => false]);
    }
}

/** A captcha of a third party: its widget reaches https://challenges.example; any token posted passes. */
final class ThirdPartyFactory implements GatewayFactoryInterface
{
    public function getName(): string
    {
        return 'third';
    }

    public function create(array $options = []): GatewayInterface
    {
        return new class implements \Omnishield\ChallengeInterface {
            public function getName(): string
            {
                return 'third';
            }

            public function getTitle(): string
            {
                return 'Third party';
            }

            public function capabilities(): Capabilities
            {
                return new Capabilities(challenge: true, thirdParty: true);
            }

            public function widget(?string $action = null): \Omnishield\Model\Widget
            {
                return new \Omnishield\Model\Widget('third-token', tag: 'div', attributes: ['class' => 'third'], action: $action, thirdParty: true, origins: ['https://challenges.example']);
            }

            public function verify(\Omnishield\Model\Attempt $attempt): \Omnishield\Model\Verdict
            {
                return $attempt->isEmpty() ? \Omnishield\Model\Verdict::fail(\Omnishield\Model\Verdict::MISSING) : new \Omnishield\Model\Verdict(true, 1.0, $attempt->action, null, new \DateTimeImmutable());
            }
        };
    }
}

/** A provider that never answers: a list and a classifier. */
final class DownFactory implements GatewayFactoryInterface
{
    public function getName(): string
    {
        return 'down';
    }

    public function create(array $options = []): GatewayInterface
    {
        return new class implements ReputationInterface, \Omnishield\ClassifierInterface {
            public function getName(): string
            {
                return 'down';
            }

            public function getTitle(): string
            {
                return 'Down';
            }

            public function capabilities(): Capabilities
            {
                return new Capabilities(classifier: true, reputation: true);
            }

            public function lookup(Identity $identity): Reputation
            {
                throw new UnreachableException('down', 'No answer.');
            }

            public function classify(\Omnishield\Model\Submission $submission): \Omnishield\Model\Classification
            {
                throw new UnreachableException('down', 'No answer.');
            }

            public function report(\Omnishield\Model\Submission $submission, bool $spam): void
            {
                throw new UnreachableException('down', 'No answer.');
            }
        };
    }
}
