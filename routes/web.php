<?php

declare(strict_types=1);

use App\Http\Controllers\DownloadReportController;
use Illuminate\Support\Facades\Route;

Route::get('/reports/daily-orders/{date}', DownloadReportController::class)
    ->where('date', '\d{4}-\d{2}-\d{2}')
    ->middleware('signed')
    ->name('reports.daily-orders.download');
