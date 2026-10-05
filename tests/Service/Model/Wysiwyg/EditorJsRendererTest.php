<?php

namespace Tests\Base\Service\Model\Wysiwyg;

use Base\Service\Model\Wysiwyg\EditorJsRenderer;
use PHPUnit\Framework\TestCase;

/** EditorJS's document as HTML on the server: each block of omnibase's editor, and nothing that runs. */
class EditorJsRendererTest extends TestCase
{
    private function html(array ...$blocks): string
    {
        return EditorJsRenderer::render(json_encode(['time' => 1, 'blocks' => $blocks, 'version' => '2.30.0']));
    }

    public function testTextBlocks(): void
    {
        $html = $this->html(
            ['type' => 'header', 'data' => ['text' => 'Le <i>cabinet</i>', 'level' => 2]],
            ['type' => 'paragraph', 'data' => ['text' => 'Ouvert <b>du lundi</b> au <a href="/contact">vendredi</a>.']],
            ['type' => 'paragraph', 'data' => ['text' => '<br>']],
            ['type' => 'quote', 'data' => ['text' => 'Nul n\'est censé ignorer la loi.', 'caption' => 'Adage']],
            ['type' => 'delimiter', 'data' => []],
            ['type' => 'code', 'data' => ['code' => 'if ($a < $b) { echo "<b>"; }']],
            ['type' => 'warning', 'data' => ['title' => 'Attention', 'message' => 'Fermé le 1er mai.']],
        );

        $this->assertSame(implode("\n", [
            '<h2>Le <i>cabinet</i></h2>',
            '<p>Ouvert <b>du lundi</b> au <a href="/contact">vendredi</a>.</p>',
            '<blockquote><p>Nul n\'est censé ignorer la loi.</p><footer><cite>Adage</cite></footer></blockquote>',
            '<hr>',
            '<pre><code>if ($a &lt; $b) { echo &quot;&lt;b&gt;&quot;; }</code></pre>',
            '<aside class="wysiwyg-alert wysiwyg-alert-warning" role="note"><strong>Attention</strong> Fermé le 1er mai.</aside>',
        ]), $html, 'an empty paragraph is left out');
    }

    public function testLists(): void
    {
        $this->assertSame('<ul><li>un</li><li>deux</li></ul>', $this->html(['type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['un', 'deux']]]));

        $this->assertSame(
            '<ol><li>un<ol><li>un bis</li></ol></li><li>deux</li></ol>',
            $this->html(['type' => 'list', 'data' => ['style' => 'ordered', 'items' => [
                ['content' => 'un', 'items' => [['content' => 'un bis', 'items' => []]]],
                ['content' => 'deux', 'items' => []],
            ]]]),
            'NestedList'
        );

        $this->assertSame(
            '<ul class="wysiwyg-checklist"><li class="is-checked"><input type="checkbox" disabled checked> fait</li><li class="is-unchecked"><input type="checkbox" disabled> à faire</li></ul>',
            $this->html(['type' => 'checklist', 'data' => ['items' => [['text' => 'fait', 'checked' => true], ['text' => 'à faire', 'checked' => false]]]])
        );
    }

    public function testImageEmbedAndTable(): void
    {
        $this->assertSame(
            '<figure class="wysiwyg-image is-stretched"><img src="/images/abc/photo.webp" alt="La salle" loading="lazy"><figcaption>La <b>salle</b></figcaption></figure>',
            $this->html(['type' => 'image', 'data' => ['file' => ['url' => '/images/abc/photo.webp'], 'caption' => 'La <b>salle</b>', 'stretched' => true]])
        );

        $embed = $this->html(['type' => 'embed', 'data' => ['service' => 'youtube', 'embed' => 'https://www.youtube.com/embed/x1', 'width' => 580, 'height' => 320, 'caption' => '']]);
        $this->assertStringContainsString('<iframe src="https://www.youtube.com/embed/x1" width="580" height="320"', $embed);
        $this->assertStringContainsString('wysiwyg-embed-youtube', $embed);

        $this->assertSame(
            '<table><thead><tr><th scope="col">Acte</th><th scope="col">Tarif</th></tr></thead><tbody><tr><td>Vente</td><td>1 %</td></tr></tbody></table>',
            $this->html(['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['Acte', 'Tarif'], ['Vente', '1 %']]]])
        );
    }

    public function testNothingThatRuns(): void
    {
        $html = $this->html(
            ['type' => 'paragraph', 'data' => ['text' => 'a<script>alert(1)</script> <a href="javascript:alert(1)" onclick="x()">b</a> <img src=x onerror=alert(1)>']],
            ['type' => 'image', 'data' => ['file' => ['url' => 'javascript:alert(1)']]],
            ['type' => 'embed', 'data' => ['embed' => 'javascript:alert(1)']],
            ['type' => 'header', 'data' => ['text' => 'T', 'level' => 9]],
        );

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<figure', $html);
        $this->assertStringContainsString('<h6>T</h6>', $html);
    }

    public function testAnUnknownBlockOrDocumentIsLeftOut(): void
    {
        $this->assertSame('<p>x</p>', $this->html(['type' => 'hologram', 'data' => ['z' => 1]], ['type' => 'paragraph', 'data' => ['text' => 'x']]));
        $this->assertSame('', EditorJsRenderer::render('{"blocks": []}'));
        $this->assertSame('', EditorJsRenderer::render('not json'));
        $this->assertSame('<p>o</p>', EditorJsRenderer::render((object) ['blocks' => [(object) ['type' => 'paragraph', 'data' => (object) ['text' => 'o']]]]), 'a decoded document too');
    }
}
