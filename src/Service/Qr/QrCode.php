<?php

namespace Base\Service\Qr;

use Endroid\QrCode\QrCode as EndroidQrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * A QR code for an address (or any text), drawn by endroid/qr-code: as SVG
 * - sharp at any print size, the default - or PNG. Used by QrSheet for its
 * pages, and directly for a poster or a ticket:
 *
 *     <img src="{{ qr_code(url('app_menu')) }}" alt="">
 */
class QrCode
{
    /** The SVG markup. */
    public function svg(string $data, int $size = 600, int $margin = 0): string
    {
        return (new SvgWriter())->write($this->code($data, $size, $margin))->getString();
    }

    /** A data: URI to put in an <img src>: SVG, or PNG when asked (needs GD). */
    public function dataUri(string $data, int $size = 600, int $margin = 0, string $format = 'svg'): string
    {
        $writer = 'png' === $format ? new PngWriter() : new SvgWriter();

        return $writer->write($this->code($data, $size, $margin))->getDataUri();
    }

    protected function code(string $data, int $size, int $margin): EndroidQrCode
    {
        return new EndroidQrCode(data: $data, size: $size, margin: $margin);
    }
}
