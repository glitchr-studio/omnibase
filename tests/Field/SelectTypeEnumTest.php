<?php

namespace Tests\Base\Field;

use Base\Entity\Thread\Comment;
use Base\Enum\CommentState;
use Base\Field\Type\SelectType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Tests\Base\Fixtures\Form\Hand;
use Tests\Base\Fixtures\Form\Suit;

/**
 * A SelectType bound to a PHP enum finds its choices by itself: the cases of
 * a Doctrine `enumType:` column, of a property typed with an enum, or of the
 * `class` option - where it used to stop on "No choices, or autocomplete
 * option, could be guessed...". The case is what the record receives.
 */
class SelectTypeEnumTest extends KernelTestCase
{
    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires a host application (the omnibase harness).');
        }

        self::bootKernel();
        $request = Request::create('/');
        $request->setLocale('en');
        $request->setSession(new Session(new MockArraySessionStorage()));
        static::getContainer()->get('request_stack')->push($request);
        static::getContainer()->get('translator')->setLocale('en');
    }

    private function form(object $data, array $fields): FormInterface
    {
        $builder = static::getContainer()->get('form.factory')->createNamedBuilder('record', FormType::class, $data, [
            'data_class' => $data::class,
            'csrf_protection' => false,
            'spam_protection' => false,     // the spam check sets a comment's state itself
        ]);
        foreach ($fields as $name => $options) {
            $builder->add($name, SelectType::class, $options);
        }

        return $builder->getForm();
    }

    /** @return array<string, string> id => text, as the select2 widget receives them */
    private function choices(FormInterface $form, string $field): array
    {
        $select2 = json_decode($form->createView()[$field]->vars['select2'], true);

        return array_column($select2['data'], 'text', 'id');
    }

    public function testTheCasesOfAnEnumTypeColumnAreTheChoices(): void
    {
        $form = $this->form(new Comment(), ['state' => []]);

        $this->assertSame([
            'pending' => 'Awaiting moderation',
            'approved' => 'Published',
            'spam' => 'Spam',
            'trash' => 'Removed',
        ], $this->choices($form, 'state'), 'the cases, labelled from the "enums" domain (comment_state.<value>)');

        $view = $form->createView()['state'];
        $this->assertSame(['pending'], $view->vars['data'], 'the record\'s case is the one selected');
        $this->assertStringNotContainsString('"ajax"', $view->vars['select2'], 'a list, not an autocompletion');

        $html = static::getContainer()->get('twig')->createTemplate('{{ form_row(field) }}')->render(['field' => $view]);
        $this->assertStringContainsString('<select', $html);
    }

    public function testTheLabelsFollowTheLocale(): void
    {
        static::getContainer()->get('translator')->setLocale('fr');

        $this->assertSame('Publié', $this->choices($this->form(new Comment(), ['state' => []]), 'state')['approved']);
    }

    public function testTheRecordReceivesTheCase(): void
    {
        $comment = new Comment();
        $form = $this->form($comment, ['state' => []]);
        $form->submit(['state' => ['choice' => 'trash']]);

        $this->assertTrue($form->get('state')->isSynchronized() && $form->get('state')->isValid(), (string) $form->get('state')->getErrors(true));
        $this->assertSame(CommentState::TRASH, $comment->getState());
    }

    public function testAValueThatNamesNoCaseIsRefused(): void
    {
        $comment = new Comment();
        $form = $this->form($comment, ['state' => []]);
        $form->submit(['state' => ['choice' => 'published']]);

        $this->assertFalse($form->get('state')->isValid());
        $this->assertSame(CommentState::PENDING, $comment->getState());
    }

    public function testAPropertyTypedWithAnEnumOnAPlainModel(): void
    {
        $hand = new Hand();
        $form = $this->form($hand, ['suit' => ['required' => false], 'state' => []]);

        $this->assertSame(
            ['HEARTS' => 'Hearts', 'SPADES' => 'Spades', 'NO_TRUMP' => 'No trump'],
            $this->choices($form, 'suit'),
            'an enum that is not backed is held by its names, and labelled by them when no translation exists'
        );

        $form->submit(['suit' => ['choice' => 'NO_TRUMP'], 'state' => ['choice' => 'spam']]);

        $this->assertTrue($form->isSynchronized() && $form->isValid(), (string) $form->getErrors(true));
        $this->assertSame(Suit::NO_TRUMP, $hand->suit);
        $this->assertSame(CommentState::SPAM, $hand->state);
    }

    public function testSeveralCasesWithTheClassOption(): void
    {
        $hand = new Hand();
        $hand->states = [CommentState::SPAM];
        $form = $this->form($hand, ['states' => ['class' => CommentState::class, 'multiple' => true]]);

        $this->assertSame(['spam'], $form->createView()['states']->vars['data']);

        $form->submit(['states' => ['choice' => ['approved', 'trash']]]);

        $this->assertTrue($form->isSynchronized() && $form->isValid(), (string) $form->getErrors(true));
        $this->assertSame([CommentState::APPROVED, CommentState::TRASH], $hand->states);
    }

    public function testAFieldThatBringsItsChoicesKeepsThem(): void
    {
        // What the bundles wrote before enums were guessed: labels and string
        // values, stored as they are - on a property that is not an enum here,
        // and the same on one that is (the guess steps aside).
        $hand = new Hand();
        $form = $this->form($hand, ['legacy' => ['choices' => ['Soloist' => 'soloist', 'Guest' => 'guest', '@enums.comment_state.spam' => 'spam']]]);

        $this->assertSame(
            ['soloist' => 'Soloist', 'guest' => 'Guest', 'spam' => 'Spam'],
            $this->choices($form, 'legacy'),
            'shown by their labels - a label that is a translation key, translated'
        );

        $form->submit(['legacy' => ['choice' => 'guest']]);
        $this->assertTrue($form->isSynchronized(), (string) $form->getErrors(true));
        $this->assertSame('guest', $hand->legacy);

        $form = $this->form(new Comment(), ['state' => ['choices' => ['Online' => 'approved'], 'mapped' => false]]);
        $this->assertSame(['approved' => 'Online'], $this->choices($form, 'state'));
        $form->submit(['state' => ['choice' => 'approved']]);
        $this->assertSame('approved', $form->get('state')->getData());
    }
}
