<?php
// Copy this file to config.php and fill in your database details.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'solution_builder',
        'user' => 'ssb_app',
        'pass' => 'change-this-password',
    ],
    // Shown in the browser tab and page headers.
    'app_name' => 'Solution Summary Builder',
    // Minutes of inactivity before a user is signed out.
    'session_minutes' => 480,
    // Largest BOM template upload, in megabytes.
    'max_template_mb' => 10,
    // Set to true only while troubleshooting; it shows PHP errors in the page.
    'debug' => false,
];
