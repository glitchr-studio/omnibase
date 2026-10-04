<?php

namespace Tests\Base\Fixtures\Form;

use Base\Enum\CommentState;

/** A plain model (no Doctrine mapping) whose properties are typed with PHP enums. */
class Hand
{
    public ?Suit $suit = null;
    public CommentState $state = CommentState::PENDING;
    /** @var list<CommentState> */
    public array $states = [];
    public ?string $legacy = null;
}
