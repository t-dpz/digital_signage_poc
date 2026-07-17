<?php
/**
 * Signage — configuration
 */
return [
    // Change this before going live. Used for the admin login.
    'admin_password' => 'change-me',

    // Absolute or relative path where uploads + database live.
    // Must be writable by the web server, and ideally OUTSIDE the docroot.
    // If kept inside the docroot, the bundled .htaccess blocks direct access (Apache).
    'storage_path' => __DIR__ . '/storage',

    // Wall-clock timezone for schedule resolution and every admin-facing timestamp —
    // set to wherever the screens physically are. PHP otherwise defaults to UTC.
    'timezone' => 'Europe/Brussels',

    // How often players re-fetch their manifest / send a heartbeat (seconds).
    'player_refresh' => 60,

    // Default display duration for images / web pages (seconds).
    'default_duration' => 15,

    // Allowed upload types => extension. Chromium on signage SoCs reliably
    // plays H.264 MP4 and VP9 WebM. Transcode anything else before upload.
    'allowed_mime' => [
        'video/mp4'     => 'mp4',
        'video/webm'    => 'webm',
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/webp'    => 'webp',
        'image/gif'     => 'gif',
    ],

    // Max upload size hint shown in the UI (actual limit = php.ini
    // upload_max_filesize / post_max_size — see README).
    'max_upload_hint' => '2 GB',
];
