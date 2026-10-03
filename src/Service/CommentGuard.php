<?php

namespace Base\Service;

use Base\Repository\Thread\CommentRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * What stops a robot before Akismet is even asked, and what Akismet cannot
 * know: the trap field filled, a form sent faster than a person types, a
 * second comment from the same address within the flood interval
 * (base.comments.min_delay, base.comments.flood_interval). Each answers
 * with a reason the controller turns into a form error - except the trap,
 * which should be thanked and dropped so the robot learns nothing.
 *
 * Reads the form Base\Form\Type\CommentType builds: its `url` trap and its
 * `opened` time.
 */
class CommentGuard
{
    public const TRAPPED = 'trapped';
    public const TOO_FAST = 'too_fast';
    public const FLOOD = 'flood';

    public function __construct(
        protected readonly CommentRepository $comments,
        #[Autowire('%base.comments.min_delay%')] protected readonly int $minDelay = 4,
        #[Autowire('%base.comments.flood_interval%')] protected readonly int $floodInterval = 60,
    ) {
    }

    /** null when the comment may go on to Akismet. */
    public function check(FormInterface $form, Request $request, ?int $minDelay = null, ?int $floodInterval = null): ?string
    {
        $minDelay ??= $this->minDelay;
        $floodInterval ??= $this->floodInterval;

        if ($form->has('url') && '' !== trim((string) $form->get('url')->getData())) {
            return self::TRAPPED;
        }
        $opened = $form->has('opened') ? (int) $form->get('opened')->getData() : 0;
        if ($minDelay > 0 && ($opened <= 0 || time() - $opened < $minDelay)) {
            return self::TOO_FAST;
        }
        $ip = $request->getClientIp();
        if ($floodInterval > 0 && $ip) {
            $last = $this->comments->findLastFromIp($ip);
            if ($last && $last->getCreatedAt() && time() - $last->getCreatedAt()->getTimestamp() < $floodInterval) {
                return self::FLOOD;
            }
        }

        return null;
    }
}
