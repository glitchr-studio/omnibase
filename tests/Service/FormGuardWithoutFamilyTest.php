<?php

namespace Tests\Base\Service;

use Base\Form\Extension\FormTypeGuardExtension;
use Base\Routing\AdvancedRouterInterface;
use Base\Service\FormGuard;
use Base\Service\SpamCheckerInterface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Validation;

/**
 * glitchr/omnishield is suggested, not required: on a site without the family,
 * a guarded form keeps its trap and its time and asks nothing else - no list,
 * no captcha -, whatever base.guard says (here reputation: [emails], the
 * configuration a site copies), and never answers 500.
 *
 * FormGuardTest runs with the family installed (require-dev), so it cannot
 * see a class of the family used where it is missing. Here each test runs in
 * a process of its own whose autoloader refuses the Omnishield\ namespace: the
 * family is not there, as on such a site. (A form sent with an address once
 * built omnishield's Identity to ask the lists - "Class
 * Omnishield\Model\Identity not found", on the first contact form sent.)
 *
 * The annotations for PHPUnit 9.6 (the harness's), the attributes for 10.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FormGuardWithoutFamilyTest extends TestCase
{
    private const SECRET = 'not-the-family-secret';

    private RequestStack $requests;

    /** @var list<callable> the loaders as they were, given back after each test */
    private array $loaders = [];

    protected function setUp(): void
    {
        // Only in a process of its own: where a class of the family is loaded already, it cannot be taken away,
        // and the loaders are not to be changed under the other tests.
        if (class_exists(\Omnishield\Registry::class, false) || class_exists(\Omnishield\Model\Identity::class, false)) {
            self::markTestSkipped('Not in a process of its own: glitchr/omnishield is loaded already.');
        }
        // The family removed from the autoload: every loader asked for one of its classes answers nothing.
        $this->loaders = spl_autoload_functions();
        foreach ($this->loaders as $loader) {
            spl_autoload_unregister($loader);
            spl_autoload_register(static function (string $class) use ($loader): void {
                if (!str_starts_with($class, 'Omnishield\\')) {
                    $loader($class);
                }
            });
        }
        $this->assertFalse(class_exists(\Omnishield\Model\Identity::class), 'the family is not there');
        $this->requests = new RequestStack();
    }

    protected function tearDown(): void
    {
        foreach (spl_autoload_functions() as $loader) {
            spl_autoload_unregister($loader);
        }
        foreach ($this->loaders as $loader) {
            spl_autoload_register($loader);
        }
    }

    private function form(): FormInterface
    {
        $router = $this->createMock(AdvancedRouterInterface::class);
        $router->method('isAdmin')->willReturn(false);
        $guard = new FormGuard(self::SECRET, ['reputation' => ['emails'], 'min_delay' => 3]);

        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypeExtension(new FormTypeGuardExtension($guard, $this->createMock(SpamCheckerInterface::class), $router, null, $this->requests))
            ->addTypeExtension(new CaptchaProtectionOption())
            ->getFormFactory()
            ->createNamedBuilder('contact', FormType::class, null, ['guard' => true, 'spam_protection' => false])
            ->add('name', TextType::class)
            ->add('email', EmailType::class)
            ->getForm();
    }

    /** @param array<string, mixed> $fields */
    private function send(array $fields = [], int $ago = 10): FormInterface
    {
        $form = $this->form();
        $data = $fields + ['name' => 'Anne', 'email' => 'anne@mailinator.com', FormGuard::TRAP_FIELD => '', FormGuard::STAMP_FIELD => (new FormGuard(self::SECRET))->stamp(time() - $ago)];
        $this->requests->push(Request::create('/contact', 'POST', ['contact' => $data], [], [], ['REMOTE_ADDR' => '203.0.113.7']));
        $form->submit($data);

        return $form;
    }

    /** @return list<string> the form's own errors, by cause */
    private function causes(FormInterface $form): array
    {
        $causes = [];
        foreach ($form->getErrors(true) as $error) {
            $causes[] = (string) ($error->getCause() ?? $error->getMessage());
        }

        return $causes;
    }

    public function testAFormSentAsAPersonIsAcceptedWithNoListAsked(): void
    {
        $guard = new FormGuard(self::SECRET, ['reputation' => ['emails']]);
        $this->assertFalse($guard->hasOmnishield());
        $this->assertSame([], $guard->reputationGateways());
        $this->assertNull($guard->challengeGateway());

        $form = $this->send();
        $this->assertFalse($form->has(FormGuard::CHALLENGE_FIELD), 'no captcha');
        $this->assertTrue($form->isValid(), implode(' | ', $this->causes($form)));
    }

    public function testTheTrapAndTheTimeStillRefuse(): void
    {
        $this->assertSame([FormGuard::TRAPPED], $this->causes($this->send([FormGuard::TRAP_FIELD => 'https://spam.example'])));
        $this->assertSame([FormGuard::TOO_FAST], $this->causes($this->send(ago: 0)));
    }
}

/** glitchr/ux-google's option, as its FormTypeCaptchaExtension declares it: off. */
final class CaptchaProtectionOption extends \Symfony\Component\Form\AbstractTypeExtension
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
