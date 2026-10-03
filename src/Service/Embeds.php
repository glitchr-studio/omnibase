<?php

namespace Base\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * An address (a video, a song, a board, a slideshow) turned into what a page
 * can frame - the Twig function embed_url():
 *
 *     {% set frame = embed_url(link) %}
 *     {% if frame and frame.src %}<iframe src="{{ frame.src }}" style="aspect-ratio: {{ frame.ratio }}"…>{% endif %}
 *
 * The providers known to allow framing are read from the address itself,
 * without a request (KNOWN: YouTube in its no-cookie form, Vimeo, Canva,
 * Padlet, LearningApps, Genially, Google Slides). Anything else is asked to
 * its own site through the embed/embed library (oEmbed, Open Graph), once a
 * day per address, when the library is installed; its iframe's address is
 * kept, never its script. Nothing found: null - a plain link on the page.
 *
 * @phpstan-type Frame array{provider: string, src: ?string, ratio: string, height: ?int, title: ?string, html: ?string, url: string, known: bool}
 */
class Embeds
{
    public const KNOWN = ['Canva', 'Padlet', 'YouTube', 'Vimeo', 'LearningApps', 'Genially', 'Google Slides'];

    public function __construct(
        protected readonly ?CacheInterface $cache = null,
        protected readonly ?LoggerInterface $logger = null,
        protected readonly bool $remote = true,
    ) {
    }

    /** @return Frame|null */
    public function resolve(?string $url, bool $remote = true): ?array
    {
        $url = trim((string) $url);
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }

        $known = $this->known($url);
        if ($known || !$remote || !$this->remote || !class_exists(\Embed\Embed::class)) {
            return $known;
        }

        $fetch = fn () => $this->fetch($url);

        return $this->cache
            ? $this->cache->get('base.embed.'.hash('xxh128', $url), function (ItemInterface $item) use ($fetch) {
                $item->expiresAfter(86400);

                return $fetch();
            })
            : $fetch();
    }

    /** @return Frame|null the providers read from the address alone */
    public function known(string $url): ?array
    {
        $parts = parse_url($url);
        $host = strtolower(preg_replace('/^www\./', '', $parts['host'] ?? ''));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        $frame = match (true) {
            // https://www.canva.com/design/DAF.../XYZ/view or /edit: the published design.
            'canva.com' === $host && (bool) preg_match('#^/design/([A-Za-z0-9_-]+)#', $path, $m) => ['canva', sprintf('https://www.canva.com/design/%s/view?embed', $m[1]), '16 / 9'],
            // https://padlet.com/<user>/<title>-<id>: the board, by its id (the end of its address).
            'padlet.com' === $host && (bool) preg_match('#/(?:embed/)?(?:[^/]+/)?(?:[^/]*-)?([a-z0-9]{8,})/?$#i', $path, $m) => ['padlet', 'https://padlet.com/embed/'.$m[1], '4 / 3'],
            \in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com'], true) && isset($query['v']) => ['youtube', 'https://www.youtube-nocookie.com/embed/'.rawurlencode((string) $query['v']), '16 / 9'],
            \in_array($host, ['youtube.com', 'm.youtube.com'], true) && (bool) preg_match('#^/(?:shorts|embed|live)/([A-Za-z0-9_-]+)#', $path, $m) => ['youtube', 'https://www.youtube-nocookie.com/embed/'.$m[1], '16 / 9'],
            'youtu.be' === $host && '' !== trim($path, '/') => ['youtube', 'https://www.youtube-nocookie.com/embed/'.rawurlencode(trim($path, '/')), '16 / 9'],
            'vimeo.com' === $host && (bool) preg_match('#^/(\d+)#', $path, $m) => ['vimeo', 'https://player.vimeo.com/video/'.$m[1], '16 / 9'],
            'learningapps.org' === $host && (isset($query['v']) || (bool) preg_match('#^/(?:view|watch)?/?(\d+)#', $path, $m)) => ['learningapps', 'https://learningapps.org/watch?app='.rawurlencode((string) ($query['v'] ?? $m[1] ?? '')), '4 / 3'],
            \in_array($host, ['genial.ly', 'view.genial.ly', 'view.genially.com', 'genially.com'], true) && (bool) preg_match('#([a-f0-9]{24})#', $path, $m) => ['genially', 'https://view.genially.com/'.$m[1], '16 / 9'],
            'docs.google.com' === $host && (bool) preg_match('#^/presentation/d/([A-Za-z0-9_-]+)#', $path, $m) => ['google-slides', sprintf('https://docs.google.com/presentation/d/%s/embed', $m[1]), '16 / 9'],
            default => null,
        };

        return $frame ? ['provider' => $frame[0], 'src' => $frame[1], 'ratio' => $frame[2], 'height' => null, 'title' => null, 'html' => null, 'url' => $url, 'known' => true] : null;
    }

    /** @return Frame|null what the address's own site says (embed/embed) */
    protected function fetch(string $url): ?array
    {
        try {
            $info = (new \Embed\Embed())->get($url);
            $html = $info->code?->html;
            if (!$html || !preg_match('#<iframe[^>]+src="(https://[^"]+)"#i', $html, $m)) {
                return null;
            }
            $width = (int) ($info->code->width ?? 0);
            $height = (int) ($info->code->height ?? 0);
            // A player of a fixed height across the whole width (SoundCloud's "100%" x 400): its height, no ratio.
            $fixed = $width <= 0 && $height > 0;
            $provider = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) ($info->providerName ?: parse_url($url, \PHP_URL_HOST))));

            return [
                'provider' => trim($provider, '-'),
                'src' => html_entity_decode($m[1]),
                'ratio' => $width > 0 && $height > 0 ? $width.' / '.$height : '16 / 9',
                'height' => $fixed ? $height : null,
                'title' => $info->title ? (string) $info->title : null,
                'html' => $html,
                'url' => $url,
                'known' => false,
            ];
        } catch (\Throwable $e) {
            $this->logger?->info('No embed for {url}: {message}', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }
    }
}
