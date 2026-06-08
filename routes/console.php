<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly: purge sandbox-workspace events / deliveries / api_keys older
// than 24 hours. Sandbox visitors only get a 24h window.
Schedule::command('webhook-relay:sweep-sandbox --hours=24')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
