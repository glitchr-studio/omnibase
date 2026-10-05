<?php

namespace Base\Form\Model;

use Base\Database\Attribute\Uploader;
use Base\Form\Common\AbstractModel;
use Base\Notifier\Recipient\Recipient;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the contact form (Base\Form\Type\ContactType) sends: a name, an
 * e-mail, a message, and as the form is set up a phone, a subject,
 * attachments. `website` is the trap: left empty by people, filled by the
 * robots that fill every field (isRobot()).
 */
class ContactModel extends AbstractModel
{
    public function getRecipient(): ?Recipient
    {
        return new Recipient(mailformat([], $this->name ?? "", $this->email ?? ""));
    }

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 30)]
    public ?string $phone = null;

    #[Assert\Length(max: 255)]
    public ?string $subject = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 5000)]
    public ?string $message = null;

    /** Left empty by people; filled by the robots that fill every field. */
    public ?string $website = null;

    #[Uploader(max_size: "5MB", mime_types: ["image/*"])]
    public array $attachments = [];

    /**
     * A form sent with no file hands null to its model, not an empty list:
     * written straight into the property, that null stopped the request
     * ("Cannot assign null to property ... of type array") on every message
     * without an attachment.
     */
    public function setAttachments(?array $attachments): void
    {
        $this->attachments = $attachments ?? [];
    }

    /** The trap was filled: thank it all the same, and send nothing. */
    public function isRobot(): bool
    {
        return null !== $this->website && '' !== trim($this->website);
    }
}
