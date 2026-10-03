<?php

namespace Base\Enum;

/**
 * Where a comment is: waiting for the moderator, online, filed as spam, or
 * taken down. A string enum (not omnibase's EnumType) so the column reads
 * plainly in the database and in the admin's filters.
 */
enum CommentState: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case SPAM = 'spam';
    case TRASH = 'trash';

    public function isVisible(): bool
    {
        return self::APPROVED === $this;
    }
}
