<?php

namespace App\Providers;

use App\Models\WorkOrderList;
use App\Models\WorkOrderListTaskRequest;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
