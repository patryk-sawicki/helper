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
                        $class = 'class="' . e($class) . '"';
                    }

                    $sizes = !is_null($width) ? 'sizes="(max-width: ' . $width . 'px) 100vw, ' . $width . 'px"' : '';

                    $alt ??= $this->additional_properties?->alt;
                    $title ??= $this->additional_properties?->title;

                    // DC-1253: escape every ATTRIBUTE VALUE built here. alt/title carry the panel-entered
                    // car name (via the `?? $car->name` fallback in the views) and were the reported XSS
                    // sink; url/style/loading/fetchPriority are escaped too so the class cannot return
                    // through another attribute. $srcset/$sizes/$class are already-formed `attr="..."`
                    // fragments (see above) - escaping those would break the markup; width/height are ints.
                    return '<img src="' . e($url) . '" ' . $srcset . ' ' . $sizes . ' ' . $class . ' alt="' . e($alt) . '" title="' . e($title) . '" style="' . e($style) . '" loading="' . e($loading) . '" width="' . $thumbnail->width . '" height="' . $thumbnail->height . '" fetchpriority="' . e($fetchPriority) . '">';
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

        // DC-1253: escape the href value for parity with img(); imagesrcset/$sizes are generated
        // internally from URLs and int widths, not from user input.
        return '<link rel="preload" as="image" href="' . e($url) . '" imagesrcset="' . $this->srcset(
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
     * The main file comes out within images.max_width x max_height and the thumbnails scaled to fit the
     * sizes in thumbnailSizes, as addUpload() stores them.
     *
     * @param string $location Storage location, e.g. uploads
     * @param string $relationName Relation the file belongs to, e.g. files
     * @param bool $forceWebP Convert to WebP if possible
     * @param array $options Storage options
     * @param UploadedFile|null $watermark Watermark file
     * @param int $watermarkOpacity Watermark opacity (0-100)
     * @return bool Success status
     * @throws Exception When the transaction cannot be opened; nothing has been changed yet
     * @throws \Error Not caught, e.g. for a file without an owner (see docs/rebuildFromSource.md); one
     *                thrown during the rebuild leaves its transaction open
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

        // The source lives on the disk addFile() writes to (the default one, e.g. S3), not
        // necessarily under storage_path('app'), so it is checked and read through Storage.
        if (!$this->sourceExistsOnDisk($sourceFile)) {
            return false;
        }

        // Image processing needs a local path, so the source is copied to a temporary file
        // before anything is deleted: a failed download leaves the stored files as they were.
        $sourceFilePath = $this->copySourceToTemporaryFile($sourceFile);
        if ($sourceFilePath === null) {
            return false;
        }

        try {
            // Nothing has been changed yet, so an exception here ends the rebuild with false, as one
            // thrown during it does, instead of reaching the caller.
            try {
                // Create UploadedFile instance from source file
                $uploadedFile = new UploadedFile(
                    $sourceFilePath,
                    $sourceFile->name,
                    $sourceFile->mime_type,
                    0,
                    true
                );

                // addFile() cannot resize an image without converting it to WebP: it throws the
                // TypeError of 0.7.18, after the old files would have been deleted here. Such a
                // rebuild stops before anything is deleted and before the transaction is opened.
                if (!$this->sourceCanBeResized($uploadedFile, $forceWebP)) {
                    return false;
                }
            } catch (Exception $e) {
                Log::error(
                    'Not rebuilding file ID ' . $this->id . ', checking its source failed: ' . $e->getMessage(),
                    ['exception' => $e, 'file_id' => $this->id]
                );
                return false;
            }

            // Begin transaction to ensure data consistency. It is opened outside the try below, so
            // an exception from opening it propagates and the rollBack() there cannot undo a
            // transaction of the caller's instead.
            DB::beginTransaction();

            try {
                // Delete all thumbnails
                foreach ($this->thumbnails as $thumbnail) {
                    // Delete file from storage
                    Storage::delete($thumbnail->file);
                    // Delete record
                    $thumbnail->delete();
                }
                // The loaded relation still holds the deleted thumbnails. Unset, it is read afresh
                // when next used: the new thumbnails, or the restored ones after a rollback.
                $this->unsetRelation('thumbnails');

                // Remove old file
                Storage::delete($this->file);

                // Process main file using the addFile method from files trait. Like addUpload(), it
                // is scaled to images.max_width x max_height (null takes them from the configuration),
                // so a source larger than that, kept at full resolution, is not served in its place.
                $this->model->addFile(
                    file: $uploadedFile,
                    location: $location,
                    relationName: $relationName, // The owner's relation the main file belongs to
                    max_width: null,
                    max_height: null,
                    externalRelation: false, // We want to update this model
                    forceWebP: $forceWebP,
                    options: $options,
                    watermark: $watermark,
                    watermarkOpacity: $watermarkOpacity,
                    fileModel: $this
                );

                // Generate thumbnails if this is an image
                if (explode('/', $this->mime_type)[0] == 'image' && !str_contains($this->mime_type, 'svg')) {
                    $thumbnailSizes = config('filesSettings.thumbnailSizes', []);
                    // addFile() records the size of what it stored; the stored file itself may be remote.
                    $fileWidth = $this->width;
                    $fileHeight = $this->height;

                    // One thumbnail for each size smaller than the main file, scaled from the source to fit
                    // that size, as addUpload() does. Without max_width and max_height addFile() would fall
                    // back to the main-file limits and make every thumbnail as large as the main file.
                    foreach ($thumbnailSizes as $thumbnailSize) {
                        if ($this->isThumbnailSizeSmaller($thumbnailSize, $fileWidth, $fileHeight)) {
                            $this->addFile(
                                file: $uploadedFile,
                                location: $location,
                                relationName: 'thumbnails',
                                max_width: $thumbnailSize['width'],
                                max_height: $thumbnailSize['height'],
                                options: $options,
                                watermark: $watermark,
                                watermarkOpacity: $watermarkOpacity
                            );
                        }
                    }
                }

                // Clear cache for this file
                Cache::tags([self::$cacheName])->flush();

                DB::commit();
                return true;
            } catch (Exception $e) {
                DB::rollBack();
                Log::error(
                    'Error rebuilding file from source: ' . $e->getMessage(),
                    ['exception' => $e, 'file_id' => $this->id]
                );
                return false;
            }
        } finally {
            // Also runs when an Error, which is not caught above, propagates to the caller.
            @unlink($sourceFilePath);
        }
    }

    /**
     * Check that addFile() can store what the rebuild makes from the source.
     *
     * A file that is not an image, or an SVG, is stored as it is, so it passes without being read.
     *
     * addFile() calls the encoder with null when it resizes an image without converting it to WebP,
     * and intervention/image 3 throws a TypeError for that (the known problem of 0.7.18). The
     * conversion is off for the main file with forceWebP: false, and for the main file and the
     * thumbnails when block_webp_conversion is set or the source's extension is listed in
     * forbidden_webp_extensions. The conditions are the ones addFile() and the thumbnail loop in
     * rebuildFromSource() decide by, so the part of this check about the conversion goes together
     * with the fix of that TypeError in addFile(): once addFile() can store such an image, it only
     * refuses rebuilds that would work.
     *
     * An image whose size cannot be read is refused too, and that part stays after the fix: addFile()
     * reads the size the same way. Converted, such an image failed only after the old files had been
     * deleted; not converted (the conversion off, or a WebP source), it failed that way on PHP 8.5,
     * where reading the size throws, and on earlier versions it was stored as a copy of the source,
     * which it no longer is. The image itself is not decoded here, so one that GD cannot decode still
     * fails in addFile().
     */
    private function sourceCanBeResized(UploadedFile $source, bool $forceWebP): bool
    {
        $mimeType = (string)$source->getMimeType();
        if (explode('/', $mimeType)[0] != 'image' || str_contains($mimeType, 'svg')) {
            return true;
        }

        $extension = explode('.', $source->getClientOriginalName());
        $extension = strtolower($extension[count($extension) - 1]);

        $size = @getimagesize($source->getRealPath());
        if ($size === false) {
            Log::warning(
                'Not rebuilding file ID ' . $this->id . ': the size of its source cannot be read',
                ['file_id' => $this->id, 'mime_type' => $mimeType, 'extension' => $extension]
            );
            return false;
        }
        [$width, $height] = $size;

        $blockedBy = match (true) {
            (bool)config('filesSettings.block_webp_conversion') => 'block_webp_conversion is set',
            in_array($extension, config('filesSettings.forbidden_webp_extensions', [])) =>
                'the extension is listed in forbidden_webp_extensions',
            default => null,
        };

        $maxWidth = config('filesSettings.images.max_width', 1280);
        $maxHeight = config('filesSettings.images.max_height', 720);
        $context = [
            'file_id' => $this->id,
            'width' => $width,
            'height' => $height,
            'extension' => $extension,
            'max_width' => $maxWidth,
            'max_height' => $maxHeight,
        ];

        if (($width > $maxWidth || $height > $maxHeight) && ($blockedBy !== null || !$forceWebP)) {
            Log::warning(
                'Not rebuilding file ID ' . $this->id . ': its source is larger than images.max_width x '
                . 'images.max_height and would be resized without the conversion to WebP ('
                . ($blockedBy ?? 'forceWebP is false') . '), which addFile() cannot store',
                $context
            );
            return false;
        }

        // The thumbnails are converted unless the configuration blocks it. When it does, the main file
        // fits the limits here, so it is stored as it is, at the source's size, and every thumbnail
        // smaller than it is resized. One with neither a width nor a height is resized to the limits,
        // which the source fits, so it is stored as a copy.
        if ($blockedBy === null) {
            return true;
        }

        foreach (config('filesSettings.thumbnailSizes', []) as $thumbnailSize) {
            if ((!is_null($thumbnailSize['width']) || !is_null($thumbnailSize['height']))
                && $this->isThumbnailSizeSmaller($thumbnailSize, $width, $height)) {
                Log::warning(
                    'Not rebuilding file ID ' . $this->id . ': its thumbnails would be resized without the '
                    . 'conversion to WebP (' . $blockedBy . '), which addFile() cannot store',
                    $context
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a size in thumbnailSizes gets a thumbnail next to a main file of the given size.
     */
    private function isThumbnailSizeSmaller(array $thumbnailSize, ?int $width, ?int $height): bool
    {
        return (is_null($thumbnailSize['width']) || $thumbnailSize['width'] < $width) &&
            (is_null($thumbnailSize['height']) || $thumbnailSize['height'] < $height);
    }

    /**
     * Check that the source file is on the storage disk.
     *
     * An error the disk reports, such as a lost connection to S3, counts as a missing source, so the
     * rebuild returns false before changing anything, as it does when the source cannot be read.
     */
    protected function sourceExistsOnDisk(self $sourceFile): bool
    {
        try {
            $exists = Storage::exists($sourceFile->file);
        } catch (Exception $e) {
            Log::warning(
                'Could not check source file ' . $sourceFile->file . ' (' . $e->getMessage() . ')',
                ['exception' => $e]
            );
            return false;
        }

        if (!$exists) {
            Log::info('Source file does not exist: ' . $sourceFile->file);
        }

        return $exists;
    }

    /**
     * Copy the source file from the storage disk to a local temporary file.
     *
     * @return string|null Path of the copy, or null if the source could not be copied
     */
    protected function copySourceToTemporaryFile(self $sourceFile): ?string
    {
        // When the directory cannot be used, tempnam() creates the file in the system's temporary
        // directory instead and raises a notice, which Laravel turns into an ErrorException. The
        // notice is silenced, so the method gets that file's path to clean up, or false.
        $directory = $this->temporaryDirectory();
        $path = @tempnam($directory, 'helper-rebuild-');
        if ($path === false) {
            Log::warning(
                'Could not create a temporary file in ' . $directory . ' for source file: ' . $sourceFile->file
            );
            return null;
        }
        // The notice being silenced, the fallback is logged here, so a directory set on purpose,
        // such as one with more room, is not given up without a trace.
        if (realpath(dirname($path)) !== realpath($directory)) {
            Log::warning(
                'Could not use the temporary directory ' . $directory . ', the source is copied to '
                . dirname($path) . ' instead'
            );
        }

        $input = null;
        $copied = false;
        $error = null;

        try {
            $input = Storage::readStream($sourceFile->file);
            // Given a stream, file_put_contents() copies it in chunks, so the source is never held
            // in memory whole.
            $written = is_resource($input) ? file_put_contents($path, $input) : false;
            // A stream that ends early without an error would pass for a whole copy, so its length
            // is checked against the size the disk reports. An S3 object stored with
            // Content-Encoding: gzip may come back decoded, and then fails this check and is not rebuilt.
            $copied = $written !== false && $written === Storage::size($sourceFile->file);
        } catch (Exception $e) {
            $error = $e;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            // Also runs when an Error propagates, so a failed copy never stays behind.
            if (!$copied) {
                @unlink($path);
            }
        }

        if (!$copied) {
            $reason = $error ? ' (' . $error->getMessage() . ')' : '';
            Log::warning(
                'Could not copy source file to a temporary file: ' . $sourceFile->file . $reason,
                $error ? ['exception' => $error] : []
            );
            return null;
        }

        return $path;
    }

    /**
     * Directory the source is copied to for the rebuild.
     *
     * A file model can override it, e.g. with a directory that has more room. One that cannot be used
     * is not an error: the copy is then made in the system's temporary directory and a warning is logged.
     */
    protected function temporaryDirectory(): string
    {
        return sys_get_temp_dir();
    }
}
