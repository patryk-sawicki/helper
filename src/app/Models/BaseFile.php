<?php

namespace PatrykSawicki\Helper\app\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PatrykSawicki\Helper\app\Traits\files;

/**
 * @mixin Builder
 * @property string file
 * @property Collection pageParameterImages
 */
abstract class BaseFile extends Model
{
    use files;
    use SoftDeletes;

    protected $table = 'files';
    public static string $cacheName = 'files';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'mime_type',
        'file',
        'width',
        'height',
        'additional_properties',
        'protected_type',
        'protected_id',
        'model_type',
        'model_id',
        'relation_type',
    ];

    protected function casts(): array
    {
        return [
            'additional_properties' => AsArrayObject::class,
        ];
    }

    protected array $protectedRelations = [
        'pageParameterImages',
    ];

    public function __toString(): string
    {
        return $this->url();
    }

    public function url(): string
    {
        return '/file/' . $this->id;
    }

    public function fullUrl(): string
    {
        return url($this->url());
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function protected(): MorphTo
    {
        return $this->morphTo();
    }

    public function thumbnails(): MorphMany
    {
        return $this->morphMany($this::class, 'model')->where('relation_type', '=', 'thumbnails');
    }

    public function source(): MorphOne
    {
        return $this->morphOne($this::class, 'model')->where('relation_type', '=', 'source');
    }

    public function canBeDeleted(): bool
    {
        foreach ($this->protectedRelations as $relation) {
            if ($this->{$relation}->count() > 0) {
                return false;
            }
        }

        return true;
    }

    public function thumbnail($width, $height = null)
    {
        $thumbnails = $this->thumbnails;

        if (!is_null($width)) {
            $thumbnails = $thumbnails->where('width', '=', $width);
        }

        if (!is_null($height)) {
            $thumbnails = $thumbnails->where('height', '=', $height);
        }

        return $thumbnails->first() ?? $this;
    }

    public function icon($width, $height = null): string
    {
        if (explode('/', $this->mime_type)[0] === 'image') {
            return $this->thumbnail($width, $height);
        }

        return '/img/icons/' . $this->type . '.svg';
    }

    /**
     * Return html code for image with srcset.
     *
     * @param bool $fullUrl If true, use full URLs instead of relative paths
     */
    public function srcset(bool $fullUrl = false): string
    {
        return Cache::tags([self::$cacheName])
            ->remember(
                self::$cacheName . '_srcset_' . $this->id . ($fullUrl ? '_full_url' : ''),
                config('app.cache_default_ttl', 86400),
                function () use ($fullUrl) {
                    $srcset = $fullUrl ? $this->fullUrl() : $this->url();
                    $srcset .= ' ' . $this->width . 'w';

                    foreach ($this->thumbnails as $thumbnail) {
                        $srcset .= ', ';
                        $srcset .= $fullUrl ? $thumbnail->fullUrl() : $thumbnail->url();
                        $srcset .= ' ' . $thumbnail->width . 'w';
                    }

                    return $srcset;
                }
            );
    }

    /**
     * Return image html code.
     *
     */
    public function img(
        ?int $width = null,
        ?int $height = null,
        ?string $class = null,
        ?string $alt = null,
        ?string $style = null,
        string $loading = 'lazy',
        string $fetchPriority = 'auto',
        ?string $title = null,
        bool $source = false, // If true, use source file instead of this file
        bool $fullUrl = false // If true, use full URL instead of relative path
    ): string
    {
        return Cache::tags([self::$cacheName])
            ->remember(
                self::$cacheName . '_img_' . $this->id . '_' . implode('_', func_get_args()),
                config('app.cache_default_ttl', 86400),
                function () use (
                    $width,
                    $height,
                    $class,
                    $alt,
                    $style,
                    $loading,
                    $fetchPriority,
                    $title,
                    $source,
                    $fullUrl
                ) {
                    $srcset = '';
                    if ($source && $this->source) {
                        $url = $fullUrl ? $this->source->fullUrl() : $this->source->url();
                        $width = $this->source->width;
                        $height = $this->source->height;
                    } else {
                        $url = $fullUrl ? $this->fullUrl() : $this->url();
                        $srcset = ' srcset="' . $this->srcset($fullUrl) . '"';
                    }

                    if (is_null($width) && is_null($height)) {
                        $width = 1920;
                    }

                    $thumbnail = $this->thumbnail($width, $height);

                    if (!is_null($class)) {
                        $class = 'class="' . $class . '"';
                    }

                    $sizes = !is_null($width) ? 'sizes="(max-width: ' . $width . 'px) 100vw, ' . $width . 'px"' : '';

                    $alt ??= $this->additional_properties?->alt;
                    $title ??= $this->additional_properties?->title;

                    return '<img src="' . $url . '" ' . $srcset . ' ' . $sizes . ' ' . $class . ' alt="' . $alt . '" title="' . $title . '" style="' . $style . '" loading="' . $loading . '" width="' . $thumbnail->width . '" height="' . $thumbnail->height . '" fetchpriority="' . $fetchPriority . '">';
                }
            );
    }

    /**
     * Return image preload html code.
     *
     */
    public function imgPreload(
        ?int $width = null,
        bool $fullUrl = false
    ): string {
        if (is_null($width)) {
            $width = 1920;
        }

        $sizes = !is_null($width) ? 'imagesizes="(max-width: ' . $width . 'px) 100vw, ' . $width . 'px"' : '';
        $url = $fullUrl ? $this->fullUrl() : $this->url();

        return '<link rel="preload" as="image" href="' . $url . '" imagesrcset="' . $this->srcset(
                $fullUrl
            ) . '" ' . $sizes . '>';
    }

    public function scopeMainFile(Builder $query, $fileClass): Builder
    {
        return $query->whereNull('model_type')->orWhere('model_type', '!=', $fileClass);
    }

    public static function findBySlug(string $slug): ?self
    {
        return Cache::tags([self::$cacheName])
            ->remember(
                self::$cacheName . '_findBySlug_' . $slug,
                config('app.cache_default_ttl', 86400),
                function () use ($slug) {
                    return self::where('slug', '=', $slug)->with('thumbnails')->first();
                }
            );
    }

    public function fullStoragePatch(): string
    {
        return storage_path('app' . $this->file);
    }

    /**
     * Rebuild file and its thumbnails from source file.
     *
     * @param bool $forceWebP Convert to WebP if possible
     * @param array $options Storage options
     * @param UploadedFile|null $watermark Watermark file
     * @param int $watermarkOpacity Watermark opacity (0-100)
     * @return bool Success status
     */
    public function rebuildFromSource(
        string $location = 'uploads',
        string $relationName = 'files',
        bool $forceWebP = true,
        array $options = [],
        ?UploadedFile $watermark = null,
        int $watermarkOpacity = 70
    ): bool {
        // Check if source file exists
        $sourceFile = $this->source()->first();
        if (!$sourceFile) {
            Log::info('Source file not found for file ID: ' . $this->id);
            return false;
        }

        $sourceFilePath = $sourceFile->fullStoragePatch();
        if (!file_exists($sourceFilePath)) {
            Log::info('Source file does not exist: ' . $sourceFilePath);
            return false;
        }

        // Begin transaction to ensure data consistency
        DB::beginTransaction();

        try {
            // Create UploadedFile instance from source file
            $uploadedFile = new UploadedFile(
                $sourceFilePath,
                $sourceFile->name,
                $sourceFile->mime_type,
                0,
                true
            );

            // Delete all thumbnails
            foreach ($this->thumbnails as $thumbnail) {
                // Delete file from storage
                Storage::delete($thumbnail->file);
                // Delete record
                $thumbnail->delete();
            }

            // Remove old file
            Storage::delete($this->file);

            // Process main file using the addFile method from files trait
            $this->model->addFile(
                file: $uploadedFile,
                location: $location,
                relationName: $relationName, // Using 'files' as we're updating the main file
                max_width: null, // No resizing for main file
                max_height: null,
                externalRelation: false, // We want to update this model
                forceWebP: $forceWebP,
                preventResizing: true, // Don't resize the main file
                options: $options,
                watermark: $watermark,
                watermarkOpacity: $watermarkOpacity,
                fileModel: $this
            );

            // Generate thumbnails if this is an image
            if (explode('/', $this->mime_type)[0] == 'image' && !str_contains($this->mime_type, 'svg')) {
                $thumbnailSizes = config('filesSettings.thumbnailSizes', []);
                $thumbnailFiles = [];
                [$fileWidth, $fileHeight] = getimagesize($this->fullStoragePatch());

                // Prepare array of files for thumbnail generation
                foreach ($thumbnailSizes as $thumbnailSize) {
                    if ((is_null($thumbnailSize['width']) || $thumbnailSize['width'] < $fileWidth) &&
                        (is_null($thumbnailSize['height']) || $thumbnailSize['height'] < $fileHeight)) {
                        // Add the file to the array for each valid thumbnail size
                        $thumbnailFiles[] = $uploadedFile;
                    }
                }

                // Use addFiles method from files trait to generate all thumbnails at once
                if (!empty($thumbnailFiles)) {
                    $this->addFiles(
                        files: $thumbnailFiles,
                        location: $location,
                        relationName: 'thumbnails',
                        watermark: $watermark,
                        watermarkOpacity: $watermarkOpacity
                    );
                }
            }

            // Clear cache for this file
            Cache::tags([self::$cacheName])->flush();

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error rebuilding file from source: ' . $e->getMessage());
            return false;
        }
    }
}
