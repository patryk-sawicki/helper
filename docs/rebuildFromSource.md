# rebuildFromSource Method Documentation

## Overview

The `rebuildFromSource` method is designed to rebuild a file and its thumbnails from its source file. This is useful
when you need to regenerate files, apply different processing parameters, or add watermarks to existing files.

## Method Signature

```php
/**
 * Rebuild file and its thumbnails from source file.
 * 
 * @param bool $forceWebP Convert to WebP if possible
 * @param array $options Storage options
 * @param \Illuminate\Http\UploadedFile|null $watermark Watermark file
 * @param int $watermarkOpacity Watermark opacity (0-100)
 * @return bool Success status
 */
public function rebuildFromSource(
    bool $forceWebP = true,
    array $options = [],
    ?\Illuminate\Http\UploadedFile $watermark = null,
    int $watermarkOpacity = 70
): bool
```

## Parameters

- **forceWebP** (bool): Whether to convert images to WebP format if possible. Default is `true`.
- **options** (array): Storage options to pass to the storage driver. Default is an empty array.
- **watermark** (UploadedFile|null): An optional watermark file to apply to the images. Default is `null`.
- **watermarkOpacity** (int): The opacity of the watermark (0-100). Default is `70`.

## Return Value

- **bool**: Returns `true` if the rebuild was successful, `false` otherwise.

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
7. Commits the transaction if successful, or rolls back if an error occurs

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

The method uses a database transaction to ensure data consistency. If any error occurs during the process, the
transaction is rolled back, and the method returns `false`. This prevents partial updates in the database; files already
deleted from storage are not restored (see Notes).

## Notes

- The method will completely remove and regenerate all thumbnails
- WebP conversion is subject to configuration settings and file type compatibility
- Watermarks are applied to the main file and the thumbnails, scaled to cover the whole image and
  centred (since 0.7.18), so rebuilding re-marks files stored before 0.7.18, within the limits
  listed below.
  Exception: a file is marked only when it is resized or converted to WebP. The main file is rebuilt
  with `preventResizing: true`, so a WebP source, or one with the conversion switched off, keeps its
  main file unmarked (see "Known gap" in the 0.7.18 changelog)
- The source is read from the local disk (`storage_path('app')`). On S3 or another remote disk it is
  not found and the method returns `false` without changing anything
- The main file is rebuilt at the source's full resolution, not within `max_width`×`max_height`
- Thumbnails are recreated at the main-file size (`images.max_width`×`images.max_height`), not at the
  sizes in `thumbnailSizes`; one is created for each configured size smaller than the main file
- Old files are deleted before the new ones are written. If the rebuild fails, the database changes
  are rolled back but the deleted files are not restored; the source is kept, so a later successful
  rebuild brings them back
- The method clears the cache for the file to ensure fresh data is returned after rebuilding