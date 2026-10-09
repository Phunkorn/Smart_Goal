<?php

namespace App\Support;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ใครถูกเชิญเข้าประชุมหรือนัดหมายได้ — แหล่งความจริงจุดเดียวของทั้งสอง domain
 *
 * ก่อนหน้านี้กติกานี้อยู่ใน MeetingQueryService ซึ่งชื่อผูกกับการประชุม พอนัดหมาย
 * ต้องเชิญคนได้ด้วย ทางเลือกมีแค่คัดลอกคิวรีไปอีกชุด (แล้วรอวันที่สองชุดไม่ตรงกัน)
 * หรือยกกติกาออกมาไว้ตรงกลาง — ที่นี่คือทางเลือกหลัง
 *
 * กติกา: บัญชีที่ยังไม่ถูกลบ เปิดใช้งานอยู่ และบทบาทอยู่ใน INVITABLE_ROLES
 *
 * ทำไม viewer ยังอยู่ในรายการ
 * ---------------------------------------------------------------
 * ค่านี้ยกมาจาก MeetingQueryService::ATTENDEE_ROLES เดิมทั้งชุดโดยตั้งใจ การตัด viewer
 * ออกจะเปลี่ยนพฤติกรรมของการประชุมที่ใช้งานอยู่ และขัดกับ
 * MeetingManagementTest::test_create_normalizes_duplicate_attendees_and_accepts_active_organizational_roles
 * ซึ่งยืนยันว่า viewer ถูกผูกเป็นผู้เข้าร่วมได้จริง
 *
 * ในทางปฏิบัติ viewer ถูกกันออกอยู่แล้วสองชั้นถัดไป: NotificationService::notifyDetached()
 * ไม่ส่งแจ้งเตือนให้ viewer และ policy ของทั้งสอง domain ไม่ให้ viewer ตอบรับ
 * จึงถูกผูกไว้ได้แต่ไม่มีส่วนร่วมในรอบการตอบรับ
 *
 * หมายเหตุ: browsableOptions() แสดงเฉพาะ role = user เพราะ admin/viewer ไม่ใช่
 * ผู้ถูกเชิญตามปกติ แต่ถ้าเคยถูกเชิญไว้แล้วต้องยังอยู่ในรายการต่อไป มิฉะนั้นการบันทึก
 * ฟอร์มซ้ำจะทำให้คนเหล่านั้นหลุดออกเงียบ ๆ
 */
final class InvitationCandidates
{
    private const INVITABLE_ROLES = ['admin', 'user', 'viewer'];

    /** คิวรีฐานของผู้ที่ถูกเชิญได้ ใช้ตรวจค่าที่ส่งมาจากฟอร์ม */
    public static function query(): Builder
    {
        return User::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('role', self::INVITABLE_ROLES);
    }

    /**
     * กรองรหัสที่ client ส่งมาให้เหลือเฉพาะคนที่เชิญได้จริง
     *
     * @param  array<int|string>  $candidateIds
     * @return array<int>
     */
    public static function eligibleIds(array $candidateIds): array
    {
        $ids = collect($candidateIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return self::query()
            ->whereKey($ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * รายชื่อที่แสดงให้เลือกในตัวเลือกบุคคล
     *
     * @param  array<int>  $includeIds  คนที่ถูกผูกไว้แล้วและต้องแสดงต่อไปแม้ไม่ใช่ role = user
     * @return Collection<int, User>
     */
    public static function browsableOptions(array $includeIds = []): Collection
    {
        $includeIds = collect($includeIds)->map(fn ($id) => (int) $id)->filter()->unique()->all();

        return self::query()
            ->where(fn (Builder $query) => $query
                ->where('role', 'user')
                ->when($includeIds !== [], fn (Builder $included) => $included->orWhereIn('id', $includeIds)))
            ->with('department:id,department_name')
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'department_id', 'profile_image']);
    }

    /** @return Collection<int, Department> */
    public static function departments(): Collection
    {
        return Department::query()->orderBy('department_name')->get(['id', 'department_name']);
    }
}
