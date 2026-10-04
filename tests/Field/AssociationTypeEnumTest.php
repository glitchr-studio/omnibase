<?php

namespace Tests\Base\Field;

use Base\Entity\Thread\Comment;
use Base\Enum\CommentState;
use Base\Field\Type\AssociationType;
use Base\Field\Type\SelectType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The form of a related record, embedded by AssociationType, chooses a PHP
 * enum in a list: the column behind it being a string, the case went to a
 * text input and the page died ("CommentState could not be converted to
 * string").
 */
class AssociationTypeEnumTest extends KernelTestCase
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

    public function testAnEnumOfTheEmbeddedRecordIsASelect(): void
    {
        $fields = static::getContainer()->get(\Base\Database\Mapping\ClassMetadataManipulator::class)->getFields(Comment::class);
        $this->assertSame(SelectType::class, $fields['state']['form_type']);
        $this->assertSame(CommentState::class, $fields['state']['class']);
    }

    public function testTheEmbeddedFormRendersAndSubmits(): void
    {
        $comment = new Comment();
        $form = static::getContainer()->get('form.factory')
            ->createNamedBuilder('record', FormType::class, ['comment' => $comment], ['csrf_protection' => false, 'spam_protection' => false])
            ->add('comment', AssociationType::class, ['class' => Comment::class, 'autoload' => false, 'fields' => ['state' => [], 'content' => []]])
            ->getForm();

        $this->assertInstanceOf(SelectType::class, $form->get('comment')->get('state')->getConfig()->getType()->getInnerType());

        $html = static::getContainer()->get('twig')->createTemplate('{{ form_widget(form) }}')->render(['form' => $form->createView()]);
        $this->assertStringContainsString('record[comment][state][choice]', $html);
        $this->assertStringContainsString('Awaiting moderation', html_entity_decode($html));

        $form->submit(['comment' => ['state' => ['choice' => 'spam'], 'content' => 'Buy now']]);
        $this->assertTrue($form->isSynchronized(), (string) $form->getErrors(true));
        $this->assertSame(CommentState::SPAM, $comment->getState());
        $this->assertSame('Buy now', $comment->getContent());
    }
}
