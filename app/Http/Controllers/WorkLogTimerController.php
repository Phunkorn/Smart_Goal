<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithTaskResult;
use App\Models\WorkLog;
use App\Services\WorkLogParticipantService;
use App\Services\WorkLogQueryService;
use App\Services\WorkLogService;
use App\Support\WorkLogDesign;
use App\Support\WorkLogPresenter;
use App\Support\WorkLogSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * ตัวจับเวลาของบันทึกงานประจำวัน
 *
 * แยกจาก WorkLogController เพราะเป็นคนละเรื่องกัน: ที่นั่นคือการบันทึกสิ่งที่
 * เกิดขึ้นแล้ว ส่วนที่นี่คือการเดินเวลาของงานที่กำลังทำอยู่ ซึ่งมีกติกาเฉพาะ
 * (หนึ่งคนจับเวลาได้ทีละงานเดียว และเวลาต้องมาจากเซิร์ฟเวอร์เท่านั้น)
 */
class WorkLogTimerController extends Controller
{
    use RespondsWithTaskResult;

    public function __construct(
        private readonly WorkLogService $logs,
        private readonly WorkLogQueryService $query,
        private readonly WorkLogParticipantService $participants,
    ) {}

    public function start(Request $request)
    {
        Gate::authorize('create', WorkLog::class);

        $actor = Auth::user();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'kind' => ['required', 'string', 'in:'.implode(',', WorkLogDesign::kindKeys())],
            'work_log_category_id' => ['nullable', 'integer', 'exists:work_log_categories,id'],
            'work_order_list_id' => ['nullable', 'integer', 'exists:work_order_lists,id'],
            'job_id' => ['nullable', 'integer', 'exists:work_orders,job_id'],
            'details' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:120'],
            'requester_name' => ['nullable', 'string', 'max:120'],
            'participants' => ['nullable', 'array', 'max:20'],
            'participants.*' => ['integer', 'exists:users,id'],
        ]);

        $log = $this->logs->startTimer($actor, $actor, $data);

        // เคสที่ต้องการที่สุดของฟีเจอร์นี้: ตรวจสอบคอมพิวเตอร์ตอนเช้าสองคน
        // กดเริ่มงานครั้งเดียวแล้วติ๊กเพื่อนไปพร้อมกัน ไม่ต้องเปิดฟอร์มเต็ม
        $this->participants->sync($log, $actor, $request->input('participants', []));

        return $this->jsonOrBack($request, true, 'เริ่มจับเวลาแล้ว', 200, $this->payload($log));
    }

    /**
     * เริ่มจับเวลาบนรายการที่มีอยู่แล้ว เช่น งานประจำที่ระบบสร้างให้ตอนเช้า
     */
    public function resume(Request $request, WorkLog $workLog)
    {
        Gate::authorize('manageTimer', $workLog);

        $log = $this->logs->resumeTimer($workLog, Auth::user());

        return $this->jsonOrBack($request, true, 'เริ่มจับเวลาแล้ว', 200, $this->payload($log));
    }

    public function stop(Request $request, WorkLog $workLog)
    {
        Gate::authorize('manageTimer', $workLog);

        $log = $this->logs->stopTimer($workLog, Auth::user());

        return $this->jsonOrBack(
            $request,
            true,
            'บันทึกเวลาเรียบร้อย — '.WorkLogDesign::durationLabel($log->duration_minutes),
            200,
            $this->payload($log)
        );
    }

    /**
     * ข้อมูลที่หน้าจอต้องใช้อัปเดตตัวเองหลังเริ่ม/หยุดจับเวลา
     *
     * ใช้รูปแบบเดียวกับ WorkLogController::mutationPayload() เพื่อให้ฝั่ง client
     * มีทางเดียวในการนำผลลัพธ์ไปแสดง
     */
    private function payload(WorkLog $log): array
    {
        $log->loadMissing(['category', 'project', 'task', 'attachments', 'user', 'participants']);

        return [
            'log' => WorkLogPresenter::forClient($log),
            'summary' => WorkLogSummary::fromLogs($this->query->dayFor($log->user, $log->work_date)),
            'html' => view('daily-logs.components.log-card', [
                'log' => $log,
                'presented' => WorkLogPresenter::forClient($log),
                'capabilities' => ['canEdit' => true, 'canUseTimer' => true, 'isReadOnly' => false],
            ])->render(),
        ];
    }
}
