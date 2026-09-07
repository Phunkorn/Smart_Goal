<?php

use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Services\NotificationMaintenanceService;
use App\Services\WorkLogRoutineMaterializer;
use App\Services\WorkLogService;
use App\Support\TrashRetention;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

/*
 * ปิดตัวจับเวลาของบันทึกงานประจำวันที่ถูกลืมค้างข้ามวัน
 *
 * คำสั่งนี้เป็นเพียงตัวช่วย ไม่ใช่แหล่งความจริง WorkLogController::index() ก็เรียก
 * closeStaleTimers() ตอนเปิดหน้าด้วย เพราะ deploy/README.md ของระบบนี้ไม่มีขั้นตอน
 * ตั้ง cron ให้ schedule:run บนเครื่อง production หากพึ่งคำสั่งนี้อย่างเดียวแล้ว
 * cron ไม่ถูกตั้ง ผู้ใช้จะเห็นตัวจับเวลาเดินค้างข้ามวันโดยไม่มีอะไรมาแก้ให้
 *
 * ประโยชน์ของการมีคำสั่งนี้คือ หัวหน้าที่เปิดดูวันของลูกทีมตอนเช้า จะเห็นข้อมูล
 * ที่ถูกปิดเรียบร้อยแล้ว แม้เจ้าตัวจะยังไม่ได้เข้าระบบ
 */
Artisan::command('worklogs:close-stale-timers', function (): void {
    $closed = 0;

    User::query()
        ->whereIn('role', ['admin', 'user'])
        ->whereIn('id', WorkLog::query()->whereNotNull('open_timer_owner_id')->select('open_timer_owner_id'))
        ->each(function (User $owner) use (&$closed): void {
            $closed += app(WorkLogService::class)->closeStaleTimers($owner);
        });

    $this->info("Closed {$closed} stale work-log timer(s).");
})->purpose('Close daily work-log timers left running across the business day');

Schedule::command('worklogs:close-stale-timers')->dailyAt('00:20')->timezone('Asia/Bangkok');

/*
 * สร้างรายการงานประจำของวันนี้ให้ผู้ใช้ทุกคนที่มีแม่แบบ
 *
 * เช่นเดียวกับคำสั่งข้างบน คำสั่งนี้เป็นตัวช่วย ไม่ใช่แหล่งความจริง
 * WorkLogController::index() สร้างให้ตอนเปิดหน้าอยู่แล้ว การมีคำสั่งนี้ทำให้
 * หัวหน้าที่เปิดดูวันของลูกทีมตอนเช้าเห็นรายการงานประจำ แม้เจ้าตัวยังไม่ได้เข้าระบบ
 *
 * ความถูกต้องไม่ขึ้นกับว่าเส้นทางไหนทำงานก่อน เพราะ unique index
 * work_logs_template_day_unique เป็นผู้กันรายการซ้ำ
 */
Artisan::command('worklogs:materialize-routines', function (): void {
    $created = 0;

    User::query()
        ->whereIn('role', ['admin', 'user'])
        ->where('is_active', true)
        ->whereIn('id', WorkLogTemplate::query()->where('is_active', true)->select('user_id'))
        ->each(function (User $owner) use (&$created): void {
            $created += app(WorkLogRoutineMaterializer::class)->materializeToday($owner);
        });

    $this->info("Created {$created} routine work-log entr(ies) for today.");
})->purpose('Create today\'s routine work-log entries from each member\'s templates');

Schedule::command('worklogs:materialize-routines')->dailyAt('00:10')->timezone('Asia/Bangkok');
