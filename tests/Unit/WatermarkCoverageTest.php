<?php

namespace PatrykSawicki\Helper\Tests\Unit;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Colors\Rgb\Channels\Green;
use Intervention\Image\Colors\Rgb\Channels\Red;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use PatrykSawicki\Helper\Tests\Fixtures\WatermarkSubject;
use PatrykSawicki\Helper\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;

/**
 * The watermark covers the whole image, whatever its orientation (AA-271).
 *
 * Up to 0.7.17 the watermark was scaled to the image's width and placed at the top-left corner, so on
 * an image taller than the watermark's own aspect ratio - every portrait, given a 3:2 watermark - the
 * bottom of the frame stayed unmarked and could be cropped off clean.
 *
 * The requirement is an attribute, not a skip inside setUp(): PHPUnit still runs tearDown() after a
 * skip raised there, and the base case's tearDown() needs the application setUp() never booted.
 */
#[RequiresPhpExtension('gd')]
class WatermarkCoverageTest extends TestCase
{
    private ImageManager $manager;

    /** @var string[] */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new ImageManager(new Driver());
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public static function frames(): array
    {
        return [
            'landscape 3:2' => [1080, 720],
            'portrait 2:3' => [480, 720],
            'portrait 514x720' => [514, 720],
            'portrait 9:16' => [405, 720],
            'portrait thumbnail' => [40, 60],
            'panorama 2:1' => [1280, 640],
        ];
    }

    #[Test]
    #[DataProvider('frames')]
    public function the_watermark_reaches_every_corner_of_the_image(int $width, int $height): void
    {
        $image = $this->whiteImage($width, $height);

        (new WatermarkSubject())->watermark($this->manager, $image, $this->solidWatermark(), 100);

        foreach ($this->corners($width, $height) as $name => [$x, $y]) {
            $this->assertSame('ff0000', $image->pickColor($x, $y)->toHex(), "{$width}x{$height}, {$name}");
        }
    }

    #[Test]
    #[DataProvider('frames')]
    public function a_translucent_watermark_reaches_every_corner_too(int $width, int $height): void
    {
        // Below 100% opacity GD takes a different path (a merge through an intermediate copy), and 70
        // is the default every caller gets.
        $image = $this->whiteImage($width, $height);

        (new WatermarkSubject())->watermark($this->manager, $image, $this->solidWatermark(), 70);

        foreach ($this->corners($width, $height) as $name => [$x, $y]) {
            $color = $image->pickColor($x, $y);

            // 70% red over white: red stays full, green drops to about 30% of 255.
            $this->assertSame(255, $color->channel(Red::class)->value(), "{$width}x{$height}, {$name}");
            $this->assertEqualsWithDelta(77, $color->channel(Green::class)->value(), 3, "{$width}x{$height}, {$name}");
        }
    }

    #[Test]
    public function the_pattern_keeps_its_scale_on_a_portrait_of_the_same_height(): void
    {
        // A portrait capped at the same height as a landscape shows a narrower slice of the same
        // pattern - not a larger one - so the mark is as dense on both.
        $landscape = $this->whiteImage(1080, 720);
        $portrait = $this->whiteImage(480, 720);

        (new WatermarkSubject())->watermark($this->manager, $landscape, $this->squareWatermark(), 100);
        (new WatermarkSubject())->watermark($this->manager, $portrait, $this->squareWatermark(), 100);

        // 100 px of a 1500x1000 watermark at the 0.72 scale both frames get.
        $this->assertEqualsWithDelta(72, $this->darkRunThroughCentre($landscape), 2);
        $this->assertEqualsWithDelta(72, $this->darkRunThroughCentre($portrait), 2);
    }

    /**
     * The four corners and the centre - the points an unmarked band would leave out.
     *
     * @return array<string, array{int, int}>
     */
    private function corners(int $width, int $height): array
    {
        return [
            'top-left' => [0, 0],
            'top-right' => [$width - 1, 0],
            'bottom-left' => [0, $height - 1],
            'bottom-right' => [$width - 1, $height - 1],
            'centre' => [intdiv($width, 2), intdiv($height, 2)],
        ];
    }

    private function whiteImage(int $width, int $height): ImageInterface
    {
        return $this->manager->create($width, $height)->fill('ffffff');
    }

    /**
     * A 3:2 watermark (1500x1000), opaque everywhere, so any unmarked pixel shows as white.
     */
    private function solidWatermark(): UploadedFile
    {
        return $this->watermarkFile($this->manager->create(1500, 1000)->fill('ff0000'));
    }

    /**
     * 1500x1000 transparent, with a 100x100 black square in the middle to measure the scale by.
     */
    private function squareWatermark(): UploadedFile
    {
        $watermark = $this->manager->create(1500, 1000);
        $watermark->drawRectangle(700, 450, function ($rectangle) {
            $rectangle->size(100, 100);
            $rectangle->background('000000');
        });

        return $this->watermarkFile($watermark);
    }

    private function watermarkFile(ImageInterface $watermark): UploadedFile
    {
        // tempnam() creates the file it names; the PNG goes next to it, so both are removed.
        $base = tempnam(sys_get_temp_dir(), 'helper-watermark-');
        $path = $base . '.png';
        array_push($this->temporaryFiles, $base, $path);
        $watermark->toPng()->save($path);

        return new UploadedFile($path, 'watermark.png', 'image/png', null, true);
    }

    /**
     * Width of the dark run along the image's middle row.
     */
    private function darkRunThroughCentre(ImageInterface $image): int
    {
        $y = intdiv($image->height(), 2);
        $run = 0;

        for ($x = 0; $x < $image->width(); $x++) {
            if ($image->pickColor($x, $y)->channel(Red::class)->value() < 128) {
                $run++;
            }
        }

        return $run;
    }
}
