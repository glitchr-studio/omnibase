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
use Omniguard\Altcha\AltchaGateway;
use Omniguard\Altcha\AltchaGatewayFactory;
use Omniguard\Bridge\Symfony\Form\ChallengeType;
use Omniguard\Bridge\Symfony\Validator\PassesChallenge;
use Omniguard\Bridge\Symfony\Validator\PassesChallengeValidator;
use Omniguard\Disposable\DisposableGatewayFactory;
use Omniguard\Exception\UnreachableException;
use Omniguard\GatewayFactoryInterface;
use Omniguard\GatewayInterface;
use Omniguard\Model\Capabilities;
use Omniguard\Model\Identity;
use Omniguard\Model\Reputation;
use Omniguard\Registry;
use Omniguard\Replay\InMemoryReplayStore;
use Omniguard\ReputationInterface;
use Omniguard\Testing\FixedGateway;
use Omniguard\Testing\FixedGatewayFactory;
use Omniguard\WidgetPrinter;
use PHPUnit\Framework\TestCase;
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
use Symfony\Component\Validator\Validation;

/**
 * The option `guard` of a form, as a browser and a robot send it: the trap,
 * the time, the lists, the captcha - in a form factory of its own, on
 * glitchr/omniguard's real pieces (its registry, ALTCHA, the disposable
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
            self::markTestSkipped('glitchr/omniguard and omniguard/altcha are not installed.');
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
        ], $gateways);
    }

    /** @param array<string, mixed> $config base.guard */
    private function forms(array $config = [], ?string $defaultChallenge = 'forms', bool $omniguard = true): FormFactoryInterface
    {
        $registry = $omniguard ? ($this->registry ?? $this->registry(['forms' => ['factory' => 'fixed']])) : null;
        $guard = new FormGuard(self::SECRET, $config + ['min_delay' => 3], $registry, null, $defaultChallenge);
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
    private function send(FormInterface $form, array $fields = [], int $ago = 10, array $post = []): FormInterface
    {
        $data = $fields + ['name' => 'Anne', 'email' => 'anne@example.org', FormGuard::TRAP_FIELD => '', FormGuard::STAMP_FIELD => (new FormGuard(self::SECRET))->stamp(time() - $ago)];
        $this->requests->push(Request::create('/contact', 'POST', $post + ['contact' => $data]));
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
        $this->assertSame([FormGuard::CHALLENGE_FIELD => ['Please confirm that you are not a robot.']], $this->errors($missing));
        $this->assertSame(PassesChallenge::MISSING_ERROR, $missing->get(FormGuard::CHALLENGE_FIELD)->getErrors()[0]->getCause()->getCode());

        $this->registry(['forms' => ['factory' => 'fixed', 'options' => ['pass' => false]]]);
        $false = $this->send($this->form($this->forms()), post: $this->token());
        $this->assertSame(PassesChallenge::FAILED_ERROR, $false->get(FormGuard::CHALLENGE_FIELD)->getErrors()[0]->getCause()->getCode());
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
        $this->assertSame(PassesChallenge::FAILED_ERROR, $again->get(FormGuard::CHALLENGE_FIELD)->getErrors()[0]->getCause()->getCode(), 'a token is spent once');
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

    public function testWithoutOmniguardTheTrapAndTheTimeAlone(): void
    {
        $forms = $this->forms(['reputation' => ['emails']], null, omniguard: false);

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

/** A provider that never answers: a list and a classifier. */
final class DownFactory implements GatewayFactoryInterface
{
    public function getName(): string
    {
        return 'down';
    }

    public function create(array $options = []): GatewayInterface
    {
        return new class implements ReputationInterface, \Omniguard\ClassifierInterface {
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

            public function classify(\Omniguard\Model\Submission $submission): \Omniguard\Model\Classification
            {
                throw new UnreachableException('down', 'No answer.');
            }

            public function report(\Omniguard\Model\Submission $submission, bool $spam): void
            {
                throw new UnreachableException('down', 'No answer.');
            }
        };
    }
}
