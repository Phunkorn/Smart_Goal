<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ถ้อยคำของตัวกรอง "ขอบเขตงาน" ในหน้างานของฉัน — แหล่งเดียวสำหรับทุก role
 *
 * ปัญหาเดิมคือชื่อตัวเลือกอธิบายด้วยภาษาฐานข้อมูล ("งานที่ฉันสร้าง" / "งานที่ฉันมอบหมาย")
 * ซึ่งซ้อนทับกันในทางปฏิบัติ เพราะ assigned_by ถูกเซ็ตทุกครั้งที่สร้างงาน
 * งานที่สร้างแล้วมอบให้คนอื่นจึงเข้าเงื่อนไขทั้งสองข้อ ผู้ใช้กดสองตัวเลือกแล้วเห็นงานชุดเดียวกัน
 *
 * ที่นี่จึงเปลี่ยนแกนคำถามเป็น "ต้องลงมือทำ" กับ "ต้องติดตาม" แล้วอธิบายกำกับทุกตัวเลือก
 * เพื่อให้ความต่างชัดโดยไม่ต้องเดาจากชื่อ
 */
class TaskScopeOptions
{
    public const GROUP_MINE = 'ฉันต้องลงมือทำ';

    public const GROUP_WATCHING = 'ฉันต้องติดตาม';

    public const GROUP_SUBJECT = 'บทบาทในงาน';

    /**
     * @return array<int, array{value: string, label: string, description: string, group: ?string, icon: string}>
     */
    public static function forUser(User $user): array
    {
        $options = [
            [
                'value' => 'all',
                'label' => 'งานทั้งหมด',
                'description' => 'ทุกงานที่คุณมีสิทธิ์เห็น',
                'group' => null,
                'icon' => 'bi-collection',
            ],
            [
                'value' => 'today',
                'label' => 'งานของวันนี้',
                'description' => 'งานที่อยู่ในช่วงวันทำงานวันนี้ งานค้าง และงานที่เพิ่งปิดวันนี้',
                'group' => null,
                'icon' => 'bi-calendar-day',
            ],
            [
                'value' => 'responsible',
                'label' => 'ฉันรับผิดชอบเอง',
                'description' => 'งานที่คุณเป็นผู้รับผิดชอบหลัก',
                'group' => self::GROUP_MINE,
                'icon' => 'bi-person-check',
            ],
            [
                'value' => 'collaborating',
                'label' => 'ฉันถูกชวนมาร่วมทำ',
                'description' => 'งานของคนอื่นที่คุณตอบรับเข้าร่วมแล้ว',
                'group' => self::GROUP_MINE,
                'icon' => 'bi-people',
            ],
            [
                'value' => 'assigned_by_me',
                'label' => 'ฉันสั่งให้คนอื่นทำ',
                'description' => 'งานที่คุณมอบหมายออกไป และคนอื่นเป็นผู้รับผิดชอบ',
                'group' => self::GROUP_WATCHING,
                'icon' => 'bi-send',
            ],
            [
                'value' => 'created',
                'label' => 'ฉันเป็นคนเปิดงานไว้',
                'description' => 'ทุกงานที่คุณสร้าง ไม่ว่าใครจะเป็นผู้รับผิดชอบ',
                'group' => self::GROUP_WATCHING,
                'icon' => 'bi-pencil-square',
            ],
        ];

        /*
         * "ทั้งแผนก" มีเฉพาะหัวหน้าแผนก
         *
         * ตัวกรองทำได้แค่ "แคบลง" จากสิ่งที่ผู้ใช้เห็นอยู่แล้วเสมอ ตัวเลือกนี้จึงมีความหมาย
         * ก็ต่อเมื่อขอบเขตการมองเห็นครอบคลุมงานของทั้งแผนกจริง ซึ่งตอนนี้เป็นเช่นนั้นแล้ว
         * (WorkOrder::scopeVisibleInProjectsFor() ให้หัวหน้าเห็นงานที่อนุมัติแล้วของแผนกตัวเอง
         * ตรงกับที่ WorkOrderPolicy::view() อนุญาตไว้อยู่ก่อนแล้ว)
         *
         * ผู้ใช้ทั่วไปไม่มีตัวเลือกนี้ เพราะกดแล้วจะได้ผลเท่ากับ "งานทั้งหมด" ซึ่งหลอกผู้ใช้
         */
        if ($user->isDepartmentHead()) {
            $options[] = [
                'value' => 'department',
                'label' => 'งานทั้งแผนก',
                'description' => 'งานของทุกคนในแผนกที่คุณดูแล รวมงานที่ปิดไปแล้ว',
                'group' => self::GROUP_WATCHING,
                'icon' => 'bi-diagram-3',
            ];
        }

        return $options;
    }

    /**
     * ตัวเลือกเดียวกันแต่เขียนในมุม "คนที่เรากำลังดูงานของเขา"
     *
     * Admin และหัวหน้าแผนกเปิด Member Workspace เพื่อดูงานของสมาชิก ไม่ใช่ของตัวเอง
     * ถ้อยคำจึงต้องพูดถึงสมาชิกคนนั้น ไม่ใช่ "ฉัน" มิฉะนั้นผู้ดูจะเข้าใจว่ากำลังกรองงานตัวเอง
     *
     * มีเพียงสามตัวเลือกเพราะ MemberWorkloadQuery::forMember() คืนเฉพาะงานที่สมาชิก
     * เป็นผู้รับผิดชอบหรือเป็นผู้ร่วมงานที่ตอบรับแล้ว ตัวเลือก "สั่งให้คนอื่นทำ" หรือ
     * "เป็นคนเปิดงานไว้" จึงไม่มีงานให้กรองในหน้านี้ และจะกลายเป็นตัวเลือกที่กดแล้วว่างเปล่า
     *
     * @return array<int, array{value: string, label: string, description: string, group: ?string, icon: string}>
     */
    public static function forSubject(User $subject): array
    {
        $name = $subject->name;

        return [
            [
                'value' => 'all',
                'label' => 'งานทั้งหมด',
                'description' => 'ทุกงานของ '.$name.' ที่คุณมีสิทธิ์เห็น',
                'group' => null,
                'icon' => 'bi-collection',
            ],
            [
                'value' => 'today',
                'label' => 'งานของวันนี้',
                'description' => 'งานของ '.$name.' ที่อยู่ในช่วงวันทำงานวันนี้ งานค้าง และงานที่เพิ่งปิดวันนี้',
                'group' => null,
                'icon' => 'bi-calendar-day',
            ],
            [
                'value' => 'responsible',
                'label' => $name.' รับผิดชอบเอง',
                'description' => 'งานที่ '.$name.' เป็นผู้รับผิดชอบหลัก',
                'group' => self::GROUP_SUBJECT,
                'icon' => 'bi-person-check',
            ],
            [
                'value' => 'collaborating',
                'label' => $name.' ถูกชวนมาร่วมทำ',
                'description' => 'งานของคนอื่นที่ '.$name.' ตอบรับเข้าร่วมแล้ว',
                'group' => self::GROUP_SUBJECT,
                'icon' => 'bi-people',
            ],
        ];
    }

    /** @return array{label: string, description: string, icon: string} */
    public static function activeForSubject(User $subject, string $scope): array
    {
        return self::pick(self::forSubject($subject), $scope);
    }

    /** ค่าที่ใช้ได้จริงของชุดตัวเลือกหนึ่ง ๆ — ค่านอกรายการต้องตกกลับเป็น 'all' เสมอ */
    public static function normalize(array $options, mixed $scope): string
    {
        $available = array_column($options, 'value');

        return is_string($scope) && in_array($scope, $available, true) ? $scope : 'all';
    }

    /**
     * เงื่อนไขจริงของแต่ละขอบเขต — แหล่งเดียวที่ทั้งหน้า "งานของฉัน" และ Member Workspace ใช้
     *
     * รับ User ที่เป็น "เจ้าของขอบเขต" ซึ่งไม่จำเป็นต้องเป็นผู้ที่ล็อกอิน:
     * Member Workspace ส่งสมาชิกที่กำลังถูกดูเข้ามา ไม่ใช่ Admin หรือหัวหน้าที่เปิดหน้า
     */
    /**
     * ขอบเขตที่ตัดสินจาก "วันทำงานวันนี้" ซึ่งเป็นกติกาเวลาไทย ไม่ใช่เงื่อนไข SQL
     *
     * นิยามของวันนี้อยู่ที่ TodayWorkspace::tasks() ที่เดียว (งานในช่วงวันเริ่ม–กำหนดส่ง
     * บวกงานค้างและงานที่เพิ่งปิดวันนี้) การเขียนซ้ำเป็น where ใน SQL จะทำให้มีสองนิยาม
     * ตัวเรียกจึงต้องกรองใน memory ด้วย TodayWorkspace แทนการส่งเข้ามาที่ apply()
     */
    public static function isBusinessDayScope(string $scope): bool
    {
        return $scope === 'today';
    }

    public static function apply(Builder $query, User $user, string $scope): Builder
    {
        return match ($scope) {
            'responsible' => $query->where('user_id', $user->id),
            'created' => $query->where('created_by', $user->id),
            'assigned_by_me' => $query
                ->where('assigned_by', $user->id)
                ->where('user_id', '!=', $user->id),
            'collaborating' => $query->whereHas('collaborators', fn (Builder $collaborators) => $collaborators
                ->where('users.id', $user->id)
                ->where('work_order_collaborators.status', 'accepted')),
            // ใช้นิยาม "ปลายทางเป็นแผนกนี้" ชุดเดียวกับ scopeVisibleInProjectsFor()
            'department' => $query->inDepartmentOf($user),
            default => $query,
        };
    }

    /** @return array{label: string, description: string, icon: string} */
    private static function pick(array $options, string $scope): array
    {
        foreach ($options as $option) {
            if ($option['value'] === $scope) {
                return ['label' => $option['label'], 'description' => $option['description'], 'icon' => $option['icon']];
            }
        }

        $fallback = $options[0];

        return ['label' => $fallback['label'], 'description' => $fallback['description'], 'icon' => $fallback['icon']];
    }

    /** @return array{label: string, description: string, icon: string} */
    public static function active(User $user, string $scope): array
    {
        return self::pick(self::forUser($user), $scope);
    }
}
