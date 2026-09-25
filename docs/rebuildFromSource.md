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
 * @throws \Error Not caught, e.g. the TypeError described in Notes; its transaction stays open
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
  uploaded with: when the default disk is named `s3`, an empty array stores the files with that disk's `visibility`,
  or `public` when it sets none, whatever visibility they had before.
- **watermark** (UploadedFile|null): An optional watermark file to apply to the images. Default is `null`.
- **watermarkOpacity** (int): The opacity of the watermark (0-100). Default is `70`.

## Return Value

- **bool**: Returns `true` if the rebuild was successful, and `false` if the file has no source record, the source
  file is missing from the disk, the disk fails while checking or reading it, the temporary copy cannot be created,
  or an `Exception` is thrown during the rebuild. In every case but the last nothing has been changed yet; each is
  logged. An `Error`, such as the `TypeError` described in Notes, is not caught and propagates to the caller, as does
  an exception thrown while the transaction is being opened (see Error Handling).

## Behavior

The method performs the following operations:

1. Checks if the source file exists on the storage disk, and copies it to a temporary local file (removed when the
   method returns or throws; a fatal error, such as running out of memory, leaves it in the temporary directory,
   `sys_get_temp_dir()` by default, see Requirements)
2. Creates a database transaction for data consistency (inside a transaction of the caller's, only a savepoint; see
   Error Handling)
3. Deletes all existing thumbnails (both files and database records)
4. Processes the main file the way `addUpload()` does:
    - Scales it to fit within `images.max_width`×`images.max_height` (a smaller source is scaled up to them when it
      is converted to WebP, unless `images.prevent_upscale` is set)
    - Converts to WebP if enabled and applicable
    - Updates file metadata (name, type, mime_type)
    - Updates dimensions for images
5. Generates one new thumbnail for each size in `thumbnailSizes` smaller than the rebuilt main file, cut from the
   source at that size, as `addUpload()` does
6. Clears the cache for the file
7. Commits the transaction if successful, or rolls back if an `Exception` is thrown (an `Error` is not caught, see
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

Do not call the method inside a transaction of your own, such as one around a loop over a gallery. The method's own
commit then commits nothing (the outer transaction decides), and the old files of each rebuilt file are deleted at
once. When the outer transaction is rolled back afterwards, by an exception after the loop, a timeout, a fatal error
or a lost connection, the records of **every** file rebuilt in it go back to their old files, which no longer exist,
and the new files stay on the disk as orphans. The sources are kept, so rebuilding those files again brings them back.
Rebuild each file in a queued job of its own, outside any transaction.

An `Error` is not caught. The `TypeError` described in Notes propagates to the caller with the transaction still open,
so the caller has to roll it back: note `DB::transactionLevel()` before the call (which is made outside any
transaction, as above), and in a `catch (\Throwable)` call `DB::rollBack($level)`, then rethrow or log. In a
long-running process, such as a queue worker, a transaction left open can keep every later write on that connection
uncommitted. Running out of memory on a large image (see "Memory on large portraits" in the 0.7.18 changelog) is
fatal: nothing can catch it, and the transaction is never committed.

Rolling back restores the database only, not the disk. When the `TypeError` hits the main file, which it does for a
source larger than `images.max_width`×`images.max_height`, the main file has not been written, so the restored
record points to a file that is gone, and so do the thumbnails' records. When it hits a thumbnail, the main file has
already been written as an unmarked copy of the source, which fits within the limits. Its path is built from the
location, the current date and the file's id, so a rebuild run on the day the file was stored, with the same location
(and, with `store_with_extension`, the same extension), leaves the restored record pointing to that unmarked copy; any
other rebuild leaves it on the disk as an orphan.

Until the problem is fixed, do not call `rebuildFromSource()` at all, with or without a watermark, when the conversion
is blocked in the configuration (`block_webp_conversion` is set, or the source's extension is listed in
`forbidden_webp_extensions`, which lists `gif` by default), whatever the source's size, nor with `forceWebP: false`
when the source is larger than `images.max_width`×`images.max_height`. Do not call it with `watermark:` either when
the source fits within those limits and is WebP, or is not converted (see Notes), as the rebuild then takes the
watermark off the main file instead of putting it on.

## Notes

- The method will completely remove and regenerate all thumbnails
- WebP conversion is subject to configuration settings and file type compatibility
- Watermarks are applied to the main file and the thumbnails, scaled to cover the whole image and
  centred (since 0.7.18), so rebuilding re-marks files stored before 0.7.18, within the limits
  listed below.
  Exception: a file is marked only when it is resized or converted to WebP. Since 0.7.20 the main
  file is resized, and so marked, whenever the source is larger than
  `images.max_width`×`images.max_height`. A source that fits and is already WebP, or is not
  converted (`forceWebP: false`, `block_webp_conversion`, an extension listed in
  `forbidden_webp_extensions`, such as `gif`), becomes the main file as it is, unmarked, even if
  the main file carried a watermark before the rebuild (see "Known gap" in the 0.7.18 changelog).
  The thumbnails are resized from the source to their sizes, or converted, so they are marked
  whenever they are written. The one exception is a size in `thumbnailSizes` with neither a width
  nor a height: it is cut at the main-file limits, so from a WebP source that fits them it is
  stored as a copy, as the main file is. With the conversion off, resizing throws a `TypeError`:
  with `forceWebP: false` on the main file of a source larger than the limits, and with the
  conversion blocked in the configuration on the main file or the first thumbnail of nearly any
  image, as in `addUpload()`, in both cases after the old files are deleted; the method catches
  only `Exception`, so it neither returns `false` nor rolls back its transaction (see Error
  Handling, and "Known problem" in the 0.7.18 changelog)
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
- The main file is rebuilt within `images.max_width`×`images.max_height` and the thumbnails at the sizes in
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