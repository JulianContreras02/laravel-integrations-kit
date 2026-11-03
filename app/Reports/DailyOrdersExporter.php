<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use stdClass;

/**
 * Builds the daily orders spreadsheet and stores it on the reports disk.
 *
 * Memory stays flat whatever the size of the day: rows are read in chunks
 * with keyset pagination and written one by one by OpenSpout, which never
 * keeps the workbook in memory (PhpSpreadsheet, behind Laravel Excel, does).
 * The finished file is streamed to the disk, not read into a string.
 */
final class DailyOrdersExporter
{
    public const string MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly FilesystemFactory $storage,
        private readonly string $disk,
        private readonly string $directory,
        private readonly string $timezone,
        private readonly int $chunkSize = 1_000,
        private readonly ?string $temporaryDirectory = null,
    ) {}

    public static function fileName(CarbonImmutable $day): string
    {
        return 'daily-orders-'.$day->toDateString().'.xlsx';
    }

    public function export(CarbonImmutable $day): ExportedReport
    {
        $fileName = self::fileName($day);
        $path = $this->directory.'/'.$day->format('Y/m').'/'.$fileName;
        $localFile = $this->temporaryDirectory().'/'.Str::ulid().'-'.$fileName;

        try {
            $rowCount = $this->writeSpreadsheet($day, $localFile);
            $sizeBytes = filesize($localFile);
            $sha256 = hash_file('sha256', $localFile);

            if ($sizeBytes === false || $sha256 === false) {
                throw new RuntimeException("Could not read back the generated report {$localFile}.");
            }

            $this->upload($localFile, $path);
        } finally {
            if (is_file($localFile)) {
                unlink($localFile);
            }
        }

        return new ExportedReport($path, $fileName, $rowCount, $sizeBytes, $sha256);
    }

    private function writeSpreadsheet(CarbonImmutable $day, string $localFile): int
    {
        $options = new Options(tempFolder: $this->temporaryDirectory());
        $options->setColumnWidth(20, 1, 2);
        $options->setColumnWidth(12, 3, 7, 8, 9);
        $options->setColumnWidth(24, 4, 5);

        $writer = new Writer($options);
        $writer->openToFile($localFile);
        $writer->getCurrentSheet()->setName('Orders')->setSheetView(new SheetView(freezeRow: 2));

        $columnStyles = [
            1 => new Style(format: 'yyyy-mm-dd hh:mm'),
            7 => new Style(format: '#,##0.00'),
        ];

        $rowCount = 0;

        try {
            $writer->addRow(Row::fromValuesWithStyle([
                'Order number', "Placed at ({$this->timezone})", 'Status', 'Customer',
                'City', 'Country', 'Items', 'Total', 'Currency',
            ], new Style(fontBold: true)));

            // The shape is fixed by the select() in ordersPlacedOn(). Integer
            // columns may arrive as strings depending on the PDO driver.
            /** @var object{number: string, placed_at: string, status: string, customer_name: string, shipping_city: string, shipping_country: string, items_count: int|string, total_cents: int|string, currency: string} $order */
            foreach ($this->ordersPlacedOn($day) as $order) {
                $writer->addRow(Row::fromValuesWithStyles([
                    $order->number,
                    CarbonImmutable::parse($order->placed_at, 'UTC')->setTimezone($this->timezone),
                    $order->status,
                    $order->customer_name,
                    $order->shipping_city,
                    $order->shipping_country,
                    (int) $order->items_count,
                    (int) $order->total_cents / 100,
                    $order->currency,
                ], $columnStyles));

                $rowCount++;
            }
        } finally {
            $writer->close();
        }

        return $rowCount;
    }

    /**
     * Plain rows (no Eloquent models) in id order, one chunk at a time. Only
     * the columns the partner needs: no customer email leaves the shop.
     *
     * @return LazyCollection<int, stdClass>
     */
    private function ordersPlacedOn(CarbonImmutable $day): LazyCollection
    {
        $start = CarbonImmutable::parse($day->toDateString(), $this->timezone)->startOfDay();

        return Order::query()
            ->toBase()
            ->select(['id', 'number', 'placed_at', 'status', 'customer_name', 'shipping_city', 'shipping_country', 'items_count', 'total_cents', 'currency'])
            ->where('placed_at', '>=', $start->utc())
            ->where('placed_at', '<', $start->addDay()->utc())
            ->lazyById($this->chunkSize);
    }

    private function upload(string $localFile, string $path): void
    {
        $stream = fopen($localFile, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Could not open the generated report {$localFile}.");
        }

        try {
            $this->storage->disk($this->disk)->writeStream($path, $stream, ['ContentType' => self::MIME_TYPE]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function temporaryDirectory(): string
    {
        return rtrim($this->temporaryDirectory ?? sys_get_temp_dir(), '/');
    }
}
