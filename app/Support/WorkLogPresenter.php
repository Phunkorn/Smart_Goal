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
        $status = WorkLogDesign::status($log->status);

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
            'status_label' => $status['label'],
            'status_tone' => $status['tone'],

            'source' => $log->source,
            'work_date' => $log->work_date?->format('Y-m-d'),

            // ส่งทั้งค่า ISO (ให้ JavaScript คำนวณเวลาที่เดินอยู่) และข้อความที่
            // แปลงเป็นเวลากรุงเทพแล้ว (ให้แสดงผลได้ทันทีโดยไม่ต้องแปลงซ้ำฝั่ง client)
            'started_at' => $log->started_at?->toIso8601String(),
            'ended_at' => $log->ended_at?->toIso8601String(),
            'started_time' => self::clockLabel($log, 'started_at'),
            'ended_time' => self::clockLabel($log, 'ended_at'),
            'time_range_label' => self::timeRangeLabel($log),

            'duration_minutes' => $log->duration_minutes,
            'duration_label' => WorkLogDesign::durationLabel($log->duration_minutes),

            'is_running' => $log->open_timer_owner_id !== null,
            'auto_closed' => $log->auto_closed_at !== null,

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

        if ($log->open_timer_owner_id !== null) {
            return $start.' - กำลังทำ';
        }

        $end = self::clockLabel($log, 'ended_at');

        return $end === null ? $start : $start.' - '.$end;
    }
}
