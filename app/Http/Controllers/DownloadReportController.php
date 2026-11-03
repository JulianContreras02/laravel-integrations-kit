<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Reports\DailyOrdersExporter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Target of the link in the report email. The route is signed (valid for
 * days, cannot be altered) and only redirects to a presigned S3 URL that
 * lives a few minutes. Keeping our own link in front of S3 means:
 *
 * - its lifetime does not depend on the AWS credentials that sign the S3
 *   URL (with an IAM role those expire after hours, not days);
 * - every download goes through our logs;
 * - rotating APP_KEY revokes every link already sent.
 */
final class DownloadReportController extends Controller
{
    public function __invoke(Request $request, string $date, DailyOrdersExporter $exporter, FilesystemFactory $storage): RedirectResponse
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        abort_if($day === null, 404);

        $path = $exporter->pathFor($day);
        abort_unless($storage->disk(Config::string('reports.disk'))->exists($path), 404);

        Log::info('Daily orders report downloaded.', ['date' => $date, 'ip' => $request->ip()]);

        $url = $storage->disk(Config::string('reports.signing_disk'))->temporaryUrl(
            $path,
            CarbonImmutable::now()->addMinutes(Config::integer('reports.mail.presigned_url_ttl_minutes')),
            ['ResponseContentDisposition' => 'attachment; filename="'.DailyOrdersExporter::fileName($day).'"'],
        );

        return redirect()->away($url);
    }
}
