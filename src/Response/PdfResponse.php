<?php

namespace Base\Response;

use Dompdf\Dompdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * An HTML page answered as a PDF, rendered by dompdf/dompdf - a suggested
 * package: `composer require dompdf/dompdf`.
 *
 *     return new PdfResponse($this->renderView('invoice.pdf.twig', [...]));
 *     return new PdfResponse($html, 200, [
 *         'filename' => 'facture-2026-041.pdf',
 *         'stream' => ['Attachment' => true],             // downloaded, not shown
 *         'options' => ['isRemoteEnabled' => true],       // Dompdf's options
 *     ]);
 *
 * The PDF is the response's content (getContent(), testable, cacheable):
 * nothing is sent before the kernel sends the response.
 *
 * Remote resources are off by default: an <img>, a stylesheet or a font at
 * an http(s) address is not fetched - the HTML often carries what a visitor
 * wrote, and fetching what it names from the server is a request forgery
 * waiting to happen. Embed images as data: URIs (the `embed_base64` filter),
 * fonts and styles in the page; or turn `isRemoteEnabled` on, knowingly.
 *
 * Three keys of $headers are settings, not headers: `options` (Dompdf's),
 * `stream` (`Attachment`, `compress`), `filename`, and `debug` (true: the
 * HTML itself is answered, to look at it in a browser).
 */
class PdfResponse extends Response
{
    public function __construct(string|Response|null $data = null, int $status = 200, array $headers = [])
    {
        if ($data instanceof Response) {
            $data = $data->getContent();
        }

        $options = array_pop_key("options", $headers) ?? [];
        $stream = array_pop_key("stream", $headers) ?? [];
        $filename = array_pop_key("filename", $headers) ?? 'document.pdf';
        $debug = array_pop_key("debug", $headers) ?? false;
        if ($debug) {
            parent::__construct($data, $status, $headers);

            return;
        }

        $pdf = static::render((string) $data, $options, ['compress' => (bool) ($stream['compress'] ?? true)]);

        parent::__construct($pdf, $status, array_merge([
            'Content-Disposition' => HeaderUtils::makeDisposition(
                ($stream['Attachment'] ?? false) ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
                $filename,
                preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', $filename)
            ),
        ], $headers, ['Content-Type' => 'application/pdf']));
    }

    /**
     * The PDF of an HTML page, as a string.
     *
     * @param array $options Dompdf's options, over ours (remote resources off, fonts not subset)
     * @param array $output  Dompdf's output options (`compress`)
     *
     * @throws \LogicException when dompdf/dompdf is not installed
     */
    public static function render(string $html, array $options = [], array $output = []): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new \LogicException('Base\Response\PdfResponse renders a PDF with Dompdf, which is not installed. Try running "composer require dompdf/dompdf".');
        }

        $dompdf = new Dompdf(static::options($options));

        $dompdf->loadHtml($html);
        $dompdf->render();

        return (string) $dompdf->output($output + ['compress' => true]);
    }

    /**
     * Dompdf's options as PdfResponse sets them, under the caller's: remote
     * resources off, fonts embedded whole.
     */
    public static function options(array $options = []): array
    {
        return array_merge([
            'isFontSubsettingEnabled' => false,
            'isRemoteEnabled' => false,
        ], $options);
    }

    /**
     * The same, as a factory: an HTML source answered as a PDF.
     *
     *     return PdfResponse::fromPdfString($html)->setSharedMaxAge(300);
     */
    public static function fromPdfString(string $source, int $status = 200, array $headers = []): Response
    {
        return new static($source, $status, $headers);
    }

    /** A PDF that already exists as a file. */
    public static function fromPdfFile(string $filename, int $status = 200, array $headers = []): Response
    {
        return new BinaryFileResponse($filename, $status, $headers + ['Content-Type' => 'application/pdf']);
    }
}
