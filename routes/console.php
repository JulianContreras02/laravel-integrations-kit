<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;

// Every morning, after the business day is closed. onOneServer() keeps
// several scheduler hosts from queueing the same run twice.
Schedule::command('report:deliver')
    ->dailyAt('06:00')
    ->timezone(Config::string('reports.timezone'))
    ->onOneServer()
    ->withoutOverlapping();
