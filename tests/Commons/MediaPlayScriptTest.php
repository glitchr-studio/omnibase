<?php

namespace Tests\Base\Commons;

use PHPUnit\Framework\TestCase;

/**
 * media.js (bundles/base/js/media.js): the script a page links is the source
 * kept in assets/, and it has no dependency. Its behaviour is tested on jsdom
 * (tests/js/media.test.mjs).
 */
class MediaPlayScriptTest extends TestCase
{
    public function testThePublishedScriptIsItsSource(): void
    {
        $root = \dirname(__DIR__, 2);
        $source = (string) file_get_contents($root.'/assets/media/media.js');

        $this->assertNotSame('', $source);
        $this->assertSame($source, (string) file_get_contents($root.'/public/js/media.js'));
        $this->assertDoesNotMatchRegularExpression('/^\s*(import|export)\s|require\(|jQuery|\$\(/m', $source, 'a plain script, nothing to load before it');
        $this->assertStringContainsString("'media:play'", $source);
    }
}
