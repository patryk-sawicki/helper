<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Error;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PatrykSawicki\Helper\app\Models\BaseFile;

/**
 * The package's own file model, so a test can call rebuildFromSource() on a stored row.
 *
 * It records where rebuildFromSource() copied the source to, so a test can check the copy is gone
 * afterwards. The name tempnam() gives it cannot be matched instead: on Windows it keeps only the
 * first three characters of the prefix.
 *
 * Two switches let a test steer the rebuild: $removeSourceBeforeCopy deletes the source from the
 * disk after rebuildFromSource() has found it and before it is read, so the real copy fails as an
 * unreadable source would; $throwOnThumbnails throws an Error once the main file has been rebuilt,
 * where the method no longer catches anything but an Exception. $temporaryDirectory, when set, is
 * where the source is copied to, so a test can check a failed copy leaves nothing behind.
 * $thumbnailOptions records the storage options each thumbnail is given.
 */
class RebuildableFile extends BaseFile
{
    public static ?string $lastTemporaryCopy = null;

    public static bool $removeSourceBeforeCopy = false;

    public static bool $throwOnThumbnails = false;

    public static ?string $temporaryDirectory = null;

    /** @var list<array> The options each thumbnail was given, in order. */
    public static array $thumbnailOptions = [];

    protected function temporaryDirectory(): string
    {
        return self::$temporaryDirectory ?? parent::temporaryDirectory();
    }

    protected function copySourceToTemporaryFile(BaseFile $sourceFile): ?string
    {
        if (self::$removeSourceBeforeCopy) {
            Storage::delete($sourceFile->file);
        }

        return self::$lastTemporaryCopy = parent::copySourceToTemporaryFile($sourceFile);
    }

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
        if ($relationName === 'thumbnails') {
            if (self::$throwOnThumbnails) {
                throw new Error('Thrown by the fixture while writing the thumbnails.');
            }

            self::$thumbnailOptions[] = $options;
        }

        return parent::addFile(
            $file,
            $location,
            $relationName,
            $max_width,
            $max_height,
            $externalRelation,
            $forceWebP,
            $preventResizing,
            $options,
            $watermark,
            $watermarkOpacity,
            $fileModel
        );
    }
}
