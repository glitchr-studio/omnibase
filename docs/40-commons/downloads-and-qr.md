---
title: Downloads and QR codes
order: 44
---

# Signed download links

`Base\Service\DownloadLinks` signs a route's absolute URL with Symfony's
`UriSigner` for `base.download_links.ttl` seconds (or the TTL given). Who may
download is checked when the link is handed out; the download action only
checks the signature.

```php
return $this->redirect($links->sign('forge_download_file', ['id' => $artifact->getId(), 'filename' => $artifact->getFilename(), 'license' => $license->getId()], 900));

#[Route('/telechargements/fichier/{id}/{filename}', name: 'forge_download_file')]
public function file(Request $request, ...): Response
{
    if (!$this->links->verify($request)) { throw $this->createAccessDeniedException(); }
    ...
}
```

`omnibase/forge` (artifacts) and `omnibase/classroom` (resources) use it.

# QR codes

`Base\Service\Qr\QrCode` draws a code with `endroid/qr-code`: `svg($data)`,
`dataUri($data, $size, $margin, 'svg'|'png')`. In Twig: `qr_code(url)`.

`Base\Service\Qr\QrSheet` lays codes out on A4 pages to print at 100 %:

| Layout | Labels | Size (mm) |
|---|---|---|
| `a4` | 1 per page | 210 × 297 |
| `L7160` | 21 (3 × 7) | 63.5 × 38.1 |
| `L7163` | 14 (2 × 7) | 99.1 × 38.1 |
| `L7121` | 20 (4 × 5) | 45 × 45 (square, made for QR codes) |

```php
#[Route('/admin/tables/qr')]
public function sheet(QrSheet $sheet): Response
{
    $items = array_map(fn (Table $t) => ['data' => $this->generateUrl('app_table', ['id' => $t->getId()], UrlGeneratorInterface::ABSOLUTE_URL), 'label' => $t->getName()], $tables);

    return new Response($sheet->render($items, 'L7160', 'Tables', skip: 2)); // the first two labels already used
}
```

`pages()` gives the positions without rendering, for a template of your own.
Check a layout once with a test print: label sheets of other makes differ by
a millimetre or two.
