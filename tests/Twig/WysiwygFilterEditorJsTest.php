<?php

namespace Tests\Base\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * |wysiwyg on an EditorJS document prints its blocks as HTML: it printed an
 * empty <div data-edjs="..."> that only the editor's script could fill - a
 * site that does not load that script on its pages showed nothing.
 */
class WysiwygFilterEditorJsTest extends KernelTestCase
{
    private const DOCUMENT = '{"time":1,"blocks":[{"type":"header","data":{"text":"Nos domaines","level":2}},{"type":"paragraph","data":{"text":"Droit <b>de la famille</b>."}},{"type":"list","data":{"style":"unordered","items":["Divorce","Succession"]}}]}';

    protected function setUp(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Requires a host application (the omnibase harness).');
        }

        self::bootKernel();
        static::getContainer()->get('request_stack')->push(Request::create('/'));
    }

    private function render(string $template): string
    {
        return static::getContainer()->get('twig')->createTemplate($template)->render(['content' => self::DOCUMENT]);
    }

    public function testTheBlocksAreInThePage(): void
    {
        $html = $this->render('{{ content|wysiwyg({media: false})|raw }}');

        $this->assertStringContainsString('<h2>Nos domaines</h2>', $html);
        $this->assertStringContainsString('<p>Droit <b>de la famille</b>.</p>', $html);
        $this->assertStringContainsString('<ul><li>Divorce</li><li>Succession</li></ul>', $html);
        $this->assertStringContainsString('data-edjs=', $html, 'the document stays there for the editor\'s script, where a page loads it');
        $this->assertStringContainsString('class="codex-container wysiwyg', $html);
    }

    public function testTheHtmlAlone(): void
    {
        $html = $this->render('{{ content|wysiwyg({media: false, hydrate: false})|raw }}');

        $this->assertStringContainsString('<h2>Nos domaines</h2>', $html);
        $this->assertStringNotContainsString('data-edjs', $html);
    }
}
