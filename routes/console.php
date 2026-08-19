<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Support\TrashRetention;
use App\Services\NotificationMaintenanceService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('trash:purge-expired', function () {
    $count = TrashRetention::purgeExpired();

    $this->info("Purged {$count} expired trash item(s).");
})->purpose('Permanently delete trash items after retention period');

Schedule::command('trash:purge-expired')->dailyAt('02:30');

Artisan::command('notifications:generate-deadlines', function (NotificationMaintenanceService $notifications) {
    $this->info("Generated {$notifications->generateDeadlines()} deadline notification(s).");
})->purpose('Generate due-today and overdue task notifications');

Artisan::command('notifications:prune', function (NotificationMaintenanceService $notifications) {
    $this->info("Pruned {$notifications->prune()} old read notification(s).");
})->purpose('Delete read notifications older than 90 days');

Schedule::command('notifications:generate-deadlines')->dailyAt('00:05')->timezone('Asia/Bangkok');
Schedule::command('notifications:prune')->dailyAt('02:45')->timezone('Asia/Bangkok');
