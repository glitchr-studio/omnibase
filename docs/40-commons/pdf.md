---
title: PDF responses
order: 52
---

# PDF responses

`Base\Response\PdfResponse` answers an HTML page as a PDF: an invoice, a rent
receipt, a quote. It renders with [Dompdf](https://github.com/dompdf/dompdf),
a **suggested** package - omnibase does not require it:

```
composer require dompdf/dompdf
```

Without it, `PdfResponse` throws a `LogicException` that says so
(`Try running "composer require dompdf/dompdf"`), where PHP used to stop on
`Class "Dompdf\Dompdf" not found`.

```php
use Base\Response\PdfResponse;

return new PdfResponse($this->renderView('invoice.pdf.twig', ['order' => $order]));

return new PdfResponse($html, 200, [
    'filename' => 'facture-2026-041.pdf',
    'stream' => ['Attachment' => true],            // downloaded, not shown in the browser
    'options' => ['defaultPaperSize' => 'a4'],     // Dompdf's options
    'Cache-Control' => 'private, no-store',        // anything else is a header
]);

$pdf = PdfResponse::render($html);                 // the PDF as a string: an e-mail attachment, a file to keep
```

| Key of `$headers` | |
|---|---|
| `filename` | the name in `Content-Disposition` (`document.pdf`) |
| `stream` | `Attachment` (false: shown inline), `compress` (true) |
| `options` | Dompdf's options, over omnibase's defaults (`PdfResponse::options()`) |
| `debug` | true: the HTML itself is answered, to look at it in a browser |

## The PDF is the response's content

The PDF is in `getContent()`, with `Content-Type: application/pdf` and a
`Content-Disposition`; the kernel sends it like any response. (It used to be
sent by Dompdf's `stream()` from the constructor: headers and bytes went out
before the kernel answered, a test could not read the response, a listener
could not touch it.)

## Remote resources are off

`isRemoteEnabled` is **false** by default: an `<img>`, a stylesheet or a font
at an `http(s)` address is not fetched, and is left out of the PDF. The page
often carries what a visitor typed (a name, an address, a message), and
fetching what it names from the server is a request forgery (SSRF).

- images: embed them, `<img src="{{ logo|embed_base64 }}">`;
- styles: in a `<style>` of the page; fonts: Dompdf's own (DejaVu, Helvetica,
  Times) or files installed with Dompdf's font loader;
- a page that names only addresses you wrote yourself may turn it back on:
  `['options' => ['isRemoteEnabled' => true]]`.

A template that linked a web font (`<link href="https://fonts.googleapis.com/…">`)
now prints in the fallback font until it does one of the above.
