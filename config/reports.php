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

    // Where the generated files live (an S3 bucket in every environment).
    'disk' => env('REPORTS_DISK', 'reports'),
    'directory' => 'daily-orders',

    // Rows read from the database per query. The export never holds more
    // than one chunk in memory, whatever the size of the day.
    'chunk_size' => 1_000,

    // XLSX is a zip container, so it is assembled in a local temporary file
    // before being streamed to S3. On Lambda this is /tmp (ephemeral storage).
    'temp_directory' => env('REPORT_TEMP_DIRECTORY'),

];
