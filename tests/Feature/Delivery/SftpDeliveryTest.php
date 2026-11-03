<?php

declare(strict_types=1);

use App\Delivery\RemoteFileConflict;
use App\Delivery\SftpDelivery;
use App\Delivery\SftpDeliveryResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use League\Flysystem\UnableToWriteFile;

const REPORT_PATH = 'daily-orders/2026/11/daily-orders-2026-11-02.xlsx';
const REMOTE_NAME = 'daily-orders-2026-11-02.xlsx';
const REPORT_BYTES = 'pretend this is a spreadsheet';

beforeEach(function () {
    config()->set('partner.sftp.disk', 'partner_sftp');
    config()->set('partner.sftp.backoff_ms', [1_000, 5_000]);

    Storage::fake('reports');
    Storage::disk('reports')->put(REPORT_PATH, REPORT_BYTES);
});

function deliverReport(): SftpDeliveryResult
{
    return app(SftpDelivery::class)->deliver('reports', REPORT_PATH, REMOTE_NAME);
}

describe('idempotency', function () {
    beforeEach(fn () => Storage::fake('partner_sftp'));

    it('uploads the file and leaves no partial file behind', function () {
        $result = deliverReport();

        expect($result->alreadyDelivered)->toBeFalse()
            ->and($result->attempts)->toBe(1)
            ->and(Storage::disk('partner_sftp')->get(REMOTE_NAME))->toBe(REPORT_BYTES);
        Storage::disk('partner_sftp')->assertMissing(REMOTE_NAME.'.part');
    });

    it('does nothing when the same file is already there', function () {
        // Same size, different bytes: proves the file was left untouched.
        $alreadyThere = str_repeat('x', strlen(REPORT_BYTES));
        Storage::disk('partner_sftp')->put(REMOTE_NAME, $alreadyThere);

        $result = deliverReport();

        expect($result->alreadyDelivered)->toBeTrue()
            ->and(Storage::disk('partner_sftp')->get(REMOTE_NAME))->toBe($alreadyThere);
    });

    it('delivers once when run twice', function () {
        expect(deliverReport()->alreadyDelivered)->toBeFalse()
            ->and(deliverReport()->alreadyDelivered)->toBeTrue()
            ->and(Storage::disk('partner_sftp')->allFiles())->toBe([REMOTE_NAME]);
    });

    it('refuses to overwrite a different file with the same name', function () {
        Storage::disk('partner_sftp')->put(REMOTE_NAME, 'an older, shorter file');

        expect(fn () => deliverReport())->toThrow(RemoteFileConflict::class, 'Not overwriting it');
        expect(Storage::disk('partner_sftp')->get(REMOTE_NAME))->toBe('an older, shorter file');
    });

    it('replaces a partial upload left behind by a crashed attempt', function () {
        Storage::disk('partner_sftp')->put(REMOTE_NAME.'.part', 'half a fi');

        deliverReport();

        expect(Storage::disk('partner_sftp')->get(REMOTE_NAME))->toBe(REPORT_BYTES);
        Storage::disk('partner_sftp')->assertMissing(REMOTE_NAME.'.part');
    });
});

describe('retries', function () {
    beforeEach(fn () => Sleep::fake());

    it('writes under a temporary name and renames it when complete', function () {
        $remote = Mockery::mock(Filesystem::class);
        $remote->shouldReceive('exists')->with(REMOTE_NAME)->andReturnFalse();
        $remote->shouldReceive('writeStream')->once()->ordered()->with(REMOTE_NAME.'.part', Mockery::type('resource'))->andReturnTrue();
        $remote->shouldReceive('move')->once()->ordered()->with(REMOTE_NAME.'.part', REMOTE_NAME)->andReturnTrue();
        Storage::set('partner_sftp', $remote);

        deliverReport();
    });

    it('retries transient SFTP errors with increasing pauses', function () {
        $failures = 0;
        $remote = Mockery::mock(Filesystem::class);
        $remote->shouldReceive('exists')->andReturnFalse();
        $remote->shouldReceive('writeStream')->times(3)->andReturnUsing(function () use (&$failures): bool {
            if (++$failures <= 2) {
                throw UnableToWriteFile::atLocation(REMOTE_NAME.'.part', 'Connection reset by peer');
            }

            return true;
        });
        $remote->shouldReceive('move')->once()->andReturnTrue();
        Storage::set('partner_sftp', $remote);

        $result = deliverReport();

        expect($result->attempts)->toBe(3);
        Sleep::assertSequence([Sleep::usleep(1_000_000), Sleep::usleep(5_000_000)]);
    });

    it('gives up after the last retry', function () {
        $remote = Mockery::mock(Filesystem::class);
        $remote->shouldReceive('exists')->andReturnFalse();
        $remote->shouldReceive('writeStream')->times(3)->andThrow(UnableToWriteFile::atLocation(REMOTE_NAME.'.part', 'No route to host'));
        $remote->shouldNotReceive('move');
        Storage::set('partner_sftp', $remote);

        expect(fn () => deliverReport())->toThrow(UnableToWriteFile::class);
        Sleep::assertSleptTimes(2);
    });

    it('does not retry a name conflict', function () {
        $remote = Mockery::mock(Filesystem::class);
        $remote->shouldReceive('exists')->once()->andReturnTrue();
        $remote->shouldReceive('size')->once()->andReturn(1);
        $remote->shouldNotReceive('writeStream');
        Storage::set('partner_sftp', $remote);

        expect(fn () => deliverReport())->toThrow(RemoteFileConflict::class);
        Sleep::assertNeverSlept();
    });
});
