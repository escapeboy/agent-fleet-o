<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'boruna_bundles' => [
            'driver' => env('BORUNA_STORAGE_DISK', 'local'),
            'root' => storage_path('app/boruna_bundles'),
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Tenant uploads — private. Block-all-public bucket; objects reached
        // only via SecureFileController stream or presigned temporaryUrl().
        's3_private' => [
            'driver' => 's3',
            'key' => env('AWS_UPLOADS_KEY'),
            'secret' => env('AWS_UPLOADS_SECRET'),
            'region' => env('AWS_DEFAULT_REGION', 'eu-central-1'),
            'bucket' => env('S3_UPLOADS_PRIVATE_BUCKET'),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        // Tenant uploads — public. Bucket policy grants anonymous GetObject;
        // ONLY files explicitly flagged public may be written here.
        's3_public' => [
            'driver' => 's3',
            'key' => env('AWS_UPLOADS_KEY'),
            'secret' => env('AWS_UPLOADS_SECRET'),
            'region' => env('AWS_DEFAULT_REGION', 'eu-central-1'),
            'bucket' => env('S3_UPLOADS_PUBLIC_BUCKET'),
            'url' => env('S3_UPLOADS_PUBLIC_URL'),
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],

        // spatie/laravel-backup destination. Separate IAM (fleetq-backup) with
        // no access to the upload buckets; write-only posture under prod/fleetq/.
        's3_backup' => [
            'driver' => 's3',
            'key' => env('AWS_BACKUP_KEY'),
            'secret' => env('AWS_BACKUP_SECRET'),
            'region' => env('AWS_DEFAULT_REGION', 'eu-central-1'),
            'bucket' => env('S3_BACKUP_BUCKET'),
            'root' => env('S3_BACKUP_PREFIX', 'prod/fleetq'),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
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
