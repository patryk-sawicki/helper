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
 * @throws \Exception When the transaction cannot be opened; nothing has been changed yet
 * @throws \Error Not caught, e.g. for a file without an owner (see Requirements); one thrown during the rebuild
 *                leaves its transaction open
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
- **options** (array): Storage options to pass to the storage driver, for the main file and the thumbnails (before
  0.7.20 the thumbnails were stored with the default ones). Default is an empty array. Pass the options the file was
  uploaded with: when the default disk is named `s3`, an empty array stores the files with the `visibility` that disk
  sets, whatever visibility they had before: `public` when its configuration has no `visibility` key, and none at all
  when the key is `null`, which leaves them with the bucket's default ACL.
- **watermark** (UploadedFile|null): An optional watermark file to apply to the images. Default is `null`.
- **watermarkOpacity** (int): The opacity of the watermark (0-100). Default is `70`.

## Return Value

- **bool**: Returns `true` if the rebuild was successful, and `false` if the file has no source record, the source
  file is missing from the disk, the disk fails while checking or reading it, the temporary copy cannot be created,
  the image would have to be resized without the conversion to WebP or its size cannot be read (see Error Handling),
  or an `Exception` is thrown while the source is checked or during the rebuild. In every case but the last, an
  exception during the rebuild, nothing has been changed yet; each is logged. An `Error`, such as
  the one for a file without an owner (see Requirements), is not caught and propagates to the caller, as does an
  exception thrown while the transaction is being opened (see Error Handling).

## Behavior

The method performs the following operations:

1. Checks if the source file exists on the storage disk, and copies it to a temporary local file (removed when the
   method returns or throws; a fatal error, such as running out of memory, leaves it in the temporary directory,
   `sys_get_temp_dir()` by default, see Requirements)
2. Checks that the source's size can be read and that no image would have to be resized without the conversion to
   WebP, which `addFile()` cannot store, and returns `false` otherwise (see Error Handling)
3. Creates a database transaction for data consistency (inside a transaction of the caller's, only a savepoint; see
   Error Handling)
4. Deletes all existing thumbnails (both files and database records)
5. Processes the main file the way `addUpload()` does:
    - Scales it to fit within `images.max_width`×`images.max_height` (a smaller source is scaled up to them when it
      is converted to WebP, unless `images.prevent_upscale` is set)
    - Converts to WebP if enabled and applicable
    - Updates file metadata (name, type, mime_type)
    - Updates dimensions for images
6. Generates one new thumbnail for each size in `thumbnailSizes` smaller than the rebuilt main file, scaled from the
   source to fit that size, as `addUpload()` does
7. Clears the cache for the file
8. Commits the transaction if successful, or rolls back if an `Exception` is thrown (an `Error` is not caught, see
   Error Handling)

## Requirements

- The file must have a source file relationship
- The file must belong to its owner through the `model` morph, and the owner must be found (not soft-deleted): the
  main file is rebuilt through `$this->model->addFile()`, so without an owner the method throws an `Error` after the
  old files have been deleted, with its transaction still open
- The owner must drop silently the key that `addFile(externalRelation: false)` writes onto it. For the default `files`
  relation, or any other `morphMany` or `hasMany` one, that key is on the file's side (`model_id`), a column the owner
  does not have. A `$fillable` that leaves it out, or a `$guarded` that lists some columns, drops it. With
  `$guarded = []` or after `Model::unguard()` it reaches the query and fails; with neither `$fillable` nor `$guarded`
  set, or with `Model::preventSilentlyDiscardingAttributes()` (part of `Model::shouldBeStrict()`) on, a
  `MassAssignmentException` is thrown. Either way the method returns `false` after the old files have been deleted,
  and the records it rolls back point to them
- The source file must exist on the default storage disk, the one `addFile()` writes to (local, S3 or another)
- Room in `sys_get_temp_dir()` for a copy of the source (or in the directory `temporaryDirectory()` returns, when a
  file model overrides it; if that directory cannot be used, the copy is made in `sys_get_temp_dir()` and a warning
  is logged)
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
database; files already deleted from storage are not restored (see Notes). This holds only when the method runs
outside any transaction: inside one of the caller's it gets a savepoint, and what happens to the records is decided by
the caller's transaction (see below).

The source is checked and copied before the transaction starts. An exception the disk throws there, such as a lost
connection to S3, is logged and the method returns `false` without having changed anything or opened a transaction.
An exception thrown while the transaction is being opened is not caught: it propagates to the caller, nothing has been
changed, and a transaction of the caller's stays open.

The image is checked before the transaction starts too. `addFile()` cannot resize an image without converting it to
WebP: it throws a `TypeError` (see "Known problem" in the 0.7.18 changelog). The conversion is off for the main file
with `forceWebP: false`, and for the main file and the thumbnails when it is blocked in the configuration
(`block_webp_conversion` is set, or the source's extension is listed in `forbidden_webp_extensions`, which lists `gif`
by default). When the rebuild would have to resize that way the main file of a source larger than
`images.max_width`×`images.max_height`, or, with the conversion blocked, any thumbnail (a size in `thumbnailSizes`
with neither a width nor a height aside), the method logs a warning and returns `false` without having changed
anything or opened a transaction (since 0.7.20; 0.7.19 rebuilt the others, and threw the `TypeError`, after deleting
the old files, only for a source larger than the limits with the conversion blocked). A source within the limits with
`forceWebP: false` is still rebuilt when the configuration does not block the conversion: the main file is stored as
it is, and the thumbnails are converted. An image whose size `getimagesize()` cannot read, which is how `addFile()`
reads it too, is refused as well (since 0.7.20; when it was not converted, with the conversion off or as a WebP
source, 0.7.19 stored it as a copy of the source on PHP before 8.5), and so is an `Exception` thrown during these
checks: it is logged, and the method returns `false`. The image itself is not decoded then, so one that
`getimagesize()` reads but GD cannot decode, such as a TIFF, still fails in `addFile()` after the old files have been
deleted, and the method returns `false` with them gone (see Notes).

Do not call the method inside a transaction of your own, such as one around a loop over a gallery. The method's own
commit then commits nothing (the outer transaction decides), and the old files of each rebuilt file are deleted at
once. When the outer transaction is rolled back afterwards, by an exception after the loop, a timeout, a fatal error
or a lost connection, the records of **every** file rebuilt in it go back to their old files, which no longer exist,
and the new files stay on the disk as orphans. The sources are kept, so rebuilding those files again brings them back.
Rebuild each file in a queued job of its own, outside any transaction.

An `Error`, such as the one for a file without an owner (see Requirements), is not caught. Thrown during the rebuild,
it propagates to the caller with the transaction still open, so the caller has to roll it back: note
`DB::transactionLevel()` before the call (which is made outside any transaction, as above), and in a
`catch (\Throwable)` call `DB::rollBack($level)`, then rethrow or log. In a long-running process, such as a queue
worker, a transaction left open can keep every later write on that connection uncommitted. Running out of memory on a
large image (see "Memory on large portraits" in the 0.7.18 changelog) is fatal: nothing can catch it, and the
transaction is never committed.

Rolling back restores the database only, not the disk: the restored records point to the paths of the old files,
which are deleted by then, and whatever the rebuild had written stays on the disk. The new main file's path is built
from the location, the current date and the file's id, so once a rebuild run on the day the file was stored, with the
same location (and, with `store_with_extension`, the same extension), has written the main file, the restored record
points to that new file instead.

Do not call `rebuildFromSource()` with `watermark:` when the source fits within
`images.max_width`×`images.max_height` and is WebP, or is not converted (see Notes), as the rebuild then takes the
watermark off the main file instead of putting it on.

## Notes

- The method will completely remove and regenerate all thumbnails
- WebP conversion is subject to configuration settings and file type compatibility
- Watermarks are applied to the main file and the thumbnails, scaled to cover the whole image and
  centred (since 0.7.18), so rebuilding re-marks files stored before 0.7.18, within the limits
  listed below.
  Exception: a file is marked only when it is resized or converted to WebP. Since 0.7.20 the main
  file is resized, and so marked, whenever the source is larger than
  `images.max_width`×`images.max_height`; with the conversion off such a source is not rebuilt at
  all (see below). A source that fits and is already WebP, or is not
  converted (`forceWebP: false`, `block_webp_conversion`, an extension listed in
  `forbidden_webp_extensions`, such as `gif`), becomes the main file as it is, unmarked, even if
  the main file carried a watermark before the rebuild (see "Known gap" in the 0.7.18 changelog),
  whenever the rebuild goes on: with the conversion blocked in the configuration it goes on only
  when no thumbnail has to be resized, see below.
  The thumbnails are resized from the source to their sizes, or converted, so they are marked
  whenever they are written. The one exception is a size in `thumbnailSizes` with neither a width
  nor a height: it is scaled to fit the main-file limits, so from a source that fits them it is
  stored as an unmarked copy, as the main file is, when the source is WebP or the conversion is
  blocked in the configuration, whatever the source's format. A rebuild that would have to resize
  without the conversion, the main file of a source larger than the limits or, with the conversion
  blocked in the configuration, a thumbnail of nearly any image, returns `false` before changing
  anything, as `addFile()` would throw a `TypeError` there (see Error Handling, and "Known problem"
  in the 0.7.18 changelog)
- The source is read through `Storage` from the default disk, so the rebuild works the same on S3 or
  another remote disk as on a local one (since 0.7.19; before, it looked under `storage_path('app')`
  only and returned `false` elsewhere). It is downloaded to a temporary file before anything is
  deleted, and the copy's length has to match the size the disk reports, so a source that cannot
  be read, or comes back shorter, leaves the stored files as they were. An S3 object stored with
  `Content-Encoding: gzip` may come back decoded, at another length, and then fails that check and
  is not rebuilt
- Each call downloads the source once, then decodes it at its full resolution for the main file and again for each
  thumbnail, as `addUpload()` does, and scales, marks, encodes and uploads each of them. Rebuild many files, such as a
  whole gallery, in a queued job, one file per job, rather than in one HTTP request, where `max_execution_time` or a
  proxy timeout can leave them partly rebuilt, and not inside a transaction, whose rollback leaves every file rebuilt
  in it pointing to deleted files (see Error Handling)
- The main file is rebuilt within `images.max_width`×`images.max_height` and the thumbnails scaled to fit the sizes in
  `thumbnailSizes`, as `addUpload()` stores them (since 0.7.20; before, the main file came out at the source's full
  resolution, so the original was served in place of the preview, and every thumbnail at the main-file limits). The
  limits are read from the configuration when the method runs: a file uploaded with its own `max_width` and
  `max_height` passed to `addUpload()` comes out at the configured ones, and raising the limits after files were
  stored gives their rebuilt main files, and the thumbnails that fit in them, the new limits. Do not rebuild files
  uploaded with smaller limits than the configured ones until the method accepts limits of its own: their main file
  comes out larger than it was, and one whose source fits within the configured limits shows the source in full
- Old files are deleted before the new ones are written. If the rebuild fails with an `Exception`, the
  database changes are rolled back but the deleted files are not restored; the source is kept, so a
  later successful rebuild brings them back. The same holds for a transaction of the caller's rolled
  back after the method has returned `true`, and then for every file rebuilt in it
- The method clears the cache for the file to ensure fresh data is returned after rebuilding