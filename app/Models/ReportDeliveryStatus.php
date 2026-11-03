<?php

declare(strict_types=1);

namespace App\Models;

enum ReportDeliveryStatus: string
{
    // Not finished yet; the next run (or a queue retry) picks it up.
    case Pending = 'pending';

    case Completed = 'completed';

    // Out of retries or blocked by something a retry cannot fix. The daily
    // catch-up leaves it alone; a person re-runs it with --date.
    case Failed = 'failed';
}
