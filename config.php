<?php
// Central Configuration File

return [
    // --- Database --- 
    'db_path' => __DIR__ . '/database.sqlite',

    // --- Admin Credentials ---
    'admin_username' => 'admin',
    'admin_password_hash' => '$2y$10$.lWLpmOjLY6pun9c62z9iOo9/in5RA/UQKTMd513rR2QhlB34Vvu2', // Default: "password". CHANGE THIS!

    // --- Image & Thumbnail Settings ---
    'image_sources' => [
        'main' => [
            'path' => realpath(__DIR__ . '/images'), // Use realpath here for consistency
            // 'name' => 'Thư mục chính' 
        ],
        'extra_drive' => [
            'path' => 'G:\\2020',
            // 'name' => 'Ổ G 2020'
        ],
        'guu_ssd' => [
            'path' => 'D:\\2020',
            // 'name' => 'SSD Guu 2020'
        ]
    ],
    'cache_thumb_root' => __DIR__ . '/cache/thumbnails', // Use __DIR__ here, db_connect will resolve realpath
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'],
    'thumbnail_sizes' => [150, 750],

    // --- API Settings ---
    'pagination_limit' => 100, // Default limit for list_files pagination (API currently uses 100, JS uses 50)
    'zip_max_execution_time' => 300, // Max execution time for zip creation (seconds)
    'zip_memory_limit' => '4096M',   // Memory limit for zip creation

    // --- Cron/Log Settings ---
    'log_max_age_days' => 30,
    'log_max_size_bytes' => 50 * 1024 * 1024, // 50 MB

    // --- Application Settings ---
    'app_title' => 'Thư viện Ảnh - Guustudio',
];

?> 