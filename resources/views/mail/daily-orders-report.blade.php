<x-mail::message>
# Daily orders report

**{{ $reportDate }}** ({{ $timezone }}): {{ number_format($report->rowCount) }} orders in `{{ $report->fileName }}` ({{ Number::fileSize($report->sizeBytes, precision: 1) }}).

@if ($downloadUrl)
The file is too large to attach, so it is available at the link below until {{ $linkExpiresAt?->setTimezone($timezone)->format('Y-m-d H:i') }} ({{ $timezone }}).

<x-mail::button :url="$downloadUrl">
Download the report
</x-mail::button>

Anyone with this link can download the file until it expires, so please do not forward it.
@else
The file is attached to this email.
@endif

SHA-256: `{{ $report->sha256 }}`

{{ config('app.name') }}
</x-mail::message>
