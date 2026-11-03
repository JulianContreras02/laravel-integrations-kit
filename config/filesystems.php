<?php

declare(strict_types=1);

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
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Generated reports. Without explicit keys the AWS SDK falls back to
        // its default credential chain (an IAM role in production).
        'reports' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            // Fail loudly: a silent "false" from a failed upload would let the
            // pipeline mark a report as delivered when it is not.
            'throw' => true,
            'report' => false,
        ],

        // Signs presigned URLs for browsers. SigV4 signs the host, so a URL
        // signed for the internal endpoint (http://s3:9000 in Docker) cannot
        // be rewritten to the public one afterwards; it has to be signed for
        // the public host from the start. Signing needs no network call. With
        // real S3 both endpoints are empty and this equals the reports disk.
        'reports_public' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_PUBLIC_ENDPOINT') ?: env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'report' => false,
        ],

        // The partner's SFTP drop box. Use a private key in production and pin
        // the server's host key fingerprint so nobody can impersonate it.
        'partner_sftp' => [
            'driver' => 'sftp',
            'host' => env('PARTNER_SFTP_HOST'),
            'port' => (int) env('PARTNER_SFTP_PORT', 22),
            'username' => env('PARTNER_SFTP_USERNAME'),
            'password' => env('PARTNER_SFTP_PASSWORD'),
            'privateKey' => env('PARTNER_SFTP_PRIVATE_KEY'),
            'passphrase' => env('PARTNER_SFTP_PASSPHRASE'),
            'hostFingerprint' => env('PARTNER_SFTP_HOST_FINGERPRINT'),
            'root' => env('PARTNER_SFTP_ROOT', '/'),
            'timeout' => 10,
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
