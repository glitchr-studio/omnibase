<?php

namespace Base\Translation;

use Base\Repository\Layout\TextOverrideRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Translation\Formatter\IntlFormatter;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The site's texts as the team rewrote them in the back office
 * (Base\Entity\Layout\TextOverride, omnibase/admin's "Textes du site"): a
 * rewritten key, in that language, wins over translations/. Between
 * omnibase's translator (which resolves "@messages.key" and falls back
 * between languages) and Symfony's: it sees a plain key and domain, and only
 * answers when it has something.
 *
 * The rewritten texts are read once per request from the cache - the shared
 * Redis pool (cache.redis) when the application has one, so the web, the
 * worker and cron agree; TextOverrideCacheListener empties it when one changes.
 * Moved here from the apps' App\Translation\OverridingTranslator.
 */
class OverridingTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, ResetInterface
{
    public const CACHE_KEY = 'base.text_overrides';

    /** @var array<string, string>|null */
    protected ?array $map = null;
    protected ?IntlFormatter $formatter = null;

    public function __construct(
        protected readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
        /** @var \Closure(): TextOverrideRepository lazy: building a repository reads entity metadata, too early while Doctrine starts */
        protected readonly \Closure $overrides,
        protected readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if (null !== $id && '' !== $id) {
            $lang = substr(str_replace('-', '_', $locale ?? $this->inner->getLocale()), 0, 2);
            $text = $this->map()[($domain ?? 'messages').'|'.$lang.'|'.$id] ?? null;
            if (null !== $text) {
                try {
                    return ($this->formatter ??= new IntlFormatter())->formatIntl($text, $lang, $parameters);
                } catch (\Throwable) {
                    // Not a valid ICU message (a stray brace): its placeholders, plainly.
                    $replace = [];
                    foreach ($parameters as $key => $value) {
                        if (\is_scalar($value) || $value instanceof \Stringable) {
                            $replace[str_starts_with((string) $key, '{') ? (string) $key : '{'.$key.'}'] = (string) $value;
                        }
                    }

                    return strtr($text, $replace);
                }
            }
        }

        return $this->inner->trans($id, $parameters, $domain, $locale);
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    /**
     * The languages after the default one (framework.translator.fallbacks):
     * asked by name - method_exists(), which __call does not answer - by the
     * translator above (the profiler's) and through it by omnibase's Localizer.
     */
    public function getFallbackLocales(): array
    {
        return method_exists($this->inner, 'getFallbackLocales') ? $this->inner->getFallbackLocales() : [];
    }

    /** What the inner translator offers beyond the interfaces. */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    public function reset(): void
    {
        $this->map = null;
    }

    /** Forget the rewritten texts: the next call reads them again. */
    public function invalidate(): void
    {
        try {
            $this->cache->deleteItem(self::CACHE_KEY);
        } catch (\Throwable) {
        }
        $this->reset();
    }

    /** @return array<string, string> */
    protected function map(): array
    {
        if (null !== $this->map) {
            return $this->map;
        }

        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            if (!$item->isHit()) {
                $item->set(($this->overrides)()->map());
                $this->cache->save($item);
            }

            return $this->map = $item->get();
        } catch (\Throwable) {
            // No table yet (before the migration), no database: the files serve -
            // and for five minutes the query is not tried again on every request.
            try {
                $this->cache->save($this->cache->getItem(self::CACHE_KEY)->set([])->expiresAfter(300));
            } catch (\Throwable) {
            }

            return $this->map = [];
        }
    }
}
