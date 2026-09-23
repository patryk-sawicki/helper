<?php

namespace PatrykSawicki\Helper\app\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/*
 * Trait for saving files.
 * */

trait files
{
    /**
     * Add file.
     *
     * @param string $location e.g. order_files
     * @param string $relationName e.g. files
     * @param int|null $max_width
     * @param int|null $max_height
     * @param bool $externalRelation
     * @param bool $forceWebP
     * @param bool $preventResizing
     * @param array $options
     * @param UploadedFile|null $watermark
     * @param int $watermarkOpacity Watermark opacity (0-100)
     */
    public function addFile(
        UploadedFile $file,
        string $location = 'files',
        string $relationName = 'files',
        ?int $max_width = null,
        ?int $max_height = null,
        bool $externalRelation = true,
        bool $forceWebP = true,
        bool $preventResizing = false,
        array $options = [],
        ?UploadedFile $watermark = null,
        int $watermarkOpacity = 70,
        ?Model $fileModel = null
    ): Model {
        $storeWithExtension = config('filesSettings.store_with_extension', false);

        // For S3/B2: Handle visibility parameter for putFileAs() compatibility
        // putFileAs() requires visibility as string, not empty array
        if (strtolower(config('filesystems.default')) == 's3') {
            if (empty($options)) {
                // Use visibility from disk config, or default to 'public' for backward compatibility
                $options = config('filesystems.disks.s3.visibility', 'public');
            }
        }

        $fileName = $file->getClientOriginalName();
        $filePath = '/' . config('filesSettings.main_dir', 'hidden') . '/' . $location . '/' .
                    date('Y') . '/' . date('m') . '/' . date('d') . '/';
        $extension = explode('.', $fileName);
        $extension = strtolower($extension[count($extension) - 1]);

        if ($forceWebP && (config('filesSettings.block_webp_conversion') || in_array(
                    $extension,
                    config('filesSettings.forbidden_webp_extensions')
                ))) {
            $forceWebP = false;
        }

        $input = [
            'name' => $fileName,
            'type' => $extension,
            'mime_type' => $file->getMimeType(),
            'file' => $filePath,
            'relation_type' => $relationName != 'files' ? $relationName : null,
        ];

        if (is_null($fileModel)) {
            $input['slug'] = $this->createSlug(name: $fileName);
        }

        if (is_null($fileModel)) {
            $fileModel = $this->{$relationName}()->create($input);
        } else {
            $fileModel->update($input);
        }

        if (!$externalRelation) {
            $this->update([
                $this->{$relationName}()->getForeignKeyName() => $fileModel->id,
            ]);
        }

        $fileModel->update(['file' => $filePath . $fileModel->id . ($storeWithExtension ? '.' . $extension : ''),]);

        if (explode('/', $file->getMimeType())[0] == 'image' && !str_contains($file->getMimeType(), 'svg')) {
            $max_width ??= config('filesSettings.images.max_width', 1280);
            $max_height ??= config('filesSettings.images.max_height', 720);
            [$w, $h] = getimagesize($file->getRealPath());

            if ((!$preventResizing && ($w > $max_width || $h > $max_height)) || ($forceWebP && $extension != 'webp')) {
                $manager = new ImageManager(new Driver());
                $image = $manager->read($file);

                if (!$preventResizing) {
                    $preventUpscale = config('filesSettings.images.prevent_upscale', false);

                    // Only scale if:
                    // - upscaling is allowed (prevent_upscale = false), OR
                    // - image is actually larger than max dimensions
                    if (!$preventUpscale || $w > $max_width || $h > $max_height) {
                        $image->scale(width: $max_width, height: $max_height);
                    }
                }

                // Apply watermark if provided and not in 'source' relation
                if ($watermark !== null && $relationName !== 'source') {
                    $this->placeWatermarkOnImage($manager, $image, $watermark, $watermarkOpacity);
                }

                $format = null;
                if ($forceWebP) {
                    $format = new WebpEncoder();
                    $fileModel->update([
                        'name' => str_replace('.' . $extension, '.webp', $fileName),
                        'type' => 'webp',
                        'mime_type' => 'image/webp',
                        'file' => $filePath . $fileModel->id . ($storeWithExtension ? '.webp' : ''),
                    ]);
                }

                $fileExtension = $forceWebP ? 'webp' : $extension;
                Storage::put(
                    $filePath . $fileModel->id . ($storeWithExtension ? '.' . $fileExtension : ''),
                    (string)$image->encode($format),
                    $options
                );

                $fileModel->update([
                    'width' => $image->width(),
                    'height' => $image->height(),
                ]);

                return $fileModel;
            } else {
                $fileModel->update([
                    'width' => $w,
                    'height' => $h,
                ]);
            }
        }

        Storage::putFileAs(
            $filePath,
            $file,
            $fileModel->id . ($storeWithExtension ? '.' . $extension : ''),
            $options
        );
        return $fileModel;
    }

    /**
     * Add files.
     *
     * @param array $files
     * @param string $location e.g. order_files
     * @param string $relationName e.g. files
     * @param UploadedFile|null $watermark
     * @param int $watermarkOpacity Watermark opacity (0-100)
     */
    public function addFiles(
        array $files,
        string $location = 'files',
        string $relationName = 'files',
        ?UploadedFile $watermark = null,
        int $watermarkOpacity = 70
    ) {
        foreach ($files as $file) {
            $this->addFile(
                $file,
                $location,
                $relationName,
                null,
                null,
                true,
                true,
                false,
                [],
                $watermark,
                $watermarkOpacity
            );
        }
    }

    /**
     * Place the watermark over the whole image.
     *
     * The watermark is scaled to cover both dimensions and cropped around its centre, so every part of
     * the frame is marked whatever its orientation. Scaling it to the image's width alone (up to 0.7.17)
     * left the bottom of any image taller than the watermark's own aspect ratio unmarked.
     *
     * @param int $opacity Watermark opacity (0-100)
     */
    protected function placeWatermarkOnImage(
        ImageManager $manager,
        ImageInterface $image,
        UploadedFile $watermark,
        int $opacity
    ): void {
        $watermarkImage = $manager->read($watermark);
        $watermarkImage->cover($image->width(), $image->height());

        $image->place($watermarkImage, 'center', 0, 0, $opacity);
    }

    protected function createSlug(string $name): string
    {
        $slug = null;

        do {
            $slug = is_null($slug) ? Str::slug($name) : substr(Str::slug($name), 0, 120) . '-' . Str::random(5);
        } while (DB::table('files')->where('slug', $slug)->exists());

        return $slug;
    }
}
