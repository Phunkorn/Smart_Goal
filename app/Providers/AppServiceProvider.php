<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListTaskRequest;
use App\Services\AdminApprovalQuery;
use App\Services\DailyBriefService;
use App\Services\Telegram\TelegramOutbox;
use App\Services\TicketSchemaStatus;
use App\Services\WorkOrderShareQuery;
use App\Support\TicketAgentAccess;
use App\Support\TicketSubmissionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as IlluminateView;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AdminApprovalQuery::class);

        /*
         * TelegramOutbox ต้องเป็นตัวเดียวกันทั้ง request เพราะเก็บธงว่าผูก callback
         * ตอนปิดท้าย request ไปแล้วหรือยัง ถ้าปล่อยให้สร้างใหม่ทุกครั้งที่ inject
         * request ที่สร้างการแจ้งเตือนหลายฉบับจะผูก callback ซ้ำเท่าจำนวนฉบับ
         */
        $this->app->scoped(TelegramOutbox::class);

        // จำผลตรวจตาราง Smart Ticket ภายใน request เดียว (ถูกถามจาก layout, middleware และ sidebar)
        $this->app->scoped(TicketSchemaStatus::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * ตัวแบ่งหน้าเริ่มต้นของ Laravel เขียนด้วยคลาสของ Tailwind และฝัง <svg> ลูกศรไว้ในตัว
         * โปรเจกต์นี้ใช้ Bootstrap 5 ไม่ใช่ Tailwind คลาสเหล่านั้นจึงไม่ถูกใช้เลย
         * ผลคือ <svg> ถูกวาดตามขนาดตั้งต้นของตัวเอง ลูกศร "ก่อนหน้า/ถัดไป" จึงใหญ่เต็มหน้าจอ
         * ตั้งค่าที่นี่ที่เดียวเพื่อให้ทุกหน้าที่เรียก ->links() ได้ตัวแบ่งหน้าหน้าตาเดียวกัน
         */
        Paginator::useBootstrapFive();

        // ตาราง Smart Ticket อาจถูกสร้างหรือถูกถอยกลับ — ล้างค่าที่จำไว้ทุกครั้งที่ migrate/rollback จบ
        Event::listen(MigrationsEnded::class, fn () => app(TicketSchemaStatus::class)->forget());

        View::composer('layouts.app', function (IlluminateView $view): void {
            $user = request()->user();
            if (! $user) {
                return;
            }

            /*
             * ป้ายตัวเลขของเมนู "แชร์งาน" นับเฉพาะคำขอที่รอ "ฉัน" ตัดสินในฐานะผู้แชร์
             * ไม่ใช่จำนวนประกาศในฟีด เพราะสิ่งที่ค้างอยู่ที่ตัวผู้ใช้คือคำขอ ไม่ใช่ประกาศ
             *
             * ประกาศไว้ที่ composer ตัวเดียวกับ approvalCounts เพื่อไม่ให้ทุก
             * controller ที่ render layout ต้องส่งค่านี้เอง
             */
            if (! array_key_exists('shareRequestCount', $view->getData())) {
                $view->with('shareRequestCount', $user->role === 'viewer'
                    ? 0
                    : app(WorkOrderShareQuery::class)->pendingIncomingCount($user));
            }

            /*
             * สรุปประจำวันแสดงบนหน้าแรกที่เปิดในวันนั้นจนกว่าจะกดรับทราบ จึงต้องอยู่ที่ layout
             * ไม่ใช่ที่ controller ของหน้าใดหน้าหนึ่ง (ผู้ใช้อาจเข้ามาทาง deep link ของแจ้งเตือน)
             * ช่วงที่รับทราบแล้ว isPending() เป็น query เดียว ส่วน build() ทำงานเฉพาะตอนต้องแสดง
             */
            if (! array_key_exists('dailyBrief', $view->getData())) {
                $dailyBrief = app(DailyBriefService::class);
                $view->with('dailyBrief', $dailyBrief->isPending($user) ? $dailyBrief->build($user) : null);
            }

            /*
             * เมนูและป้ายตัวเลขของ Smart Ticket — แตะตาราง Ticket เฉพาะเมื่อ migrate แล้ว
             * ฐานที่ยังไม่ได้ติดตั้งต้องเปิดทุกหน้าเดิมได้ตามปกติ (TicketSchemaStatus)
             * ป้ายตัวเลขคือ Ticket ที่รอรับงาน แสดงเฉพาะ agent ผู้ใช้อื่นไม่ยิงคิวรีนับ
             */
            if (! array_key_exists('ticketsInstalled', $view->getData())) {
                $ticketsInstalled = app(TicketSchemaStatus::class)->isInstalled();
                $isTicketAgent = $ticketsInstalled && TicketAgentAccess::isAgent($user);

                $view->with('ticketsInstalled', $ticketsInstalled);
                $view->with('ticketQueueCount', $isTicketAgent
                    ? Ticket::query()->where('status', Ticket::STATUS_NEW)->count()
                    : 0);
                // เสียงแจ้ง Ticket ใหม่ (components/ticket-alert.js) โหลดเฉพาะ agent ที่เปิดรับแจ้งเตือน
                $view->with('ticketAlertEnabled', $ticketsInstalled && TicketAgentAccess::hearsAlerts($user));
                // ชื่อไฟล์อาจมีช่องว่าง — encode ทีละส่วนของ path ให้เป็น URL ที่ถูกต้อง
                $view->with('ticketAlertSound', asset(implode('/', array_map('rawurlencode', explode('/', (string) config('tickets.alert.sound'))))));
            }

            if ($user->role !== 'admin' && ! $user->isDepartmentHead()) {
                return;
            }

            if (! array_key_exists('approvalCounts', $view->getData())) {
                $view->with('approvalCounts', app(AdminApprovalQuery::class)->counts($user));
            }
        });

        /*
         * แจ้งปัญหา Smart Ticket — ผู้ใช้ภายในจำกัดต่อบัญชี ฟอร์มสาธารณะจำกัดต่อ IP สองชั้น
         * (ระยะสั้นกันยิงรัว ระยะวันกันการส่งต่อเนื่องทั้งวัน) ดู App\Support\TicketSubmissionGuard
         * ตอบกลับเป็นข้อความไทยบนฟอร์มเดิมพร้อมค่าที่กรอกไว้ ไม่ใช่หน้า 429 เปล่า ๆ
         */
        RateLimiter::for(TicketSubmissionGuard::RATE_LIMITER, function (Request $request): Limit|array {
            $respond = function (Request $request, array $headers) {
                $message = 'ส่งแจ้งปัญหาถี่เกินไป กรุณารอสักครู่แล้วลองใหม่';

                if ($request->expectsJson()) {
                    return response()->json(['ok' => false, 'message' => $message, 'errors' => ['form' => [$message]]], 429, $headers);
                }

                return back()
                    ->withInput($request->except(['photos', TicketSubmissionGuard::TOKEN_FIELD]))
                    ->withErrors(['form' => $message])
                    ->withHeaders($headers);
            };

            if ($user = $request->user()) {
                return Limit::perMinute(10)->by('ticket:user:'.$user->getAuthIdentifier())->response($respond);
            }

            return [
                Limit::perMinutes(10, 5)->by('ticket:ip:'.$request->ip())->response($respond),
                Limit::perDay(20)->by('ticket:ip-day:'.$request->ip())->response($respond),
            ];
        });

        RateLimiter::for(WorkOrderListTaskRequest::SUBMIT_RATE_LIMITER, function (Request $request): Limit {
            $message = 'ส่งคำขอถี่เกินไป กรุณารอสักครู่แล้วลองใหม่';
            $userKey = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(WorkOrderListTaskRequest::SUBMIT_RATE_LIMIT_PER_MINUTE)
                ->by('user:'.$userKey)
                ->response(function (Request $request, array $headers) use ($message) {
                    if ($request->expectsJson()) {
                        return response()->json([
                            'ok' => false,
                            'message' => $message,
                            'errors' => ['task_request' => [$message]],
                        ], 429, $headers);
                    }

                    $list = $request->route('list');
                    $listId = $list instanceof WorkOrderList ? $list->id : (int) $list;

                    return back()
                        ->withInput()
                        ->withErrors(['task_request' => $message], 'projectTaskRequest')
                        ->with('project_task_request_list_id', $listId)
                        ->withHeaders($headers);
                });
        });
    }
}
