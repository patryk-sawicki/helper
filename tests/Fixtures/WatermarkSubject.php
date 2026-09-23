<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use PatrykSawicki\Helper\app\Traits\files;

/**
 * The files trait's watermark step, reachable from a test.
 *
 * addFile() needs a model, its relations and a disk; the composition itself needs none of them, so
 * the test drives the same protected method addFile() calls, on an image built in memory.
 */
class WatermarkSubject
{
    use files;

    public function watermark(ImageManager $manager, ImageInterface $image, UploadedFile $watermark, int $opacity): void
    {
        $this->placeWatermarkOnImage($manager, $image, $watermark, $opacity);
    }
}
