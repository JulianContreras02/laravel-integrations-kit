<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Daily Orders Report
    |--------------------------------------------------------------------------
    |
    | "Yesterday" is a business day in the shop's timezone, not in UTC: an
    | order placed at 23:30 in Bogotá is stored as 04:30 UTC the next day and
    | must still land in the report of the day it was placed.
    |
    */

    'timezone' => env('REPORT_TIMEZONE', 'America/Bogota'),

    // A run without --date delivers every day of this window that has no
    // completed delivery. Older gaps are not sent automatically: after a
    // long outage a person decides, instead of flooding the partner.
    'catch_up_days' => 3,

    // Where the generated files live (an S3 bucket in every environment).
    'disk' => env('REPORTS_DISK', 'reports'),
    'directory' => 'daily-orders',

    // Rows read from the database per query. The export never holds more
    // than one chunk in memory, whatever the size of the day.
    'chunk_size' => 1_000,

    // XLSX is a zip container, so it is assembled in a local temporary file
    // before being streamed to S3. On Lambda this is /tmp (ephemeral storage).
    'temp_directory' => env('REPORT_TEMP_DIRECTORY'),

    /*
    |--------------------------------------------------------------------------
    | Email
    |--------------------------------------------------------------------------
    |
    | Small files are attached. Files that would push the message over the
    | mail provider's limit are replaced by a signed download link.
    |
    */

    'mail' => [
        'to' => array_filter(explode(',', (string) env('REPORT_MAIL_TO', 'ops@partner.example'))),

        // Ceiling for the whole message after base64 encoding. Amazon SES:
        // 10 MB with the v1 API (Laravel's "ses" mailer), 40 MB with the v2 API
        // ("ses-v2") or SMTP. The recipient's server may accept less, so the
        // default stays at 10 MB, read as 10,000,000 bytes to be safe.
        'max_message_bytes' => (int) env('REPORT_MAIL_MAX_MESSAGE_BYTES', 10_000_000),

        // Room for headers, the HTML and text bodies and MIME boundaries.
        'headroom_bytes' => 256 * 1024,

        // How long the emailed download link keeps working.
        'link_ttl_days' => 7,

        // Lifetime of the S3 URL the link redirects to. Short on purpose: a
        // presigned URL works for anyone who has it.
        'presigned_url_ttl_minutes' => 5,
    ],

    // Disk used only to sign download URLs. Same bucket and credentials as
    // "disk", but with the endpoint the browser can reach (see filesystems).
    'signing_disk' => env('REPORTS_SIGNING_DISK', 'reports_public'),

];
