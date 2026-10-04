<?php

namespace Tests\Base\Commons;

use PHPUnit\Framework\TestCase;

/**
 * Glitch Art's signature (bundles/base/css/credits.css). Its detail line is
 * hidden by opacity, which keeps its width: set between the signature and the
 * edge it stands against, it pushed the signature that far from the edge
 * (about 140px on a site showing it on the left). Where the detail fades in
 * and out it holds no room; the font, its size and the two inks are untouched.
 */
class CreditsStylesheetTest extends TestCase
{
    private static function css(string $path): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/'.$path);
    }

    public function testThePublishedStylesheetIsItsSource(): void
    {
        $this->assertSame(self::css('assets/styles/credits/credits.css'), self::css('public/css/credits.css'));
    }

    public function testTheHiddenDetailHoldsNoRoomWhereItFadesInAndOut(): void
    {
        $css = self::css('public/css/credits.css');

        $this->assertSame(1, preg_match('/@media \(hover: hover\) \{(.*?)\n\}/s', $css, $block), 'a block for pointers that hover');
        $this->assertMatchesRegularExpression('/\.ga-credits-detail \{[^}]*\bwidth: 0;/', $block[1]);
        $this->assertMatchesRegularExpression('/\.ga-credits \{ gap: 0; \}/', $block[1]);

        // Without a pointer it is always shown, and takes its place.
        $this->assertMatchesRegularExpression('/@media \(hover: none\) \{\s*\.ga-credits-detail \{ opacity: 1; \}/', $css);
    }

    public function testTheSignatureKeepsItsFontAndItsTwoInks(): void
    {
        $css = self::css('public/css/credits.css');

        $this->assertStringContainsString('font-family: "Source Code Pro", ui-monospace, monospace;', $css);
        $this->assertStringContainsString('font-size: 18pt;', $css);
        $this->assertStringContainsString('line-height: 21px;', $css);
        $this->assertStringContainsString('--ga-credits-ink: #000;', $css);
        $this->assertStringContainsString('.ga-credits--white { --ga-credits-ink: #fff; }', $css);

        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $colours);
        $this->assertSame(['#000', '#fff'], array_values(array_unique(array_map('strtolower', $colours[0]))), 'black and white only');
    }
}
