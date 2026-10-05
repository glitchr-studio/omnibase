<?php

namespace Tests\Base\Form;

use Base\Form\Model\ContactModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * The contact form writes its fields into ContactModel through the property
 * accessor, and an upload field left empty is null there, not an empty list.
 */
class ContactModelAttachmentsTest extends TestCase
{
    public function testAMessageWithoutAFileKeepsAnEmptyList(): void
    {
        $model = new ContactModel();

        PropertyAccess::createPropertyAccessor()->setValue($model, 'attachments', null);

        $this->assertSame([], $model->attachments);
    }

    public function testTheFilesSentAreKept(): void
    {
        $model = new ContactModel();

        PropertyAccess::createPropertyAccessor()->setValue($model, 'attachments', ['a.png', 'b.png']);

        $this->assertSame(['a.png', 'b.png'], $model->attachments);
    }
}
