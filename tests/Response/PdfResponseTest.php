<?php

namespace Tests\Base\Response;

use Base\Response\PdfResponse;
use Dompdf\Dompdf;
use PHPUnit\Framework\TestCase;

/**
 * PdfResponse: dompdf/dompdf is a suggested package (a clear exception when
 * it is missing); the PDF is the response's content, where it used to be
 * sent straight to the output by Dompdf's stream(); remote resources are not
 * fetched unless asked.
 */
class PdfResponseTest extends TestCase
{
    private const HTML = '<html><body><h1>Facture 2026-041</h1><p>Total : 120,00 €</p></body></html>';

    public function testWithoutDompdfTheExceptionSaysWhatToInstall(): void
    {
        if (class_exists(Dompdf::class)) {
            $this->markTestSkipped('dompdf/dompdf is installed.');
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('composer require dompdf/dompdf');

        new PdfResponse(self::HTML);
    }

    public function testTheHtmlIsAnsweredAsItIsInDebug(): void
    {
        $response = new PdfResponse(self::HTML, 200, ['debug' => true, 'X-Robots-Tag' => 'noindex']);

        $this->assertSame(self::HTML, $response->getContent());
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $this->assertFalse($response->headers->has('debug'), 'a setting, not a header');
    }

    public function testThePdfIsTheContentOfTheResponse(): void
    {
        $this->needsDompdf();

        ob_start();
        $response = new PdfResponse(self::HTML, 200, ['filename' => 'facture-2026-041.pdf']);
        $sent = ob_get_clean();

        $this->assertSame('', $sent, 'nothing is sent before the kernel sends the response');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename=facture-2026-041.pdf', $response->headers->get('Content-Disposition'));
        $this->assertFalse($response->headers->has('filename'));
    }

    public function testAsADownload(): void
    {
        $this->needsDompdf();

        $response = PdfResponse::fromPdfString(self::HTML, 200, ['stream' => ['Attachment' => true]]);

        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame('attachment; filename=document.pdf', $response->headers->get('Content-Disposition'));
    }

    public function testRemoteResourcesAreOffUnlessAsked(): void
    {
        $this->assertFalse(PdfResponse::options()['isRemoteEnabled']);
        $this->assertTrue(PdfResponse::options(['isRemoteEnabled' => true])['isRemoteEnabled']);

        $this->needsDompdf();

        // An image at an http address is left out, the page still rendered.
        $pdf = PdfResponse::render('<html><body><img src="http://127.0.0.1:9/pixel.png"><p>x</p></body></html>');
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    private function needsDompdf(): void
    {
        if (!class_exists(Dompdf::class)) {
            $this->markTestSkipped('Requires dompdf/dompdf (a suggested package).');
        }
    }
}
