<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Support\TrashRetention;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('trash:purge-expired', function () {
    $count = TrashRetention::purgeExpired();

    $this->info("Purged {$count} expired trash item(s).");
})->purpose('Permanently delete trash items after retention period');

Schedule::command('trash:purge-expired')->dailyAt('02:30');
