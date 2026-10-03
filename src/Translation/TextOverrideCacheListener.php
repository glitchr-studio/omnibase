<?php

namespace Base\Translation;

use Base\Entity\Layout\TextOverride;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * A rewritten text saved or deleted: the next page reads them all again.
 *
 * Noted on the row's events, cleared once the flush is over (postFlush):
 * cleared inside it, before the commit, a page read in between would put the
 * old texts back in the cache.
 */
class TextOverrideCacheListener
{
    protected bool $changed = false;

    /** @param \Closure(): OverridingTranslator $translator lazy: a Doctrine listener is built while Doctrine starts */
    public function __construct(protected readonly \Closure $translator)
    {
    }

    public function postPersist(LifecycleEventArgs $args): void { $this->note($args->getObject()); }
    public function postUpdate(LifecycleEventArgs $args): void { $this->note($args->getObject()); }
    public function postRemove(LifecycleEventArgs $args): void { $this->note($args->getObject()); }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->changed) {
            return;
        }
        $this->changed = false;
        ($this->translator)()->invalidate();
    }

    protected function note(object $entity): void
    {
        if ($entity instanceof TextOverride) {
            $this->changed = true;
        }
    }
}
