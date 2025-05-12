<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Example usage of the rebuildFromSource method

// Assuming you have a BaseFile implementation
use PatrykSawicki\Helper\app\Models\YourFileModel;

// Replace with your actual file model class

// Load Laravel environment
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Find a file that has a source relationship
$file = YourFileModel::find(1); // Replace with actual file ID

if (!$file) {
    echo "File not found.\n";
    exit(1);
}

// Check if file has a source
if (!$file->source()->exists()) {
    echo "File does not have a source file.\n";
    exit(1);
}

echo "Starting rebuild process for file ID: " . $file->id . "\n";
echo "Original file: " . $file->name . " (" . $file->mime_type . ")\n";
echo "Thumbnail count before: " . $file->thumbnails()->count() . "\n";

// Optional: Create a watermark
$watermark = null;
// If you want to use a watermark, uncomment the following lines
// $watermarkPath = __DIR__ . '/watermark.png'; // Path to your watermark image
// if (file_exists($watermarkPath)) {
//     $watermark = new \Illuminate\Http\UploadedFile(
//         $watermarkPath,
//         'watermark.png',
//         'image/png',
//         null,
//         true
//     );
// }

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
    echo "Rebuild successful!\n";
    echo "Updated file: " . $file->name . " (" . $file->mime_type . ")\n";
    echo "Thumbnail count after: " . $file->thumbnails()->count() . "\n";

    // List thumbnails
    echo "Thumbnails:\n";
    foreach ($file->thumbnails as $thumbnail) {
        echo "- " . $thumbnail->width . "x" . $thumbnail->height . "\n";
    }
} else {
    echo "Rebuild failed.\n";
}