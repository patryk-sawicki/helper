<?php

return [
    'images' => [
        'max_width' => 1280,
        'max_height' => 720,
    ],

    'thumbnailSizes' => [
        [
            'width' => 64,
            'height' => 64
        ],
        [
            'width' => 374,
            'height' => null
        ],
        [
            'width' => 1088,
            'height' => null
        ],
    ],

    'main_dir' => 'hidden',

    'middleware' => [
        'auth' => 'admin',
        'permission' => 'file-management',
    ],

    'block_webp_conversion' => env('FILES_SETTINGS_BLOCK_WEBP_CONVERSION', false),

    'forbidden_webp_extensions' => [
        'gif',
    ],

    'fileClass' => null, //Set the class of the file model.

    'store_with_extension' => env('FILES_SETTINGS_STORE_WITH_EXTENSION', false), // Whether to store files with their extensions
];
