<?php

namespace App\Support;

use App\Models\WorkLog;
use App\Models\WorkLogAttachment;

/**
 * รูปแบบข้อมูลเดียวของบันทึกงานหนึ่งรายการ ใช้ร่วมกันทั้ง Blade, payload ของ
 * AJAX และไฟล์ CSV เพื่อไม่ให้แต่ละช่องทางประกอบข้อมูลเองคนละแบบ
 *
 * ข้อห้ามสำคัญ: ห้ามส่ง file_path ของไฟล์แนบออกไปเด็ดขาด ไฟล์แนบเป็นไฟล์ส่วนตัว
 * ที่ต้องผ่านการตรวจสิทธิ์ใน MediaController เสมอ สิ่งที่ส่งออกได้คือ URL ของ
 * route ที่ตรวจสิทธิ์ให้แล้วเท่านั้น
 */
final class WorkLogPresenter
{
    public static function forClient(WorkLog $log): array
    {
        $kind = WorkLogDesign::kind($log->kind);
        $now = TodayWorkspace::businessNow()->utc();
        $isRoutine = $log->work_log_template_id !== null;
        $isPastDay = self::isPastDay($log);
        $displayStatus = self::displayStatus($log);
        $isOverdue = $displayStatus === 'overdue';
        $requiresExplanation = self::requiresExplanation($log);
        $isPastCutoff = $log->work_date !== null && WorkLogDesign::isPastCutoff($log->work_date->format('Y-m-d'));
        $isStartable = $log->status === 'open' || ($log->status === 'absent' && ! $isPastCutoff);
        $status = WorkLogDesign::status($displayStatus);

        return [
            'id' => $log->id,
            'title' => $log->title,
            'details' => $log->details,
            'location' => $log->location,
            'requester_name' => $log->requester_name,

            'kind' => $log->kind,
            'kind_label' => $kind['label'],
            'kind_tone' => $kind['tone'],
            'kind_icon' => $kind['icon'],

            'status' => $log->status,
            'display_status' => $displayStatus,
            'status_label' => $status['label'],
            'status_tone' => $status['tone'],

            'source' => $log->source,
            'work_date' => $log->work_date?->format('Y-m-d'),

            // ส่งทั้งค่า ISO (ให้ JavaScript คำนวณเวลาที่เดินอยู่) และข้อความที่
            // แปลงเป็นเวลากรุงเทพแล้ว (ให้แสดงผลได้ทันทีโดยไม่ต้องแปลงซ้ำฝั่ง client)
            'started_at' => $log->started_at?->toIso8601String(),
            'ended_at' => $log->ended_at?->toIso8601String(),
            'planned_start_at' => $log->planned_start_at?->toIso8601String(),
            'planned_end_at' => $log->planned_end_at?->toIso8601String(),
            'planned_start_time' => self::clockLabel($log, 'planned_start_at'),
            'planned_end_time' => self::clockLabel($log, 'planned_end_at'),
            'started_time' => self::clockLabel($log, 'started_at'),
            'ended_time' => self::clockLabel($log, 'ended_at'),
            'time_range_label' => self::timeRangeLabel($log),

            'duration_minutes' => $log->duration_minutes,
            'duration_label' => WorkLogDesign::durationLabel($log->duration_minutes),

            'is_done' => $log->status === 'done',
            'is_skipped' => $log->status === 'skipped',
            'is_in_progress' => $log->status === 'in_progress',
            'is_overdue' => $isOverdue,
            'is_past_day' => $isPastDay,
            // ปิดรอบ 17:00 แล้ว (ไม่ได้เริ่ม / เริ่มแล้วไม่กดเสร็จ / ไม่มา) — เหลือทางเดียวคือระบุเหตุผล
            'is_closed_by_cutoff' => $isRoutine && in_array($displayStatus, WorkLogDesign::EXPLANATION_STATUSES, true)
                && ($displayStatus !== 'absent' || $isPastCutoff),
            'requires_explanation' => $requiresExplanation,
            'explanation_type' => $requiresExplanation ? $displayStatus : null,
            'can_start' => $isRoutine && ! $isPastCutoff && $isStartable
                && ($log->planned_start_at === null || ! $now->lessThan($log->planned_start_at)),
            // ช้าเมื่อเลยเวลาที่ตั้งไว้เกินช่วงผ่อนผัน — กติกาเดียวกับ WorkLogService
            'requires_late_start_reason' => $isRoutine && ! $isPastCutoff && $isStartable
                && $log->planned_start_at !== null && $now->greaterThan(WorkLogDesign::lateAfter($log->planned_start_at)),
            'requires_late_completion_reason' => $isRoutine && ! $isPastDay && $log->status === 'in_progress'
                && $log->planned_end_at !== null && $now->greaterThan(WorkLogDesign::lateAfter($log->planned_end_at)),
            // เวลาที่เริ่มนับว่าช้า ให้หน้าจอที่เปิดค้างไว้รู้ว่าต้องถามเหตุผลแล้ว โดยไม่ต้องคำนวณช่วงผ่อนผันเอง
            'late_start_after' => $isRoutine && $log->planned_start_at !== null
                ? WorkLogDesign::lateAfter($log->planned_start_at)->toIso8601String() : null,
            'late_completion_after' => $isRoutine && $log->planned_end_at !== null
                ? WorkLogDesign::lateAfter($log->planned_end_at)->toIso8601String() : null,
            'late_start_reason' => $log->late_start_reason,
            'late_completion_reason' => $log->late_completion_reason,
            'unfinished_reason' => $log->unfinished_reason,
            'absent_marked_by' => $log->absent_marked_by === null ? null : $log->absentMarkedBy?->name,
            'explained_at' => $log->explained_at?->toIso8601String(),
            'has_issue' => (bool) $log->has_issue,
            'issue_details' => $log->issue_details,
            // งานนอกสถานที่ที่คนอื่นสร้างแล้วเพิ่มเจ้าของรายการนี้เข้าร่วม
            'shared_from' => self::sharedFrom($log),
            'skip_reason' => $log->skip_reason,
            'auto_closed' => $log->auto_closed_at !== null,

            // งานประจำที่มาจากแม่แบบ — ต้องบอกช่วงเวลาที่ "ตั้งไว้ว่าต้องเข้าไปทำ"
            // ไม่ใช่เวลาที่ทำจริง เพราะตอนที่รายการยังค้างอยู่ ยังไม่มีเวลาจริงเลย
            'routine' => self::routine($log),

            'owner' => [
                'id' => $log->user_id,
                'name' => $log->user?->name,
            ],
            'category' => $log->work_log_category_id === null ? null : [
                'id' => $log->work_log_category_id,
                'name' => $log->category?->name ?? 'ไม่ระบุหมวด',
                'tone' => $log->category?->tone ?? 'gray',
                'icon' => $log->category?->icon,
            ],
            'project' => $log->work_order_list_id === null ? null : [
                'id' => $log->work_order_list_id,
                'name' => $log->project?->name ?? 'โปรเจกต์ถูกลบแล้ว',
            ],
            'task' => self::task($log),
            // ผู้ร่วมงาน — คนที่อยู่หน้างานด้วยกัน ไม่ใช่เจ้าของบันทึก
            'participants' => $log->relationLoaded('participants')
                ? $log->participants->map(fn ($person): array => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'initials' => WorkBoardDesign::initials((string) $person->name),
                ])->values()->all()
                : [],
            'attachments' => $log->attachments
                ->map(fn (WorkLogAttachment $attachment): array => self::attachment($attachment))
                ->values()
                ->all(),
        ];
    }

    /**
     * สถานะที่ผู้ใช้เห็น — แหล่งเดียวของกติกา "เกินเวลา" และ "ต้องระบุเหตุผล"
     *
     * ใช้ร่วมกันระหว่างหน้าบันทึกงานประจำวันและรายงานปฏิบัติงาน สองหน้าจึงบอกสถานะ
     * ของรายการเดียวกันตรงกันเสมอ ไม่ต้องโหลดไฟล์แนบเหมือน forClient()
     */
    public static function displayStatus(WorkLog $log): string
    {
        $isRoutine = $log->work_log_template_id !== null;
        $isUnclosed = in_array($log->status, ['open', 'in_progress'], true);

        // เลยเวลาปิดรอบ 17:00 ของวันนั้นแล้ว — แยก "ไม่ได้เริ่ม" กับ "เริ่มแล้วไม่กดเสร็จ" ให้ชัด
        // ใช้ได้แม้ยังไม่มีทางเข้าไหนเขียนสถานะลงฐานข้อมูล รายงานจึงเห็นตรงกันเสมอ
        // (RoutineAccountabilityService::closeFor() เขียนค่าเดียวกันนี้ลงคอลัมน์ status)
        if ($isRoutine && $isUnclosed && $log->work_date !== null
            && WorkLogDesign::isPastCutoff($log->work_date->format('Y-m-d'))) {
            return $log->status === 'in_progress' ? 'unfinished' : 'not_started';
        }

        if ($isRoutine && $isUnclosed && $log->planned_end_at !== null
            && TodayWorkspace::businessNow()->utc()->greaterThan($log->planned_end_at)) {
            return 'overdue';
        }

        return (string) $log->status;
    }

    /**
     * รายการงานประจำนี้ปิดรอบแล้วและยังไม่มีเหตุผลหรือไม่
     *
     * not_started / absent ต้องมี skip_reason ส่วน unfinished ต้องมี unfinished_reason
     * absent ของวันนี้ที่ยังไม่ถึงเวลาปิดรอบยังไม่ต้องตอบ เพราะเจ้าตัวยังมาเริ่มเองได้
     */
    public static function requiresExplanation(WorkLog $log): bool
    {
        if ($log->work_log_template_id === null) {
            return false;
        }

        $status = self::displayStatus($log);

        return match ($status) {
            'unfinished' => blank($log->unfinished_reason),
            'not_started' => blank($log->skip_reason),
            'absent' => blank($log->skip_reason)
                && $log->work_date !== null
                && WorkLogDesign::isPastCutoff($log->work_date->format('Y-m-d')),
            default => false,
        };
    }

    /**
     * สถานะที่คนในแผนกเห็นบนปฏิทิน — เหมือน displayStatus() แต่แยก "พบปัญหา" ออกจาก "เสร็จแล้ว"
     *
     * แยกเป็นเมธอดของตัวเองเพราะรายงานปฏิบัติงานนับ displayStatus() = 'done' เป็นงานที่ปิดแล้ว
     * ถ้าเปลี่ยนค่านั้นเป็น 'issue' ตัวเลขในรายงานจะหายไปเงียบ ๆ
     */
    public static function calendarStatus(WorkLog $log): string
    {
        if ($log->status === 'done' && $log->has_issue) {
            return 'issue';
        }

        return self::displayStatus($log);
    }

    /**
     * คนสร้างงานนอกสถานที่ต้นฉบับ และคนอื่นที่ไปด้วยกัน (ไม่รวมเจ้าของรายการนี้)
     */
    private static function sharedFrom(WorkLog $log): ?array
    {
        if ($log->shared_from_work_log_id === null) {
            return null;
        }

        // ต้นฉบับถูกลบไปแล้ว — ยังเป็นสำเนา แต่ไม่มีชื่อให้แสดง
        $source = $log->sharedFrom;

        if ($source === null) {
            return ['owner_name' => null, 'people' => []];
        }

        return [
            'owner_name' => $source->user?->name,
            'people' => collect([$source->user])
                ->merge($source->participants)
                ->filter(fn ($person) => $person && (int) $person->id !== (int) $log->user_id)
                ->pluck('name')->unique()->values()->all(),
        ];
    }

    /**
     * วันของรายการผ่านไปแล้วหรือยัง เทียบด้วยวันตามเวลาทำการเสมอ
     * เพราะ 23:00 ที่กรุงเทพยังเป็นวันเดิม แต่เป็นวันถัดไปแล้วตามเวลา UTC
     */
    public static function isPastDay(WorkLog $log): bool
    {
        return $log->work_date !== null
            && $log->work_date->format('Y-m-d') < TodayWorkspace::businessNow()->format('Y-m-d');
    }

    /**
     * ข้อมูลของงานประจำที่รายการนี้ถูกสร้างมาจาก
     *
     * คืน null สำหรับงานที่ผู้ใช้พิมพ์เอง เพื่อให้ฝั่งแสดงผลแยกสองกรณีนี้ได้ตรง ๆ
     * โดยไม่ต้องเดาจาก source หรือ kind ซึ่งเป็นคนละความหมายกัน
     */
    private static function routine(WorkLog $log): ?array
    {
        if ($log->work_log_template_id === null) {
            return null;
        }

        $template = $log->template;

        if ($template === null) {
            return null;
        }

        $isShared = (int) $template->user_id !== (int) $log->user_id;

        return [
            'id' => $template->id,
            // ช่วงเวลาที่ต้องเข้าไปทำ เช่น "08:30 - 08:50"
            'window' => $template->plannedWindowLabel(),
            'is_shared' => $isShared,
            // ชื่อคนที่ตั้งงานประจำนี้ไว้ แสดงเฉพาะตอนที่ไม่ใช่ของตัวเอง
            'owner_name' => $isShared ? $template->user?->name : null,
            'people' => collect([$template->user])
                ->merge($template->participants)
                ->filter(fn ($person) => $person && (int) $person->id !== (int) $log->user_id)
                ->pluck('name')->unique()->values()->all(),
        ];
    }

    /**
     * งานโครงการที่ผูกไว้
     *
     * work_orders เป็น soft delete การลบงานจึงไม่ทำให้บันทึกเวลาหายไปด้วย
     * แต่ต้องบอกผู้ใช้ตรง ๆ ว่างานปลายทางถูกลบแล้ว ไม่ใช่แสดงเป็นช่องว่าง
     */
    private static function task(WorkLog $log): ?array
    {
        if ($log->job_id === null) {
            return null;
        }

        $task = $log->task;

        return [
            'id' => $log->job_id,
            'topic' => $task?->job_topic ?? 'งานถูกลบแล้ว',
            'is_deleted' => $task === null || $task->trashed(),
        ];
    }

    private static function attachment(WorkLogAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'type' => $attachment->file_type,
            'url' => route('media.work-log-attachments.show', $attachment),
        ];
    }

    /**
     * เวลานาฬิกาตามเวลาทำการ เช่น "09:30"
     */
    private static function clockLabel(WorkLog $log, string $attribute): ?string
    {
        $value = $log->{$attribute};

        return $value === null
            ? null
            : TodayWorkspace::businessNow($value)->format('H:i');
    }

    /**
     * ป้ายช่วงเวลาที่พร้อมแสดงผล เช่น "10:45 - 13:30", "09:30 - กำลังทำ"
     */
    private static function timeRangeLabel(WorkLog $log): string
    {
        $start = self::clockLabel($log, 'started_at');

        if ($start === null) {
            return 'ไม่ระบุเวลา';
        }

        $end = self::clockLabel($log, 'ended_at');

        return $end === null ? $start : $start.' - '.$end;
    }
}
