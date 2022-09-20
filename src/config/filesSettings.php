<?php
return [
    'images' => [
        'max_width' => 1280,
        'max_height' => 720,
    ],

    'main_dir' => 'hidden',

    'middleware' => [
        'auth' => 'admin',
        'permission' => 'file-management',
    ],

    'block_webp_conversion' => env('FILES_SETTINGS_BLOCK_WEBP_CONVERSION', false),
];
