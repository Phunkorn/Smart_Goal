<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\TrashLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ข้อเท็จจริงร่วมสำหรับการอ่านบันทึกตรวจสอบ (Audit Log)
 *
 * ตารางป้ายภาษาไทยและตัวจัดรูปแบบค่าเคยอยู่ในหัวไฟล์ Blade ของหน้าบันทึกระบบ
 * ทำให้แท็บถังขยะกับสตรีมภาพรวมเรียกใช้ไม่ได้ จึงย้ายมารวมไว้ที่เดียวตามกฎของ App\Support
 *
 * คลาสนี้ไม่มี state และไม่แตะฐานข้อมูล ยกเว้น resolvableProfileImages()
 * ที่ต้องถาม users เพื่อรู้ว่า path เดิมยังเปิดดูได้จริงหรือไม่
 */
class AuditSnapshot
{
    /** ค่าที่ห้ามแสดงในบันทึกไม่ว่ากรณีใด */
    public const SENSITIVE_FIELDS = ['password', 'remember_token'];

    /** คอลัมน์ที่ไม่ให้ขึ้นในตารางเปรียบเทียบก่อน/หลัง เพราะไม่ใช่ข้อมูลที่คนตรวจสอบสนใจ */
    public const HIDDEN_FIELDS = ['password', 'remember_token', 'updated_at'];

    /**
     * @return array<string, string>
     */
    public static function fieldLabels(): array
    {
        return [
            'id' => 'รหัส',
            'name' => 'ชื่อ',
            'username' => 'ชื่อผู้ใช้',
            'email' => 'อีเมล',
            'phone' => 'เบอร์โทรศัพท์',
            'role' => 'สิทธิ์การใช้งาน',
            'department_id' => 'แผนก',
            'department_name' => 'แผนก',
            'profile_image' => 'รูปโปรไฟล์',
            'must_change_password' => 'บังคับเปลี่ยนรหัสผ่าน',
            'email_verified_at' => 'ยืนยันอีเมลเมื่อ',
            'created_at' => 'สร้างเมื่อ',
            'updated_at' => 'แก้ไขล่าสุด',
            'deleted_at' => 'ลบเมื่อ',
            'title' => 'ชื่องาน',
            'job_topic' => 'ชื่องาน',
            'description' => 'รายละเอียด',
            'detail' => 'รายละเอียด',
            'comment' => 'ความคิดเห็น',
            'job_status' => 'สถานะงาน',
            'status' => 'สถานะ',
            'job_priority' => 'ความสำคัญ',
            'priority_level' => 'ความสำคัญ',
            'due_date' => 'กำหนดส่ง',
            'job_due_at' => 'กำหนดส่ง',
            'job_start_at' => 'วันที่เริ่ม',
            'progress' => 'ความคืบหน้า',
            'project_leader_id' => 'หัวหน้าโปรเจกต์',
            'delete_request_reason' => 'เหตุผลขอลบ',
            'delete_requested_at' => 'วันที่ส่งคำขอลบ',
            'delete_rejected_reason' => 'เหตุผลปฏิเสธ',
            'attachments' => 'ไฟล์อ้างอิงงาน',
            'ip_address' => 'หมายเลข IP',
            'reason' => 'เหตุผล',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        return [
            'admin' => 'ผู้ดูแลระบบ',
            'user' => 'พนักงาน',
            'viewer' => 'ผู้เข้าชม',
        ];
    }

    /**
     * ป้ายสถานะงานต้องตรงกับ work_orders.job_status ปัจจุบัน
     *
     * สถานะ 1 ถูกถอดออกจากระบบแล้ว จึงไม่มีในตารางนี้ แต่บันทึกเก่ายังมีค่า 1 อยู่
     * formatValue() จึงต้องอธิบายให้ชัดแทนการแสดงเลขดิบ
     *
     * @return array<int, string>
     */
    public static function statusLabels(): array
    {
        return [
            2 => 'กำลังดำเนินการ',
            3 => 'รอตรวจสอบ',
            4 => 'เสร็จสิ้น',
            5 => 'พักงาน',
            6 => 'ล่าช้า',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function priorityLabels(): array
    {
        return [
            'low' => 'ต่ำ',
            'normal' => 'ปกติ',
            'medium' => 'ปกติ',
            'high' => 'สูง',
            'critical' => 'เร่งด่วน',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        return [
            'created' => 'สร้าง',
            'create' => 'สร้าง',
            'updated' => 'แก้ไข',
            'update' => 'แก้ไข',
            'deleted' => 'ลบ',
            'delete' => 'ลบ',
            'restored' => 'กู้คืน',
            'restore' => 'กู้คืน',
            'login' => 'เข้าสู่ระบบ',
            'logout' => 'ออกจากระบบ',
            'login_failed' => 'เข้าสู่ระบบไม่สำเร็จ',
            'login_locked' => 'ถูกล็อกชั่วคราว',
            'password_changed' => 'เปลี่ยนรหัสผ่าน',
            'status_changed' => 'เปลี่ยนสถานะงาน',
            'priority_changed' => 'เปลี่ยนความสำคัญ',
            'due_date_changed' => 'เปลี่ยนกำหนดส่ง',
            'schedule_changed' => 'เปลี่ยนช่วงเวลางาน',
            'progress_updated' => 'เพิ่มความคิดเห็น/อัปเดตงาน',
            'attachments_uploaded' => 'เพิ่มไฟล์อ้างอิงงาน',
            'attachment_deleted' => 'ลบไฟล์อ้างอิงงาน',
            'delete_requested' => 'ส่งคำขอลบงาน',
            'delete_request_rejected' => 'ปฏิเสธคำขอลบงาน',
            'approval_updated' => 'อัปเดตการอนุมัติ',
            'collaborator_added' => 'เพิ่มผู้ร่วมโปรเจกต์',
            'collaborator_removed' => 'นำผู้ร่วมโปรเจกต์ออก',
            'collaborator_accepted' => 'ตอบรับเป็นผู้ร่วมงาน',
            'collaborator_rejected' => 'ปฏิเสธการเป็นผู้ร่วมงาน',
            'project_leader_assigned' => 'กำหนดหัวหน้าโปรเจกต์',
            'project_task_request_approved' => 'อนุมัติคำขอเพิ่มงาน',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function subjectLabels(): array
    {
        return [
            'User' => 'พนักงาน',
            'Employee' => 'พนักงาน',
            'Department' => 'แผนก',
            'Task' => 'งาน',
            'Job' => 'โปรเจกต์',
            'Subtask' => 'งานย่อย',
            'WorkOrder' => 'รายการงาน',
            'WorkOrderList' => 'โปรเจกต์',
            'Meeting' => 'การประชุม',
        ];
    }

    /** เหตุการณ์ที่เกี่ยวกับการเข้าออกระบบ ใช้แยกนับในแท็บภาพรวมและระบายสีในตาราง */
    public const AUTH_ACTIONS = ['login', 'logout', 'login_failed', 'login_locked'];

    public static function actionLabel(?string $action): string
    {
        return self::actionLabels()[strtolower((string) $action)] ?? (string) $action;
    }

    public static function subjectLabel(?string $subjectType): string
    {
        if (! $subjectType) {
            return 'ระบบ';
        }

        $basename = class_basename($subjectType);

        return self::subjectLabels()[$basename] ?? $basename;
    }

    public static function isAuthAction(?string $action): bool
    {
        return in_array(strtolower((string) $action), self::AUTH_ACTIONS, true);
    }

    /**
     * ปิดบังค่าที่อ่อนไหวในทุกชั้นของโครงสร้าง ก่อนที่ค่าจะถูกส่งออกไปแสดงผล
     */
    public static function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nested) {
            $value[$key] = in_array((string) $key, self::SENSITIVE_FIELDS, true)
                ? '[REDACTED]'
                : self::redact($nested);
        }

        return $value;
    }

    /**
     * แปลงค่าดิบในบันทึกให้อ่านเข้าใจได้
     *
     * job_status ต้องแยกจาก status เพราะสถานะงานถูกถอดค่า 1 ออกจากระบบแล้ว
     * บันทึกเก่าที่ยังมีค่า 1 ต้องบอกให้ชัดว่าเป็นสถานะที่ยกเลิกไปแล้ว ไม่ใช่แสดงเลขดิบ
     */
    public static function formatValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (in_array($field, self::SENSITIVE_FIELDS, true)) {
            return '[REDACTED]';
        }

        if ($field === 'role') {
            return self::roleLabels()[$value] ?? (string) $value;
        }

        if ($field === 'must_change_password') {
            return (bool) $value ? 'ใช่' : 'ไม่ใช่';
        }

        if ($field === 'job_status') {
            if ((int) $value === 1) {
                return 'สถานะเดิมที่ยกเลิกแล้ว';
            }

            return self::statusLabels()[$value] ?? 'สถานะไม่รองรับ';
        }

        if ($field === 'status') {
            return self::statusLabels()[$value] ?? (string) $value;
        }

        if ($field === 'priority_level') {
            return self::priorityLabels()[strtolower((string) $value)] ?? (string) $value;
        }

        if (in_array($field, [
            'created_at',
            'updated_at',
            'deleted_at',
            'email_verified_at',
            'due_date',
            'job_due_at',
            'job_start_at',
            'delete_requested_at',
        ], true)) {
            try {
                return Carbon::parse($value)
                    ->timezone(TodayWorkspace::BUSINESS_TIMEZONE)
                    ->format('d/m/Y H:i');
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        if (is_bool($value)) {
            return $value ? 'ใช่' : 'ไม่ใช่';
        }

        if (is_array($value)) {
            return json_encode(self::redact($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public static function changeSets(ActivityLog $log): array
    {
        $changes = is_array($log->changes) ? $log->changes : json_decode($log->changes ?? '[]', true);
        $changes = is_array($changes) ? $changes : [];

        $before = $changes['before'] ?? $changes['old'] ?? [];
        $after = $changes['after'] ?? $changes['new'] ?? [];

        return [
            'before' => is_array($before) ? $before : [],
            'after' => is_array($after) ? $after : [],
            'raw' => $changes,
        ];
    }

    /**
     * ชื่อของสิ่งที่ถูกกระทำ ใช้ description เป็นทางเลือกสุดท้ายเมื่อ snapshot ไม่มีชื่อ
     */
    public static function targetName(array $changes, ?string $description): ?string
    {
        $snapshots = [
            $changes['after'] ?? [],
            $changes['new'] ?? [],
            $changes['before'] ?? [],
            $changes['old'] ?? [],
            $changes,
        ];

        foreach ($snapshots as $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            foreach (['name', 'job_topic', 'title'] as $field) {
                $value = $snapshot[$field] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return Str::limit(trim((string) $value), 110);
                }
            }
        }

        return filled($description) ? Str::limit(trim($description), 110) : null;
    }

    /**
     * ประกอบเหตุการณ์หนึ่งรายการให้อ่านเป็นประโยคเดียว
     *
     * เดิมหน้าภาพรวมวางผู้ทำรายการ การกระทำ และเป้าหมายเป็นคนละบรรทัด
     * ผู้อ่านจึงต้องประกอบความหมายเอง เมธอดนี้คืนส่วนประกอบที่เรียงตามลำดับประโยคภาษาไทยแล้ว
     *
     * เหตุการณ์เข้าออกระบบไม่มี "เป้าหมาย" ที่มีความหมาย เพราะเป้าหมายคือตัวผู้ใช้เอง
     * จึงต้องไม่แสดงชื่อซ้ำสองครั้ง แต่ให้บอกหมายเลข IP แทนซึ่งเป็นสิ่งที่คนตรวจสอบต้องการจริง
     *
     * @return array{actor: string, action: string, subject: ?string, target: ?string, meta: ?string}
     */
    public static function describe(ActivityLog $log): array
    {
        $actor = $log->user?->name ?? 'ผู้ใช้ที่ถูกลบแล้ว';

        if (self::isAuthAction($log->action)) {
            $attempted = data_get($log->changes, 'username');

            return [
                'actor' => $log->user?->name ?? ($attempted ? 'บัญชี '.$attempted : 'ผู้ใช้ที่ไม่รู้จัก'),
                'action' => self::actionLabel($log->action),
                'subject' => null,
                'target' => null,
                'meta' => $log->ip_address ? 'จากหมายเลข IP '.$log->ip_address : null,
            ];
        }

        $target = self::targetName(self::changeSets($log)['raw'], $log->description);
        $subject = $log->subject_type ? self::subjectLabel($log->subject_type) : null;

        return [
            'actor' => $actor,
            'action' => self::actionLabel($log->action),
            'subject' => $subject,
            // ถ้าชื่อเป้าหมายซ้ำกับป้ายประเภทอยู่แล้ว การแสดงทั้งคู่จะอ่านเป็นคำซ้ำ
            'target' => $target === $subject ? null : $target,
            'meta' => $log->ip_address ? 'จากหมายเลข IP '.$log->ip_address : null,
        ];
    }

    /**
     * คำอธิบายอายุคงเหลือของรายการในถังขยะ เป็นประโยคที่บอกผลลัพธ์ ไม่ใช่ตัวเลขลอย ๆ
     */
    public static function retentionNote(?int $daysLeft, bool $canRestore): string
    {
        if ($daysLeft === null) {
            return $canRestore ? 'ไม่มีกำหนดลบถาวร กู้คืนได้' : 'ไม่มีกำหนดลบถาวร';
        }

        if ($daysLeft <= 0) {
            return 'ครบกำหนดแล้ว กู้คืนไม่ได้';
        }

        $note = 'เหลืออีก '.$daysLeft.' วันก่อนลบถาวร';

        return $canRestore ? $note : $note.' (กู้คืนไม่ได้)';
    }

    /**
     * แปลง payload ของถังขยะเป็นคู่ "ป้าย → ค่า" ที่คนอ่านได้
     *
     * เดิมหน้าถังขยะ dump JSON ดิบทั้งก้อน ซึ่งอ่านไม่ออกสำหรับผู้ดูแลที่ไม่ใช่นักพัฒนา
     * เมธอดนี้เลือกเฉพาะฟิลด์ที่มีป้ายภาษาไทยและมีค่าอยู่จริง ส่วน JSON เต็มยังเปิดดูได้จากหน้าเดียวกัน
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function readableTrashPayload(TrashLog $trash): array
    {
        $data = self::mainTrashPayload($trash);
        $labels = self::fieldLabels();

        return collect($data)
            ->reject(fn ($value, $key) => in_array((string) $key, self::HIDDEN_FIELDS, true))
            ->filter(fn ($value) => $value !== null && $value !== '' && ! is_array($value))
            ->filter(fn ($value, $key) => isset($labels[$key]))
            ->map(fn ($value, $key) => [
                'label' => $labels[$key],
                'value' => self::formatValue((string) $key, $value),
            ])
            ->values()
            ->all();
    }

    /**
     * payload ของถังขยะห่อค่าไว้คนละคีย์ตามชนิดข้อมูล จึงต้องคลี่ให้ตรงชนิดก่อนอ่าน
     *
     * @return array<string, mixed>
     */
    private static function mainTrashPayload(TrashLog $trash): array
    {
        $payload = $trash->payload_json ?? [];

        if (! is_array($payload)) {
            return [];
        }

        $candidates = match ($trash->entity_type) {
            User::class => ['user', 'attributes'],
            WorkOrder::class => ['work_order', 'attributes'],
            WorkOrderList::class => ['list', 'attributes'],
            default => ['attributes'],
        };

        foreach ($candidates as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return $payload;
    }

    /**
     * activity log เก็บรูปโปรไฟล์ไว้เป็น "path ณ ตอนนั้น" ไม่ใช่สำเนาไฟล์ และ
     * UserController/SettingsController จะลบไฟล์เดิมทิ้งทุกครั้งที่เปลี่ยนรูป
     * path เก่าจึงมักชี้ไปยังไฟล์ที่ไม่มีอยู่แล้ว
     *
     * เมธอดนี้คืนเฉพาะ path ที่ยังเปิดดูได้จริง คือยังเป็นรูปโปรไฟล์ปัจจุบันของผู้ใช้
     * (เงื่อนไขเดียวกับที่ MediaController::legacy() ใช้ resolve) และไฟล์ยังอยู่บนดิสก์
     * เพื่อให้ view เลือกแสดง placeholder แทนการยิง <img> ที่จะได้ 404
     *
     * @param  iterable<int, ActivityLog>  $logs
     * @return Collection<string, int> path => index สำหรับเรียก has() แบบ O(1)
     */
    public static function resolvableProfileImages(iterable $logs): Collection
    {
        $paths = collect($logs)
            ->flatMap(function (ActivityLog $log): array {
                $changes = is_array($log->changes)
                    ? $log->changes
                    : json_decode($log->changes ?? '[]', true);

                return [
                    data_get($changes, 'before.profile_image'),
                    data_get($changes, 'old.profile_image'),
                    data_get($changes, 'after.profile_image'),
                    data_get($changes, 'new.profile_image'),
                ];
            })
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->unique()
            ->values();

        if ($paths->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('profile_image', $paths)
            ->pluck('profile_image')
            ->filter(fn (string $path) => ProtectedMedia::profileAbsolutePath($path) !== null)
            ->values()
            ->flip();
    }
}
