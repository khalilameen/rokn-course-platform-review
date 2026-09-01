<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Default Cloud Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Many applications store files both locally and in the cloud. For this
    | reason, you may specify a default "cloud" driver here. This driver
    | will be bound as the Cloud disk implementation in the container.
    |
    */

    'cloud' => env('FILESYSTEM_CLOUD', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => env('SHARED_STORAGE_PATH', storage_path('app')),
        ],

        'public' => [
            'driver' => 'local',
            'root' => env('SHARED_PUBLIC_STORAGE_PATH', storage_path('app/public')),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
        ],

        // Course resources are entitlements, not public assets.
        'module-attachments' => [
            'driver' => 'local',
            'root' => env(
                'MODULE_ATTACHMENT_STORAGE_PATH',
                rtrim((string) env('SHARED_STORAGE_PATH', storage_path('app')), '/\\') . DIRECTORY_SEPARATOR . 'module-attachments'
            ),
            'visibility' => 'private',
        ],

        'course-pdfs' => [
            'driver' => 'local',
            'root' => env(
                'COURSE_PDF_STORAGE_PATH',
                rtrim((string) env('SHARED_STORAGE_PATH', storage_path('app')), '/\\') . DIRECTORY_SEPARATOR . 'course-pdfs'
            ),
            'visibility' => 'private',
        ],

        'security-quarantine' => [
            'driver' => 'local',
            'root' => env('SECURITY_QUARANTINE_PATH', storage_path('app/security-quarantine')),
            'visibility' => 'private',
        ],

        'feedback' => [
            'driver' => 'local',
            'root' => env(
                'FEEDBACK_STORAGE_PATH',
                rtrim((string) env('SHARED_STORAGE_PATH', storage_path('app')), '/\\') . DIRECTORY_SEPARATOR . 'feedback'
            ),
            'visibility' => 'private',
            'shared' => filter_var(env('FEEDBACK_SHARED_STORAGE', false), FILTER_VALIDATE_BOOL),
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
