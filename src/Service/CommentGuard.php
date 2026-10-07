<?php

namespace Base\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The comment forms' face of Base\Service\FormGuard, kept for the bundles
 * that ask it (omnibase/blog, omnibase/video): the trap filled, a form sent
 * faster than a person types, a second comment from the same address within
 * the flood interval (base.comments.min_delay, base.comments.flood_interval).
 * Each answers with a reason the controller turns into a form error - except
 * the trap, which should be thanked and dropped so the robot learns nothing.
 *
 * Reads the form Base\Form\Type\CommentType builds: its `url` trap and its
 * `opened` time - or the fields the option `guard` adds.
 */
class CommentGuard
{
    public const TRAPPED = FormGuard::TRAPPED;
    public const TOO_FAST = FormGuard::TOO_FAST;
    public const FLOOD = FormGuard::FLOOD;

    public function __construct(
        protected readonly FormGuard $guard,
        #[Autowire('%base.comments.min_delay%')] protected readonly int $minDelay = 4,
        #[Autowire('%base.comments.flood_interval%')] protected readonly int $floodInterval = 60,
    ) {
    }

    /** null when the comment may go on to Akismet. */
    public function check(FormInterface $form, Request $request, ?int $minDelay = null, ?int $floodInterval = null): ?string
    {
        return $this->guard->check($form, $request, $minDelay ?? $this->minDelay, $floodInterval ?? $this->floodInterval);
    }
}
