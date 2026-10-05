<?php

namespace Tests\Base\Field;

use Base\Entity\Thread\Comment;
use Base\Enum\CommentState;
use Base\Field\Type\CurrencyType;
use Base\Field\Type\SelectType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * A SelectType on a page whose script did not run: the <select> the server
 * prints holds its options and the record's value selected, and what a
 * browser then sends is what the record receives. It was drawn empty and
 * filled by select2 alone - nothing to choose, a product's availability
 * arrived null - and the lists that did print their options (the
 * currencies) selected none: the browser sent the first one.
 *
 * And a required select sent empty is an error of the form: the browser's
 * `required` attribute was the only check.
 */
class SelectTypeWithoutScriptTest extends KernelTestCase
{
    private const STOCK = ['In stock' => 'in_stock', 'On order' => 'on_order', 'Sold out' => 'sold_out'];

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

    /** @param array<string, array{0: class-string, 1: array<string, mixed>}> $fields name => [type, options] */
    private function form(array|object $data, array $fields): FormInterface
    {
        $options = [
            'data_class' => \is_object($data) ? $data::class : null,
            'csrf_protection' => false,
            'spam_protection' => false,
        ];
        // glitchr/ux-google asks a form for a captcha once it has been sent wrong a few times: these tests do just that.
        if (class_exists(\Google\Form\Extension\FormTypeCaptchaExtension::class)) {
            $options['captcha_protection'] = false;
        }

        $builder = static::getContainer()->get('form.factory')->createNamedBuilder('record', FormType::class, $data, $options);
        foreach ($fields as $name => [$type, $options]) {
            $builder->add($name, $type, $options);
        }

        return $builder->getForm();
    }

    /** The field's <select>, as the form theme prints it. */
    private function select(FormInterface $form, string $field): \DOMElement
    {
        $html = static::getContainer()->get('twig')->createTemplate('{{ form_widget(form) }}')->render(['form' => $form->createView()[$field]]);

        $this->assertLessThanOrEqual(1, substr_count($html, ' required='), 'the attribute is written once, or not at all');

        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>');
        $selects = $document->getElementsByTagName('select');
        $this->assertSame(1, $selects->length);

        return $selects->item(0);
    }

    /** @return array<string, string> value => label of every option, in order */
    private function options(\DOMElement $select): array
    {
        $options = [];
        foreach ($select->getElementsByTagName('option') as $option) {
            $options[$option->getAttribute('value')] = trim($option->textContent);
        }

        return $options;
    }

    /** @return string[] the values of the options printed selected */
    private function selected(\DOMElement $select): array
    {
        $selected = [];
        foreach ($select->getElementsByTagName('option') as $option) {
            if ($option->hasAttribute('selected')) {
                $selected[] = $option->getAttribute('value');
            }
        }

        return $selected;
    }

    /**
     * What a browser without script sends for the select as printed: the
     * selected options, else - for a select of one value - the first one.
     */
    private function browserValue(\DOMElement $select): array|string|null
    {
        $selected = $this->selected($select);
        if ($select->hasAttribute('multiple')) {
            return $selected;
        }

        return $selected[0] ?? (array_key_first($this->options($select)) ?? null);
    }

    public function testAStaticListPrintsItsOptionsAndTheRecordsValueSelected(): void
    {
        $form = $this->form(['availability' => 'on_order'], ['availability' => [SelectType::class, ['choices' => self::STOCK]]]);
        $select = $this->select($form, 'availability');

        $this->assertSame(['' => 'Choose your selection..', 'in_stock' => 'In stock', 'on_order' => 'On order', 'sold_out' => 'Sold out'], $this->options($select));
        $this->assertSame(['on_order'], $this->selected($select));
        $this->assertSame('record[availability][choice]', $select->getAttribute('name'));
    }

    public function testSentBackAsPrintedTheRecordKeepsItsValue(): void
    {
        $fields = ['availability' => [SelectType::class, ['choices' => self::STOCK]]];
        $select = $this->select($this->form(['availability' => 'on_order'], $fields), 'availability');

        $form = $this->form(['availability' => 'on_order'], $fields);
        $form->submit(['availability' => ['choice' => $this->browserValue($select)]]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame('on_order', $form->getData()['availability'], 'it arrived null: there was no option to send');
    }

    public function testAnotherOptionChosenIsWhatTheRecordReceives(): void
    {
        $form = $this->form(['availability' => 'on_order'], ['availability' => [SelectType::class, ['choices' => self::STOCK]]]);
        $form->submit(['availability' => ['choice' => 'sold_out']]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame('sold_out', $form->getData()['availability']);
    }

    public function testARecordWithoutValueStartsOnThePlaceholderNotOnTheFirstChoice(): void
    {
        $select = $this->select($this->form(['availability' => null], ['availability' => [SelectType::class, ['choices' => self::STOCK]]]), 'availability');

        $this->assertSame([], $this->selected($select));
        $this->assertSame('', $this->browserValue($select), 'the browser sends the empty option, not "in_stock"');
    }

    public function testTheCasesOfAnEnumAreOptions(): void
    {
        $comment = (new Comment())->setState(CommentState::SPAM);
        $select = $this->select($this->form($comment, ['state' => [SelectType::class, []]]), 'state');

        $this->assertSame(['' => 'Choose your selection..', 'pending' => 'Awaiting moderation', 'approved' => 'Published', 'spam' => 'Spam', 'trash' => 'Removed'], $this->options($select));
        $this->assertSame(['spam'], $this->selected($select));

        $form = $this->form($comment, ['state' => [SelectType::class, []]]);
        $form->submit(['state' => ['choice' => $this->browserValue($select)]]);
        $this->assertCount(0, $form->get('state')->getErrors(true), (string) $form->get('state')->getErrors(true));   // the comment's own constraints are not this test's
        $this->assertSame(CommentState::SPAM, $comment->getState());
    }

    public function testAListOfSeveralPrintsEachValueSelected(): void
    {
        $fields = ['tags' => [SelectType::class, ['choices' => ['Red' => 'red', 'Green' => 'green', 'Blue' => 'blue'], 'multiple' => true]]];
        $select = $this->select($this->form(['tags' => ['red', 'blue']], $fields), 'tags');

        $this->assertSame(['red' => 'Red', 'green' => 'Green', 'blue' => 'Blue'], $this->options($select), 'no placeholder in a list of several');
        $this->assertSame(['red', 'blue'], $this->selected($select));
        $this->assertTrue($select->hasAttribute('multiple'));

        $form = $this->form(['tags' => ['red', 'blue']], $fields);
        $form->submit(['tags' => ['choice' => $this->browserValue($select)]]);
        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame(['red', 'blue'], $form->getData()['tags']);
    }

    public function testGroupsAreOptgroups(): void
    {
        $choices = ['Here' => ['In stock' => 'in_stock'], 'Elsewhere' => ['On order' => 'on_order', 'Sold out' => 'sold_out']];
        $select = $this->select($this->form(['availability' => 'sold_out'], ['availability' => [SelectType::class, ['choices' => $choices]]]), 'availability');

        $groups = [];
        foreach ($select->getElementsByTagName('optgroup') as $group) {
            $groups[$group->getAttribute('label')] = array_keys($this->options($group));
        }
        $this->assertSame(['Here' => ['in_stock'], 'Elsewhere' => ['on_order', 'sold_out']], $groups);
        $this->assertSame(['sold_out'], $this->selected($select));
    }

    public function testACurrencyKeepsTheRecordsCurrencyNotTheFirstOfTheList(): void
    {
        $fields = ['currency' => [CurrencyType::class, []]];
        $select = $this->select($this->form(['currency' => 'EUR'], $fields), 'currency');

        $this->assertGreaterThan(100, \count($this->options($select)));
        $this->assertSame(['EUR'], $this->selected($select), 'none was selected: the browser sent the first of the list');
        $this->assertSame('EUR', $this->browserValue($select));

        $form = $this->form(['currency' => 'EUR'], $fields);
        $form->submit(['currency' => ['choice' => $this->browserValue($select)]]);
        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame('EUR', $form->getData()['currency']);
    }

    public function testARequiredSelectSentEmptyIsAnError(): void
    {
        $fields = ['availability' => [SelectType::class, ['choices' => self::STOCK]]];   // required unless said otherwise

        foreach ([['availability' => ['choice' => '']], ['availability' => []], []] as $sent) {
            $form = $this->form(['availability' => 'on_order'], $fields);
            $form->submit($sent);

            $this->assertFalse($form->isValid(), json_encode($sent));
            $errors = $form->get('availability')->getErrors();
            $this->assertCount(1, $errors, json_encode($sent));
            $this->assertSame('This value should not be blank.', $errors[0]->getMessage());
            $this->assertCount(1, $form->getErrors(true), 'said once, on the field');
        }
    }

    /**
     * The empty value is not written into the record: a setter that takes
     * no null (setState(CommentState $state)) answered 500 in place of the
     * form's error.
     */
    public function testTheRecordKeepsWhatItHadWhenARequiredSelectIsSentEmpty(): void
    {
        $comment = (new Comment())->setState(CommentState::SPAM);
        $form = $this->form($comment, ['state' => [SelectType::class, []]]);
        $form->submit(['state' => ['choice' => '']]);

        $this->assertSame('This value should not be blank.', $form->get('state')->getErrors()[0]->getMessage());
        $this->assertSame(CommentState::SPAM, $comment->getState());

        $record = ['availability' => 'on_order'];
        $form = $this->form($record, ['availability' => [SelectType::class, ['choices' => self::STOCK]]]);
        $form->submit(['availability' => ['choice' => '']]);
        $this->assertSame('on_order', $form->getData()['availability']);
    }

    public function testAValueThatIsNoChoiceOfAnEnumSaysSoOnce(): void
    {
        $comment = (new Comment())->setState(CommentState::SPAM);
        $form = $this->form($comment, ['state' => [SelectType::class, []]]);
        $form->submit(['state' => ['choice' => 'nothing-of-the-kind']]);

        $this->assertCount(1, $form->get('state')->getErrors(true), (string) $form->get('state')->getErrors(true));
        $this->assertStringNotContainsString('blank', (string) $form->get('state')->getErrors(true));
        $this->assertSame(CommentState::SPAM, $comment->getState());
    }

    public function testTheErrorIsSaidInTheVisitorsLanguage(): void
    {
        static::getContainer()->get('translator')->setLocale('fr');

        $form = $this->form(['availability' => null], ['availability' => [SelectType::class, ['choices' => self::STOCK]]]);
        $form->submit(['availability' => ['choice' => '']]);

        $this->assertSame('Cette valeur ne doit pas être vide.', $form->get('availability')->getErrors()[0]->getMessage());
    }

    public function testAnOptionalSelectSentEmptyIsEmpty(): void
    {
        $form = $this->form(['availability' => 'on_order'], ['availability' => [SelectType::class, ['choices' => self::STOCK, 'required' => false]]]);
        $select = $this->select($form, 'availability');
        $this->assertArrayHasKey('', $this->options($select), 'the empty option is how "none" is chosen without script');

        $form = $this->form(['availability' => 'on_order'], ['availability' => [SelectType::class, ['choices' => self::STOCK, 'required' => false]]]);
        $form->submit(['availability' => ['choice' => '']]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertNull($form->getData()['availability']);
    }

    public function testAListOfSeveralMayBeEmptyUnlessItSaysOtherwise(): void
    {
        $choices = ['Red' => 'red', 'Green' => 'green'];

        $form = $this->form(['tags' => ['red']], ['tags' => [SelectType::class, ['choices' => $choices, 'multiple' => true]]]);
        $form->submit(['tags' => []]);
        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame([], $form->getData()['tags']);

        $form = $this->form(['tags' => ['red']], ['tags' => [SelectType::class, ['choices' => $choices, 'multiple' => true, 'required_when_multiple' => true]]]);
        $form->submit(['tags' => []]);
        $this->assertFalse($form->isValid());
    }

    public function testADisabledSelectIsNotAsked(): void
    {
        $form = $this->form(['availability' => null], ['availability' => [SelectType::class, ['choices' => self::STOCK, 'disabled' => true]]]);
        $form->submit([]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    public function testWhatSelect2ReceivesIsUnchanged(): void
    {
        $view = $this->form(['availability' => 'on_order'], ['availability' => [SelectType::class, ['choices' => self::STOCK]]])->createView()['availability'];
        $select2 = json_decode($view->vars['select2'], true);

        $this->assertSame(['in_stock' => 'In stock', 'on_order' => 'On order', 'sold_out' => 'Sold out'], array_column($select2['data'], 'text', 'id'));
        $this->assertSame(['on_order'], $select2['selected']);
        $this->assertArrayNotHasKey('select2-entries', $view->vars);
    }
}
