# rebuildFromSource Method Documentation

## Overview

The `rebuildFromSource` method is designed to rebuild a file and its thumbnails from its source file. This is useful
when you need to regenerate files, apply different processing parameters, or add watermarks to existing files.

## Method Signature

```php
/**
 * Rebuild file and its thumbnails from source file.
 * 
 * @param string $location Storage location, e.g. uploads
 * @param string $relationName Relation the file belongs to, e.g. files
 * @param bool $forceWebP Convert to WebP if possible
 * @param array $options Storage options
 * @param \Illuminate\Http\UploadedFile|null $watermark Watermark file
 * @param int $watermarkOpacity Watermark opacity (0-100)
 * @return bool Success status
 */
public function rebuildFromSource(
    string $location = 'uploads',
    string $relationName = 'files',
    bool $forceWebP = true,
    array $options = [],
    ?\Illuminate\Http\UploadedFile $watermark = null,
    int $watermarkOpacity = 70
): bool
```

## Parameters

- **location** (string): The location passed to `addFile()`, which becomes part of the stored file's path. Default is
  `'uploads'`.
- **relationName** (string): The owner's relation the main file belongs to, passed to `addFile()`. Default is `'files'`.
- **forceWebP** (bool): Whether to convert the main file to WebP format if possible. Default is `true`. The thumbnails
  are converted whenever the configuration allows it, whatever this parameter says.
- **options** (array): Storage options to pass to the storage driver. Default is an empty array.
- **watermark** (UploadedFile|null): An optional watermark file to apply to the images. Default is `null`.
- **watermarkOpacity** (int): The opacity of the watermark (0-100). Default is `70`.

## Return Value

- **bool**: Returns `true` if the rebuild was successful, and `false` if the source file is missing or an `Exception`
  is thrown. An `Error`, such as the `TypeError` described in Notes, is not caught and propagates to the caller (see
  Error Handling).

## Behavior

The method performs the following operations:

1. Checks if the source file exists
2. Creates a database transaction for data consistency
3. Deletes all existing thumbnails (both files and database records)
4. Processes the main file:
    - Converts to WebP if enabled and applicable
    - Updates file metadata (name, type, mime_type)
    - Updates dimensions for images
5. Generates one new thumbnail for each configured size smaller than the main file (see Notes for the size they
   come out at)
6. Clears the cache for the file
7. Commits the transaction if successful, or rolls back if an `Exception` is thrown (an `Error` is not caught, see
   Error Handling)

## Requirements

- The file must have a source file relationship
- The source file must exist on disk
- Thumbnail sizes should be configured in `config('filesSettings.thumbnailSizes')`

## Example Usage

```php
// Find a file
$file = YourFileModel::find($fileId);

// Create a watermark (optional)
$watermark = null;
if (file_exists($watermarkPath)) {
    $watermark = new \Illuminate\Http\UploadedFile(
        $watermarkPath,
        'watermark.png',
        'image/png',
        null,
        true
    );
}

// Rebuild the file from source
$result = $file->rebuildFromSource(
    location: 'uploads',
    relationName: 'files',
    forceWebP: true,
    options: [],
    watermark: $watermark,
    watermarkOpacity: 70
);

if ($result) {
    // Rebuild successful
} else {
    // Rebuild failed
}
```

## Error Handling

The method uses a database transaction to ensure data consistency. If an `Exception` is thrown during the process, the
transaction is rolled back, the error is logged, and the method returns `false`. This prevents partial updates in the
database; files already deleted from storage are not restored (see Notes).

An `Error` is not caught. The `TypeError` described in Notes propagates to the caller with the transaction still open,
so the caller has to roll it back: note `DB::transactionLevel()` before the call, and in a `catch (\Throwable)` call
`DB::rollBack($level)`, then rethrow or log. Wrapping the call in `DB::transaction()` alone is not enough, as that rolls
back one level and leaves one transaction level open. In a long-running process, such as a queue worker, a
transaction left open can keep every later write on that connection uncommitted. Running out of memory on a large image
(see "Memory on large portraits" in the 0.7.18 changelog) is fatal: nothing can catch it, and the transaction is never
committed.

Rolling back restores the database only, not the disk. When the `TypeError` hits, the main file has already been
written as an unmarked copy of the source at its full resolution. Its path is built from the location, the current
date and the file's id, so a rebuild run on the day the file was stored, with the same location (and, with
`store_with_extension`, the same extension), leaves the restored record pointing to that unmarked copy; any other
rebuild leaves it on the disk as an orphan.

Until the problem is fixed, do not call `rebuildFromSource()` at all, with or without a watermark, when the conversion
is blocked in the configuration (`block_webp_conversion` is set, or the source's extension is listed in
`forbidden_webp_extensions`) and the source is larger than `images.max_width`×`images.max_height`. Do not call it with
`watermark:` either when the main file will not be converted to WebP (see Notes), as the rebuild then takes the
watermark off the main file instead of putting it on.

## Notes

- The method will completely remove and regenerate all thumbnails
- WebP conversion is subject to configuration settings and file type compatibility
- Watermarks are applied to the main file and the thumbnails, scaled to cover the whole image and
  centred (since 0.7.18), so rebuilding re-marks files stored before 0.7.18, within the limits
  listed below.
  Exception: a file is marked only when it is resized or converted to WebP. The main file is rebuilt
  with `preventResizing: true`, so when the source is already WebP, or the conversion is off
  (`forceWebP: false`, `block_webp_conversion`, an extension listed in `forbidden_webp_extensions`),
  the main file is replaced by an unmarked copy of the source at its full resolution, even if it
  carried a watermark before the rebuild. With a WebP source the thumbnails are marked only when
  they have to be scaled down, that is when the source is larger than
  `images.max_width`×`images.max_height` (see "Known gap" in the 0.7.18 changelog). With the
  conversion blocked in the configuration a source that fits is not marked, and a larger one makes
  the rebuild throw a `TypeError` while writing the thumbnails, after the old files are deleted and
  the unmarked main file is written; the method catches only `Exception`, so it neither returns
  `false` nor rolls back its transaction (see Error Handling, and "Known problem" in the 0.7.18
  changelog)
- The source is read from the local disk (`storage_path('app')`). On S3 or another remote disk it is
  not found and the method returns `false` without changing anything
- The main file is rebuilt at the source's full resolution, not within `max_width`×`max_height`
- Thumbnails are recreated at the main-file size (`images.max_width`×`images.max_height`), not at the
  sizes in `thumbnailSizes`; one is created for each configured size smaller than the main file
- Old files are deleted before the new ones are written. If the rebuild fails with an `Exception`, the
  database changes are rolled back but the deleted files are not restored; the source is kept, so a
  later successful rebuild brings them back
- The method clears the cache for the file to ensure fresh data is returned after rebuilding