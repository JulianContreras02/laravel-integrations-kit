<?php

declare(strict_types=1);

use App\Delivery\RemoteFileConflict;
use App\Delivery\SftpDelivery;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->runId = Str::lower(Str::random(8));
    $this->sourcePath = "integration-tests/{$this->runId}/report.xlsx";
    $this->remoteName = "it-{$this->runId}.xlsx";

    Storage::disk('reports')->put($this->sourcePath, str_repeat('spreadsheet bytes ', 1_000));
});

afterEach(function () {
    Storage::disk('reports')->deleteDirectory("integration-tests/{$this->runId}");
    Storage::disk('partner_sftp')->delete([$this->remoteName, $this->remoteName.'.part']);
});

it('delivers to the real SFTP server exactly once', function () {
    $first = app(SftpDelivery::class)->deliver('reports', $this->sourcePath, $this->remoteName);
    $second = app(SftpDelivery::class)->deliver('reports', $this->sourcePath, $this->remoteName);

    $remote = Storage::disk('partner_sftp');
    expect($first->alreadyDelivered)->toBeFalse()
        ->and($second->alreadyDelivered)->toBeTrue()
        ->and($remote->get($this->remoteName))->toBe(Storage::disk('reports')->get($this->sourcePath))
        ->and($remote->exists($this->remoteName.'.part'))->toBeFalse();
});

it('does not overwrite a different file the partner already has', function () {
    Storage::disk('partner_sftp')->put($this->remoteName, 'something else');

    expect(fn () => app(SftpDelivery::class)->deliver('reports', $this->sourcePath, $this->remoteName))
        ->toThrow(RemoteFileConflict::class);
    expect(Storage::disk('partner_sftp')->get($this->remoteName))->toBe('something else');
});
