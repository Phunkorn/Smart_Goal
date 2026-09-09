<?php

use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkLogTemplate;
use App\Services\NotificationMaintenanceService;
use App\Services\RoutineAttentionService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramOutbox;
use App\Services\WorkLogRoutineMaterializer;
use App\Services\WorkLogService;
use App\Support\LogRetention;
use App\Support\SchedulerHeartbeat;
use App\Support\TrashRetention;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('trash:purge-expired', function () {
    $count = TrashRetention::purgeExpired();

    $this->info("Purged {$count} expired trash item(s).");
})->purpose('Permanently delete trash items after retention period');

Schedule::command('trash:purge-expired')->dailyAt('02:30');

/*
 * ล้างบันทึกกิจกรรมที่พ้นอายุ
 *
 * ถังขยะมีกำหนด 30 วันมาตั้งแต่ต้น แต่ activity_logs ไม่เคยมีการล้างเลย นโยบายอายุ
 * อยู่ที่ App\Support\LogRetention ที่เดียว ทั้งคำสั่งนี้และปุ่มในหน้า Audit Log
 * อ่านจากนิยามเดียวกัน
 */
Artisan::command('audit:prune-activity', function () {
    $count = LogRetention::pruneActivity();

    $this->info("Pruned {$count} expired activity log(s).");
})->purpose('Delete activity logs past their retention period');

Schedule::command('audit:prune-activity')->dailyAt('03:00')->timezone('Asia/Bangkok');

/*
 * สัญญาณชีพของ scheduler
 *
 * ระบบนี้ไม่มีขั้นตอนตั้ง cron ใน deploy/README.md มาก่อน จึงไม่เคยมีใครรู้ว่างานตาม
 * เวลาข้างบนทำงานจริงหรือไม่ การประทับเวลาไว้ทุกรอบทำให้หน้า Audit Log บอกได้เอง
 * และเตือนผู้ดูแลระบบให้ใช้ปุ่มล้างด้วยมือแทนเมื่อ cron ไม่ทำงาน
 */
Schedule::call(fn () => SchedulerHeartbeat::record())->everyFiveMinutes();

Artisan::command('notifications:generate-deadlines', function (NotificationMaintenanceService $notifications) {
    $this->info("Generated {$notifications->generateDeadlines()} deadline notification(s).");
})->purpose('Generate due-today and overdue task notifications');

Artisan::command('notifications:prune', function (NotificationMaintenanceService $notifications) {
    $this->info("Pruned {$notifications->prune()} old read notification(s).");
})->purpose('Delete read notifications older than 90 days');

Schedule::command('notifications:generate-deadlines')->dailyAt('00:05')->timezone('Asia/Bangkok');
Schedule::command('notifications:prune')->dailyAt('02:45')->timezone('Asia/Bangkok');

/*
 * ปิดตัวจับเวลาที่ยังค้างอยู่จากรุ่นก่อนหน้า
 *
 * ระบบจับเวลาถูกถอดออกจากบันทึกงานประจำวันแล้ว (ปิดงานด้วยการกดยืนยันครั้งเดียว
 * แทน) แต่ฐานข้อมูลของเครื่องที่ใช้งานอยู่ก่อนหน้ายังมีแถวที่ open_timer_owner_id
 * ค้างอยู่ได้ คำสั่งนี้จึงเหลือไว้เพื่อเก็บกวาดข้อมูลเก่าให้ปิดอย่างถูกต้อง
 * ไม่ใช่ฟีเจอร์ที่ผู้ใช้เห็น
 *
 * คำสั่งนี้เป็นเพียงตัวช่วย ไม่ใช่แหล่งความจริง WorkLogController::index() เรียก
 * closeLeftoverTimers() ตอนเปิดหน้าด้วย เพราะ deploy/README.md ของระบบนี้ไม่มี
 * ขั้นตอนตั้ง cron ให้ schedule:run บนเครื่อง production
 */
Artisan::command('worklogs:close-stale-timers', function (): void {
    $closed = 0;

    User::query()
        ->whereIn('role', ['admin', 'user'])
        ->whereIn('id', WorkLog::query()->whereNotNull('open_timer_owner_id')->select('open_timer_owner_id'))
        ->each(function (User $owner) use (&$closed): void {
            $closed += app(WorkLogService::class)->closeLeftoverTimers($owner);
        });

    $this->info("Closed {$closed} stale work-log timer(s).");
})->purpose('Close work-log timers left over from the removed stopwatch feature');

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
        // ทั้งเจ้าของแม่แบบ และคนที่ถูกใส่ชื่อไว้เป็นผู้ร่วมงานของแม่แบบคนอื่น
        ->where(fn ($scoped) => $scoped
            ->whereIn('id', WorkLogTemplate::query()->where('is_active', true)->select('user_id'))
            ->orWhereIn('id', DB::table('work_log_template_participants')
                ->join('work_log_templates', 'work_log_templates.id', '=', 'work_log_template_participants.work_log_template_id')
                ->where('work_log_templates.is_active', true)
                ->select('work_log_template_participants.user_id')))
        ->each(function (User $owner) use (&$created): void {
            $created += app(WorkLogRoutineMaterializer::class)->materializeToday($owner);
        });

    $this->info("Created {$created} routine work-log entr(ies) for today.");
})->purpose('Create today\'s routine work-log entries from each member\'s templates');

Schedule::command('worklogs:materialize-routines')->dailyAt('00:10')->timezone('Asia/Bangkok');

Artisan::command('worklogs:notify-routines', function (): void {
    $users = User::query()
        ->where('is_active', true)
        ->where('role', '!=', 'viewer')
        ->get();

    foreach ($users as $user) {
        app(RoutineAttentionService::class)->notifyUser($user);
    }

    $this->info('Routine reminders checked.');
})->purpose('Notify users about routine work that has not started or has exceeded its planned time');

Schedule::command('worklogs:notify-routines')->everyFiveMinutes()->timezone('Asia/Bangkok')->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Telegram
|--------------------------------------------------------------------------
|
| การส่งจริงเกิดตอนปิดท้ายทุก request อยู่แล้ว (ดู App\Services\Telegram\TelegramOutbox)
| คำสั่งเหล่านี้จึงเป็นเครื่องมือสำหรับผู้ดูแล ไม่ใช่เส้นทางหลักของการส่ง
|
| telegram:drain ผูกเข้า schedule ไว้เผื่ออนาคตมี cron แต่ตอนนี้ยังไม่ทำงานบน
| production ด้วยเหตุผลเดียวกับคำสั่งอื่นในไฟล์นี้
*/

Artisan::command('telegram:set-webhook {url?}', function (TelegramClient $telegram): int {
    $url = $this->argument('url') ?: rtrim((string) config('app.url'), '/').'/telegram/webhook';

    if (blank(config('services.telegram.webhook_secret'))) {
        $this->error('ยังไม่ได้ตั้ง TELEGRAM_WEBHOOK_SECRET ใน .env');

        return 1;
    }

    $result = $telegram->setWebhook($url);

    if (! $result->ok) {
        $this->error('ตั้ง webhook ไม่สำเร็จ: '.$result->description);

        return 1;
    }

    $this->info('ตั้ง webhook เรียบร้อยแล้วที่ '.$url);

    return 0;
})->purpose('Register the Telegram bot webhook with the configured secret token');

Artisan::command('telegram:webhook-info', function (TelegramClient $telegram): int {
    $result = $telegram->getWebhookInfo();

    if (! $result->ok) {
        $this->error('อ่านข้อมูล webhook ไม่สำเร็จ: '.$result->description);

        return 1;
    }

    foreach ($result->payload as $key => $value) {
        $this->line(str_pad((string) $key, 28).': '.(is_scalar($value) ? var_export($value, true) : json_encode($value)));
    }

    return 0;
})->purpose('Show the current Telegram webhook registration');

Artisan::command('telegram:drain', function (TelegramOutbox $outbox): void {
    $this->info("Delivered {$outbox->drain()} queued Telegram message(s).");
})->purpose('Deliver queued Telegram messages that are still pending');

Schedule::command('telegram:drain')->everyFiveMinutes()->withoutOverlapping();
