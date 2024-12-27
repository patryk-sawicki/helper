<?php

namespace PatrykSawicki\Helper\app\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use PatrykSawicki\Helper\app\Traits\files;

trait uploads
{
    use files;

    /**
     * Add image.
     *
     * @param string $location e.g. order_files
     * @param string $relationName e.g. files
     * @param int|null $max_width
     * @param int|null $max_height
     */
    public function addUpload(
        UploadedFile $uploadedFile,
        string $location = 'uploads',
        string $relationName = 'files',
        array $categories = [],
        int $max_width = null,
        int $max_height = null,
        bool $externalRelation = true,
        array $options = []
    ): Model {
        if (explode('/', $uploadedFile->getMimeType())[0] != 'image' ||
            str_contains($uploadedFile->getMimeType(), 'svg')) {
            $file = $this->addFile(
                file: $uploadedFile,
                location: $location,
                relationName: $relationName,
                max_width: $max_width,
                max_height: $max_height,
                externalRelation: $externalRelation,
                options: $options
            );

            if (!empty($categories)) {
                $file->categories()->sync($categories);
            }

            return $file;
        }

        $file = $this->addFile(
            file: $uploadedFile,
            location: $location,
            relationName: $relationName,
            max_width: $max_width,
            max_height: $max_height,
            externalRelation: $externalRelation,
            options: $options
        );

        /*Save original version of image.*/
        $file->addFile(
            file: $uploadedFile,
            location: $location,
            relationName: 'source',
            forceWebP: false,
            preventResizing: true,
            options: $options
        );

        if (!empty($categories)) {
            $file->categories()->sync($categories);
        }

        foreach (config('filesSettings.thumbnailSizes', []) as $thumbnailSize) {
            if ($file->width > $thumbnailSize['width'] && $file->height > $thumbnailSize['height']) {
                $file->addFile(
                    file: $uploadedFile,
                    location: $location,
                    relationName: 'thumbnails',
                    max_width: $thumbnailSize['width'],
                    max_height: $thumbnailSize['height'],
                    options: $options
                );
            }
        }

        return $file;
    }

    /**
     * Add uploads.
     *
     * @param string $location e.g. order_files
     * @param string $relationName e.g. files
     */
    public function addUploads(
        array $files,
        string $location = 'uploads',
        string $relationName = 'files',
        array $categories = [],
        array $options = []
    ): void {
        foreach ($files as $file) {
            $this->addUpload($file, $location, $relationName, $categories, options: $options);
        }
    }

    /**
     * Regenerate thumbnails.
     */
    public function regenerateThumbnails(string $filesClass, string $location = 'uploads'): void
    {
        $thumbnailSizes = config('filesSettings.thumbnailSizes', []);
        $thumbnailSizes = collect($thumbnailSizes);

        /*Remove old, not currently needed thumbnails.*/
        $thumbnails = $filesClass::where('model_type', $filesClass)->get();
        foreach ($thumbnails as $thumbnail) {
            $thumbnailSize = $thumbnailSizes->where('width', $thumbnail->width)->where(
                'height',
                $thumbnail->height
            )->first();
            $thumbnailSize ??= $thumbnailSizes->where('width', $thumbnail->width)->first();
            $thumbnailSize ??= $thumbnailSizes->where('height', $thumbnail->height)->first();

            if (!$thumbnailSize) {
                $thumbnail->delete();
            }
        }

        $files = $filesClass::mainFile(fileClass: $filesClass)
            ->where('mime_type', 'like', '%image%')->where('mime_type', 'not like', '%svg%')->get();

        foreach ($files as $file) {
            $filePath = storage_path('app' . $file->file);

            /*Check if file exist.*/
            if (!file_exists($filePath)) {
                continue;
            }

            $uploadedFile = new UploadedFile(
                $filePath,
                $file->name,
                $file->mime_type,
                0,
                true
            );

            [$fileWidth, $fileHeight] = getimagesize($filePath);

            foreach ($thumbnailSizes as $thumbnailSize) {
                if (($file->thumbnails->count() == 0 || ($file->thumbnails->where(
                                'width',
                                $thumbnailSize['width']
                            )->count() == 0 && $file->thumbnails->where('height',
                                $thumbnailSize['height'])->count() == 0)) && ((is_null(
                                $thumbnailSize['width']
                            ) || $thumbnailSize['width'] < $fileWidth) && (is_null(
                                $thumbnailSize['height']
                            ) || $thumbnailSize['height'] < $fileHeight))) {
                    $file->addFile(
                        file: $uploadedFile,
                        location: $location,
                        relationName: 'thumbnails',
                        max_width: $thumbnailSize['width'],
                        max_height: $thumbnailSize['height']
                    );
                }
            }
        }
    }

    public function rebuildFiles(string $filesClass, bool $forceWebP = true, array $options = []): void
    {
        if (strtolower(config('filesystems.default')) == 's3') {
            $options = [];
        }

        if ($forceWebP && config('filesSettings.block_webp_conversion')) {
            $forceWebP = false;
        }

        $files = $filesClass::mainFile(fileClass: $filesClass)->whereNull('mime_type')->orWhere(
            'mime_type',
            '!=',
            'image/webp'
        )->get();

        foreach ($files as $file) {
            $filePath = storage_path('app' . $file->file);

            /*Check if file exist.*/
            if (!file_exists($filePath)) {
                continue;
            }

            DB::beginTransaction();

            $extension = $file->type;

            if ($forceWebP && in_array($extension, config('filesSettings.forbidden_webp_extensions'))) {
                $forceWebP = false;
            }

            $uploadedFile = new UploadedFile(
                $filePath,
                $file->name,
                $file->mime_type,
                0,
                true
            );

            if ($forceWebP && $extension != 'webp' &&
                (explode('/', $uploadedFile->getMimeType())[0] == 'image' && !str_contains(
                        $uploadedFile->getMimeType(),
                        'svg'
                    ))) {
                $format = new WebpEncoder();
                $file->update([
                    'name' => str_replace('.' . $extension, '.webp', $file->name),
                    'type' => 'webp',
                    'mime_type' => 'image/webp',
                ]);

                $manager = new ImageManager(new Driver());
                $image = $manager->read($uploadedFile);

                /*Remove old file*/
                Storage::delete($file->file);

                Storage::put(
                    $file->file,
                    (string)$image->encode($format),
                    $options
                );
            } elseif (empty($file->mime_type)) {
                $file->update([
                    'mime_type' => $uploadedFile->getMimeType(),
                ]);
            }

            DB::commit();
        }

        $this->regenerateThumbnails(filesClass: $filesClass);
    }
}
