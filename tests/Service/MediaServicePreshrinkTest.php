<?php

namespace Tests\Base\Service;

use Base\Imagine\Filter\Basic\CropFilter;
use Base\Imagine\Filter\Basic\ThumbnailFilter;
use Base\Imagine\Filter\Format\BitmapFilter;
use Base\Service\MediaService;
use Imagine\Image\ImageInterface;
use Imagine\Imagick\Imagine;
use PHPUnit\Framework\TestCase;

/**
 * MediaService::preshrink() - the photo is brought down to twice its future
 * thumbnail (or, when it is less than three times that thumbnail, to its
 * very size) BEFORE strip() converts its colour profile. strip() on the
 * full-size source was 3 of the 4 seconds a 12-megapixel photo took to
 * become a 500px thumbnail, and an album asks for dozens at once.
 *
 * Covers what the request path really passes (the controller wraps the
 * steps in ONE BitmapFilter, autorotation first) and every case that must be
 * left alone.
 */
class MediaServicePreshrinkTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) $this->markTestSkipped('imagick is not loaded');
    }

    private function photo(int $width, int $height, int $frames = 1): ImageInterface
    {
        $imagick = new \Imagick();
        for ($i = 0; $i < $frames; $i++) {
            $imagick->newImage($width, $height, new \ImagickPixel('#3366cc'));
            $imagick->setImageFormat($frames > 1 ? 'gif' : 'jpeg');
        }

        return (new Imagine())->load($imagick->getImagesBlob());
    }

    private function preshrink(ImageInterface $image, array $filters): array
    {
        $service = (new \ReflectionClass(MediaService::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(MediaService::class, 'preshrink'))->invoke($service, $image, $filters);

        return [$image->getSize()->getWidth(), $image->getSize()->getHeight()];
    }

    public function testAPhotoIsBroughtToTwiceTheThumbnailOnItsShorterSide(): void
    {
        // as the controller passes it: one format filter carrying the steps
        $filters = [new BitmapFilter(null, [], [new ThumbnailFilter(500, 500)])];

        $this->assertSame([1333, 1000], $this->preshrink($this->photo(4000, 3000), $filters));
        // portrait: still the SHORTER side that keeps 2x, so an outbound (cropping) thumbnail has enough
        $this->assertSame([1000, 1333], $this->preshrink($this->photo(3000, 4000), $filters));
    }

    public function testABareThumbnailFilterCountsToo(): void
    {
        $this->assertSame([1333, 1000], $this->preshrink($this->photo(4000, 3000), [new ThumbnailFilter(500, 500)]));
    }

    public function testNothingIsDoneWithoutAThumbnail(): void
    {
        $this->assertSame([4000, 3000], $this->preshrink($this->photo(4000, 3000), [new BitmapFilter(null, [], [])]));
    }

    public function testACropFirstKeepsTheSourceGeometry(): void
    {
        // the crop is expressed against the source: shrinking first would move it
        $filters = [new BitmapFilter(null, [], [new CropFilter(0.1, 0.1, 0.5, 0.5), new ThumbnailFilter(500, 500)])];

        $this->assertSame([4000, 3000], $this->preshrink($this->photo(4000, 3000), $filters));
    }

    public function testASourceCloseToTheThumbnailGetsItsSizeAtOnce(): void
    {
        $filters = [new BitmapFilter(null, [], [new ThumbnailFilter(500, 500)])];

        // 1200px on the shorter side is 1.2x the 2x target: no cheap pass, the
        // thumbnail's own resize is done before the colour conversion instead
        $this->assertSame([500, 375], $this->preshrink($this->photo(1600, 1200), $filters));
        // the album's large view of a 12-megapixel photo, which took 5 s
        $this->assertSame([2000, 1500], $this->preshrink($this->photo(4000, 3000), [new BitmapFilter(null, [], [new ThumbnailFilter(2000, 2000)])]));
        $this->assertSame([1500, 2000], $this->preshrink($this->photo(3000, 4000), [new BitmapFilter(null, [], [new ThumbnailFilter(2000, 2000)])]));
    }

    public function testACroppingThumbnailKeepsEnoughToCoverItsBox(): void
    {
        $filters = [new BitmapFilter(null, [], [new ThumbnailFilter(2000, 2000, ImageInterface::THUMBNAIL_OUTBOUND)])];

        // outbound fills the box and crops: the shorter side is the one that reaches it
        $this->assertSame([2667, 2000], $this->preshrink($this->photo(4000, 3000), $filters));
    }

    public function testAPhotoIsNeverEnlarged(): void
    {
        $filters = [new BitmapFilter(null, [], [new ThumbnailFilter(500, 500)])];

        $this->assertSame([400, 300], $this->preshrink($this->photo(400, 300), $filters));
        $this->assertSame([500, 375], $this->preshrink($this->photo(500, 375), $filters));
    }

    public function testASideLeftToTheRatioIsTheFiltersBusiness(): void
    {
        $filters = [new BitmapFilter(null, [], [new ThumbnailFilter(2000, null)])];

        $this->assertSame([4000, 3000], $this->preshrink($this->photo(4000, 3000), $filters));
    }

    public function testAnAnimatedImageIsLeftAlone(): void
    {
        $filters = [new BitmapFilter(null, [], [new ThumbnailFilter(100, 100)])];

        $this->assertSame([800, 600], $this->preshrink($this->photo(800, 600, 3), $filters));
    }
}
