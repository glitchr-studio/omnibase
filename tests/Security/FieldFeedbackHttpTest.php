<?php

namespace Tests\Base\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Tests\Base\Http\HttpTestTrait;

/**
 * What a field says under itself on omnibase's own pages (forgotten
 * password, sign-up): its help and its errors when it has some - and no
 * block at all when it has none. The block was printed empty under the
 * e-mail field, and a page that frames its field groups showed an empty
 * frame there.
 */
class FieldFeedbackHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    protected function setUp(): void
    {
        $this->bootHost();
    }

    private function page(string $path): string
    {
        $response = static::$kernel->handle(Request::create($path));
        $this->assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getContent();
    }

    public function testTheForgottenPasswordPagePrintsNoEmptyBlockUnderItsField(): void
    {
        $html = $this->page('/reset-password');

        $this->assertStringContainsString('name="security_reset_password[email]"', $html, 'the field is there');
        $this->assertStringNotContainsString('class="invalid-feedback"', $html, 'nothing to say: no block');
        $this->assertDoesNotMatchRegularExpression('~<div class="input-group[^"]*">\s*<small>\s*</small>~', $html);
    }

    public function testTheSignUpPagePrintsNoEmptyBlockUnderItsFields(): void
    {
        $html = $this->page('/register');

        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringNotContainsString('class="invalid-feedback"', $html);
        $this->assertDoesNotMatchRegularExpression('~<div class="input-group[^"]*">\s*<small>\s*</small>~', $html);
    }

    public function testAFieldThatHasSomethingToSaySaysIt(): void
    {
        $twig = static::getContainer()->get('twig');
        $forms = static::getContainer()->get('form.factory');
        $render = static fn ($form): string => trim($twig->render('@Base/security/_field_feedback.html.twig', ['field' => $form->createView()['email']]));

        // The forms are built here, not by a controller: the form extensions ask for the current request.
        $request = Request::create('/reset-password');
        $request->setSession(static::getContainer()->get('session.factory')->createSession());
        static::getContainer()->get('request_stack')->push($request);
        $builder = static fn () => $forms->createBuilder(FormType::class, null, ['csrf_protection' => false]);

        $silent = $builder()->add('email', TextType::class)->getForm();
        $this->assertSame('', $render($silent), 'no help, no error: nothing printed');

        $helped = $builder()->add('email', TextType::class, ['help' => 'The address you signed up with.'])->getForm();
        $this->assertStringContainsString('The address you signed up with.', $render($helped));
        $this->assertStringNotContainsString('invalid-feedback', $render($helped), 'a help alone: no error block');

        $wrong = $builder()->add('email', TextType::class)->getForm();
        $wrong->get('email')->addError(new FormError('This address is not one.'));
        $this->assertMatchesRegularExpression('~<div class="invalid-feedback">.*This address is not one\.~s', $render($wrong));
        $this->assertStringNotContainsString('<small>', $render($wrong), 'an error alone: no help block');
    }
}
