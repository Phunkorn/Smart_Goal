<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderSubtask;
use App\Support\AuditSnapshot;
use App\Support\AuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * ย้อนค่าที่ถูกแก้ทับกลับไปเป็นค่าเดิม
 *
 * ชื่องาน หัวข้อโปรเจกต์ และรายละเอียด ไม่ได้ "ถูกลบ" จึงไม่เคยเข้าถังขยะ แต่ถูก
 * เขียนทับด้วยค่าใหม่ ค่าเดิมยังอยู่ครบในคอลัมน์ changes ของบันทึกกิจกรรม เพราะ
 * handler ส่วนใหญ่ snapshot ทั้งแถวลง changes.before ไม่ใช่เฉพาะฟิลด์ที่แตะ
 * คลาสนี้จึงเป็นแค่การอ่านค่าที่มีอยู่แล้วกลับไปเขียนคืน
 *
 * หลักความปลอดภัยสามข้อ:
 *
 * 1. allow-list เท่านั้น ไม่ใช่ deny-list — ฟิลด์ที่ไม่ได้ระบุไว้ที่นี่ย้อนไม่ได้
 *    เสมอ การเพิ่มคอลัมน์ใหม่ให้ตารางจึงไม่เปิดช่องให้ย้อนโดยไม่ตั้งใจ
 *
 * 2. สถานะงานย้อนไม่ได้ — job_status เป็น state machine ที่ต้องผ่าน
 *    TaskStatusTransitionService เท่านั้นตามกฎของโปรเจกต์ การเขียนทับตรง ๆ
 *    จะข้ามการตรวจ subtask, ประวัติการเปลี่ยนสถานะ และการแจ้งเตือนทั้งหมด
 *
 * 3. การย้อนถูกบันทึกเป็นกิจกรรมของตัวเอง — ย้อนแล้วยังตรวจสอบย้อนหลังได้ว่า
 *    ใครย้อนอะไรเมื่อไร และย้อนของการย้อนได้อีกชั้นถ้าจำเป็น
 */
class AuditRevertService
{
    /**
     * ฟิลด์ที่ย้อนได้ แยกตามชนิดข้อมูล
     *
     * เลือกเฉพาะฟิลด์ที่เป็น "เนื้อหาที่คนพิมพ์" กับ "กำหนดเวลา" ซึ่งการย้อนมีความหมาย
     * ตรงไปตรงมาและไม่กระทบสิทธิ์ ความสัมพันธ์ หรือ workflow ของงาน
     *
     * @var array<class-string, array<int, string>>
     */
    private const REVERTABLE = [
        WorkOrder::class => ['job_topic', 'job_details', 'job_start_at', 'job_due_at', 'job_priority'],
        WorkOrderList::class => ['name', 'description', 'priority'],
        WorkOrderSubtask::class => ['title', 'detail'],
    ];

    /**
     * ฟิลด์ที่ห้ามย้อนเด็ดขาด แม้จะมีใครเผลอเพิ่มเข้า allow-list ในอนาคต
     *
     * เป็นตาข่ายชั้นที่สอง ไม่ใช่ชั้นเดียวที่กัน — ชั้นแรกคือ REVERTABLE
     *
     * @var array<int, string>
     */
    private const NEVER = [
        'id', 'job_id', 'password', 'remember_token', 'role', 'is_department_head',
        'is_active', 'job_status', 'approval_status', 'user_id', 'created_by',
        'leader_user_id', 'department_id', 'work_order_list_id', 'deleted_at',
    ];

    /**
     * ฟิลด์ที่ย้อนได้จริงของบันทึกนี้ พร้อมค่าเดิมและค่าปัจจุบัน
     *
     * คืนเฉพาะฟิลด์ที่ยังต่างจากค่าปัจจุบันจริง ฟิลด์ที่มีคนย้อนไปแล้วหรือแก้กลับเอง
     * จึงไม่ขึ้นปุ่มซ้ำให้กดเปล่า
     *
     * @return array<int, array{field: string, label: string, before: mixed, current: mixed}>
     */
    public function revertableFields(ActivityLog $log): array
    {
        $subject = $this->subject($log);

        if (! $subject) {
            return [];
        }

        $before = AuditSnapshot::changeSets($log)['before'];
        $labels = AuditSnapshot::fieldLabels();
        $rows = [];

        foreach ($this->allowedFields($log->subject_type) as $field) {
            if (! array_key_exists($field, $before)) {
                continue;
            }

            $current = $subject->getAttribute($field);

            // เทียบเป็นสตริงเพราะค่าจาก JSON เป็น string เสมอ ส่วนค่าจากโมเดลอาจเป็น
            // int หรือ Carbon การเทียบแบบ === จะบอกว่า "ต่างกัน" ทุกครั้งจนปุ่มขึ้นตลอด
            if ($this->normalize($before[$field]) === $this->normalize($current)) {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'label' => $labels[$field] ?? $field,
                'before' => $before[$field],
                'current' => $current,
            ];
        }

        return $rows;
    }

    public function canRevert(ActivityLog $log): bool
    {
        return $this->revertableFields($log) !== [];
    }

    /**
     * ย้อนเฉพาะฟิลด์ที่ผู้ใช้เลือก
     *
     * @param  array<int, string>  $fields
     * @return array<int, string> ฟิลด์ที่ย้อนสำเร็จ
     */
    public function revert(ActivityLog $log, array $fields, User $actor): array
    {
        $subject = $this->subject($log);
        abort_unless($subject, 404, 'ไม่พบข้อมูลต้นฉบับที่จะย้อนค่า');

        $revertable = collect($this->revertableFields($log))->keyBy('field');
        $selected = array_values(array_intersect($fields, $revertable->keys()->all()));

        abort_if($selected === [], 422, 'ไม่มีฟิลด์ที่ย้อนค่าได้ในรายการที่เลือก');

        return DB::transaction(function () use ($log, $subject, $revertable, $selected, $actor) {
            $before = [];
            $after = [];

            foreach ($selected as $field) {
                $before[$field] = $subject->getAttribute($field);
                $after[$field] = $revertable[$field]['before'];
                $subject->setAttribute($field, $revertable[$field]['before']);
            }

            $subject->save();

            AuditTrail::log(
                'reverted',
                $subject,
                'ย้อนค่าเดิม: '.collect($selected)->map(fn ($f) => $revertable[$f]['label'])->join(', '),
                [
                    'before' => $before,
                    'after' => $after,
                    'reverted_from_log_id' => $log->id,
                    'reverted_by' => $actor->id,
                ]
            );

            return $selected;
        });
    }

    /**
     * @return array<int, string>
     */
    private function allowedFields(?string $subjectType): array
    {
        $allowed = self::REVERTABLE[$subjectType] ?? [];

        return array_values(array_diff($allowed, self::NEVER));
    }

    /**
     * แถวต้นฉบับที่บันทึกนี้พูดถึง
     *
     * ข้อมูลที่ถูก soft delete ไปแล้วย้อนค่าไม่ได้ ต้องกู้คืนจากถังขยะก่อน
     * ไม่เช่นนั้นการย้อนจะเขียนลงแถวที่ผู้ใช้มองไม่เห็นและไม่รู้ว่าเกิดอะไรขึ้น
     */
    private function subject(ActivityLog $log): ?Model
    {
        $class = $log->subject_type;

        if (! $class || ! isset(self::REVERTABLE[$class]) || ! class_exists($class)) {
            return null;
        }

        /** @var class-string<Model> $class */
        $model = $class::query()->find($log->subject_id);

        if ($model && in_array(SoftDeletes::class, class_uses_recursive($class), true) && $model->trashed()) {
            return null;
        }

        return $model;
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        // ค่าที่เป็นวันเวลาในรูปสตริงต้องถูกทำให้อยู่รูปเดียวกันก่อนเทียบ
        // ไม่งั้น "2026-09-03T09:15:00.000000Z" กับ "2026-09-03 09:15:00" จะดูต่างกัน
        $string = (string) $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $string)) {
            return substr(str_replace('T', ' ', $string), 0, 19);
        }

        return $string;
    }
}
