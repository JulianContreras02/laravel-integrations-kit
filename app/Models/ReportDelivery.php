<?php

declare(strict_types=1);

namespace App\Models;

use App\Reports\ExportedReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $report_key
 * @property CarbonImmutable $report_date
 * @property ReportDeliveryStatus $status
 * @property int $attempts
 * @property string|null $file_path
 * @property string|null $file_name
 * @property int|null $row_count
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property CarbonImmutable|null $exported_at
 * @property CarbonImmutable|null $sftp_delivered_at
 * @property string|null $partner_delivery_id
 * @property CarbonImmutable|null $partner_notified_at
 * @property bool|null $emailed_as_attachment
 * @property CarbonImmutable|null $emailed_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $last_error
 */
class ReportDelivery extends Model
{
    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ReportDeliveryStatus::Pending->value,
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_date' => 'immutable_date',
            'status' => ReportDeliveryStatus::class,
            'attempts' => 'integer',
            'row_count' => 'integer',
            'size_bytes' => 'integer',
            'exported_at' => 'immutable_datetime',
            'sftp_delivered_at' => 'immutable_datetime',
            'partner_notified_at' => 'immutable_datetime',
            'emailed_as_attachment' => 'boolean',
            'emailed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function recordExport(ExportedReport $report): void
    {
        $this->update([
            'file_path' => $report->path,
            'file_name' => $report->fileName,
            'row_count' => $report->rowCount,
            'size_bytes' => $report->sizeBytes,
            'sha256' => $report->sha256,
            'exported_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * The file produced by the export step, rebuilt from this row so that a
     * retry works on exactly the same file instead of generating a new one.
     */
    public function exportedReport(): ExportedReport
    {
        if ($this->exported_at === null || $this->file_path === null || $this->file_name === null
            || $this->row_count === null || $this->size_bytes === null || $this->sha256 === null) {
            throw new LogicException("Report {$this->report_key} has not been exported yet.");
        }

        return new ExportedReport($this->file_path, $this->file_name, $this->row_count, $this->size_bytes, $this->sha256);
    }
}
