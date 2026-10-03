<?php

namespace Base\Twig\Extension;

use Base\Service\Embeds;
use Base\Service\MediaService;
use Base\Service\OpeningHours;
use Base\Service\Qr\QrCode;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The shared bricks, in a template (docs/40-commons):
 *
 *     embed_url(url)          what a page can frame of an address (Base\Service\Embeds)
 *     qr_code(data, size)     a QR code as an SVG data: URI (Base\Service\Qr\QrCode)
 *     opening_hours()         Base\Service\OpeningHours
 *     image|picture(w, h)     a stored picture to its URL (an upload through the
 *                             image resolver at that size, a URL or a public path as
 *                             it is, anything else under public/assets/)
 */
class CommonsTwigExtension extends AbstractExtension
{
    public function __construct(
        protected readonly Embeds $embeds,
        protected readonly QrCode $qr,
        protected readonly OpeningHours $hours,
        protected readonly ?Packages $packages = null,
        protected readonly ?MediaService $media = null,
        #[Autowire('%kernel.project_dir%/public')] protected readonly string $publicDir = '',
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('embed_url', fn (?string $url, bool $remote = true): ?array => $this->embeds->resolve($url, $remote)),
            new TwigFunction('qr_code', fn (string $data, int $size = 600): string => $this->qr->dataUri($data, $size)),
            new TwigFunction('opening_hours', fn (): OpeningHours => $this->hours),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('picture', [$this, 'picture']),
        ];
    }

    /** A stored picture to its URL (moved here from the apps' App\Twig\ImageExtension). */
    public function picture(?string $image, ?int $width = null, ?int $height = null): ?string
    {
        if (null === $image || '' === $image) {
            return null;
        }
        if ('' !== $this->publicDir && str_starts_with($image, $this->publicDir.'/')) {
            $thumbnail = $this->media?->thumbnail($image, $width, $height);

            return \is_string($thumbnail) ? $thumbnail : substr($image, \strlen($this->publicDir));
        }
        if (str_starts_with($image, '/') || preg_match('#^https?://#', $image)) {
            return $image;
        }

        return $this->packages ? $this->packages->getUrl('assets/'.$image) : '/assets/'.$image;
    }
}
