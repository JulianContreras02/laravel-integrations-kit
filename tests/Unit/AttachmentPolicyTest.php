<?php

declare(strict_types=1);

use App\Mail\AttachmentPolicy;

it('predicts the exact size of a base64 MIME attachment', function (int $bytes) {
    $mime = chunk_split(base64_encode(random_bytes($bytes)), 76, "\r\n");

    expect(AttachmentPolicy::encodedSize($bytes))->toBe(strlen($mime));
})->with([1, 2, 3, 56, 57, 58, 1_000, 65_536, 1_048_576]);

it('grows a file by roughly 37 percent once encoded', function () {
    expect(AttachmentPolicy::encodedSize(3_000_000) / 3_000_000)->toBeBetween(1.36, 1.37);
});

it('allows about 7.1 MB per attachment under the 10 MB SES v1 limit', function () {
    $policy = new AttachmentPolicy(maxMessageBytes: 10_000_000, headroomBytes: 256 * 1024);

    $largest = $policy->largestAttachment();

    expect($largest)->toBeBetween(7_100_000, 7_120_000)
        ->and($policy->fits($largest))->toBeTrue()
        ->and($policy->fits($largest + 1))->toBeFalse();
});

it('accounts for the headroom of headers and body', function () {
    $roomy = new AttachmentPolicy(maxMessageBytes: 1_000_000, headroomBytes: 0);
    $tight = new AttachmentPolicy(maxMessageBytes: 1_000_000, headroomBytes: 100_000);

    expect($tight->largestAttachment())->toBeLessThan($roomy->largestAttachment());
});
