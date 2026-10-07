<?php

namespace Tests\Base\Http;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page of the site loads nothing from anyone else: what the contact page
 * links - scripts, stylesheets, images, frames - is the site's, and so is
 * what those scripts and stylesheets pull in themselves.
 *
 * The editor's script carried editorjs-code-highlight's styles, and with
 * them an @import of highlight.js's theme from cdnjs.cloudflare.com: every
 * page the script is on (the contact page among them) fetched it. The
 * @import is dropped at build (assets/loaders/no-remote-import.js), the
 * theme is the bundle's own copy (bundles/base/css/highlight.js/, BSD
 * 3-Clause, its licence beside it), linked by the editor's script only where
 * an editor or a block of code is shown. The emoji picker (picmo) fetched
 * its emojis from jsDelivr: they are the bundle's copy too (bundles/base/
 * emoji/, emojibase-data, MIT), on the harness's /fields.
 */
class NothingFromElsewhereHttpTest extends KernelTestCase
{
    use HttpTestTrait;

    private const PUBLIC_DIR = __DIR__.'/../../public';

    /** Hosts a page must not reach on its own. */
    private const CDNS = ['cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 'fonts.googleapis.com', 'fonts.gstatic.com', 'ajax.googleapis.com', 'code.jquery.com'];

    protected function setUp(): void
    {
        $this->bootHost();
    }

    private function page(string $path): Response
    {
        $response = $this->request($path);
        for ($hops = 0; $response->isRedirection() && $hops < 3; ++$hops) {
            $response = $this->request((string) $response->headers->get('Location'));
        }

        return $response;
    }

    /** @return list<string> what the page has the browser fetch: src, href of a link, srcset, poster, data */
    private static function resources(string $html): array
    {
        $urls = [];
        preg_match_all('/<(script|link|img|iframe|source|video|audio|embed|object|input)\b[^>]*>/i', $html, $tags, \PREG_SET_ORDER);
        foreach ($tags as [$tag, $name]) {
            preg_match_all('/\s(src|href|srcset|poster|data)\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $attributes, \PREG_SET_ORDER);
            foreach ($attributes as $attribute) {
                $attributeName = strtolower($attribute[1]);
                $value = html_entity_decode($attribute[4] ?? '' ?: $attribute[3]);
                if ('link' === strtolower($name) && 'href' === $attributeName && preg_match('/\srel\s*=\s*["\']?(canonical|alternate)\b/i', $tag)) {
                    continue; // an address of the page, not something to fetch
                }
                if ('srcset' === $attributeName) {
                    foreach (explode(',', $value) as $candidate) {
                        $urls[] = trim(explode(' ', trim($candidate))[0]);
                    }
                    continue;
                }
                $urls[] = $value;
            }
        }
        // Stylesheets of the page itself: @import and url().
        preg_match_all('/(?:@import\s+(?:url\()?|url\()\s*["\']?([^"\')\s;]+)/i', implode("\n", preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styles) ? $styles[1] : []), $found);

        return array_values(array_unique(array_filter([...$urls, ...$found[1]], static fn ($url) => '' !== $url && !str_starts_with($url, 'data:') && !str_starts_with($url, '#'))));
    }

    private static function isElsewhere(string $url): bool
    {
        $host = parse_url(str_starts_with($url, '//') ? 'https:'.$url : $url, \PHP_URL_HOST);

        return \is_string($host) && 'localhost' !== $host;
    }

    /** The content of a file the page links on the site, or null if it is not one the test can read. */
    private function local(string $url): ?string
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        if (str_starts_with($path, '/bundles/base/')) {
            $file = self::PUBLIC_DIR.'/'.substr($path, \strlen('/bundles/base/'));

            return is_file($file) ? (string) file_get_contents($file) : null;
        }
        $response = $this->request($path);
        if (200 !== $response->getStatusCode()) {
            return null;
        }

        return $response instanceof BinaryFileResponse ? (string) file_get_contents($response->getFile()->getPathname()) : (string) $response->getContent();
    }

    /**
     * The page, and what its own scripts and stylesheets pull in, reach no other origin.
     *
     * @return array{string, array<string, string>} the page's HTML, and the site's files it links by address
     */
    private function assertNothingFromElsewhere(string $path): array
    {
        $response = $this->page($path);
        self::assertSame(200, $response->getStatusCode(), $path);
        $html = (string) $response->getContent();

        $resources = self::resources($html);
        self::assertNotEmpty($resources, $path.' links its scripts and stylesheets');
        self::assertSame([], array_values(array_filter($resources, self::isElsewhere(...))), 'every resource of '.$path.' is the site\'s');
        foreach (self::CDNS as $cdn) {
            self::assertStringNotContainsString($cdn, $html);
        }

        $files = [];
        foreach (array_filter($resources, static fn ($url) => (bool) preg_match('/\.(js|css)(\?|$)/', (string) parse_url($url, \PHP_URL_PATH))) as $url) {
            $content = $this->local($url);
            if (null === $content) {
                continue;
            }
            $files[$url] = $content;
            self::assertSame(0, preg_match('/@import\s+url\(\s*[\'"]?(https?:)?\/\/[^)]*\)/i', $content, $import), $url.' imports a stylesheet from elsewhere: '.($import[0] ?? ''));
            foreach (self::CDNS as $cdn) {
                self::assertFalse(str_contains($content, $cdn), $url.' names '.$cdn);
            }
        }
        self::assertNotEmpty($files, 'the site\'s own files were read');

        return [$html, $files];
    }

    /** @param array<string, string> $files */
    private static function script(array $files, string $entry): ?string
    {
        foreach ($files as $url => $content) {
            $file = basename((string) parse_url($url, \PHP_URL_PATH));
            if (preg_match('/^'.preg_quote($entry, '/').'\.[0-9a-f]{8}\.js$/', $file)) {
                return $content;
            }
        }

        return null;
    }

    public function testTheContactPageLoadsNothingFromElsewhere(): void
    {
        [$html, $files] = $this->assertNothingFromElsewhere('/contact');

        // The code blocks' theme: not on the page, linked by the editor's script where it is needed.
        self::assertStringNotContainsString('css/highlight.js/', $html);
        $editor = self::script($files, 'form-defer.editor');
        self::assertNotNull($editor, 'the editor\'s script is on the page, as on every page with a form');
        self::assertTrue(str_contains($editor, 'css/highlight.js/default.css'), 'the editor\'s script links the theme itself, where it is needed');
    }

    /**
     * The emoji picker's data: picmo fetched it from jsDelivr (emojibase-data);
     * it is the bundle's own copy, given to the picker by the field's script.
     */
    public function testAPageWithAnEmojiFieldLoadsNothingFromElsewhere(): void
    {
        [$html, $files] = $this->assertNothingFromElsewhere('/fields');

        self::assertStringContainsString('data-emoji-field', $html, 'the page has an emoji field');
        $picker = self::script($files, 'form-defer.emoji');
        self::assertNotNull($picker, 'the field\'s script is on the page');
        self::assertTrue(str_contains($picker, 'emoji/'), 'the picker is given the bundle\'s data');
        self::assertFalse(str_contains($picker, 'emojibase-data@'), 'nothing of picmo\'s own fetching from the registry\'s CDN is left');

        foreach (['en', 'fr', 'de', 'ja'] as $locale) {
            $emojis = json_decode((string) file_get_contents(self::PUBLIC_DIR.'/emoji/'.$locale.'/data.json'), true);
            self::assertIsArray($emojis, $locale);
            self::assertGreaterThan(1000, \count($emojis), $locale);
            self::assertArrayHasKey('label', $emojis[0]);
            self::assertArrayHasKey('hexcode', $emojis[0]);
            $messages = json_decode((string) file_get_contents(self::PUBLIC_DIR.'/emoji/'.$locale.'/messages.json'), true);
            self::assertNotEmpty($messages['groups'] ?? null, $locale);
        }
        self::assertStringContainsString('MIT License', (string) file_get_contents(self::PUBLIC_DIR.'/emoji/LICENSE.txt'));
    }

    public function testTheCodeBlocksThemeIsShippedUnderItsLicence(): void
    {
        $theme = self::PUBLIC_DIR.'/css/highlight.js/default.css';
        self::assertFileExists($theme);
        self::assertStringContainsString('Theme: Default', (string) file_get_contents($theme));
        self::assertStringContainsString('.hljs', (string) file_get_contents($theme));
        self::assertStringContainsString('BSD 3-Clause License', (string) file_get_contents(self::PUBLIC_DIR.'/css/highlight.js/LICENSE.txt'));

        $manifest = json_decode((string) file_get_contents(self::PUBLIC_DIR.'/manifest.json'), true);
        self::assertSame('/bundles/base/css/highlight.js/default.css', $manifest['./css/highlight.js/default.css'] ?? null);
        $entrypoints = json_decode((string) file_get_contents(self::PUBLIC_DIR.'/entrypoints.json'), true);
        foreach ($entrypoints['entrypoints']['form-defer.editor']['js'] as $script) {
            $content = (string) file_get_contents(self::PUBLIC_DIR.'/'.basename($script));
            self::assertFalse(str_contains($content, 'cdnjs.cloudflare.com'), $script.' names cdnjs');
            self::assertTrue(str_contains($content, 'css/highlight.js/default.css'), $script.' links the theme where it is needed');
        }
    }
}
