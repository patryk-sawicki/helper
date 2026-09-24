<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Error;
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
 * where the method no longer catches anything but an Exception.
 */
class RebuildableFile extends BaseFile
{
    public static ?string $lastTemporaryCopy = null;

    public static bool $removeSourceBeforeCopy = false;

    public static bool $throwOnThumbnails = false;

    protected function copySourceToTemporaryFile(BaseFile $sourceFile): ?string
    {
        if (self::$removeSourceBeforeCopy) {
            Storage::delete($sourceFile->file);
        }

        return self::$lastTemporaryCopy = parent::copySourceToTemporaryFile($sourceFile);
    }

    public function addFiles(
        array $files,
        string $location = 'files',
        string $relationName = 'files',
        ?UploadedFile $watermark = null,
        int $watermarkOpacity = 70
    ) {
        if (self::$throwOnThumbnails) {
            throw new Error('Thrown by the fixture while writing the thumbnails.');
        }

        return parent::addFiles($files, $location, $relationName, $watermark, $watermarkOpacity);
    }
}
