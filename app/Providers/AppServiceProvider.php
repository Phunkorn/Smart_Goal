<?php

namespace App\Providers;

use App\Models\WorkOrderList;
use App\Models\WorkOrderListTaskRequest;
use App\Services\AdminApprovalQuery;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
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

        View::composer('layouts.app', function (IlluminateView $view): void {
            $user = request()->user();
            if (! $user || ($user->role !== 'admin' && ! $user->isDepartmentHead())) {
                return;
            }

            if (! array_key_exists('approvalCounts', $view->getData())) {
                $view->with('approvalCounts', app(AdminApprovalQuery::class)->counts($user));
            }
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
