<?php

namespace App\Support;

use App\Models\User;

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
         * ยังไม่มีตัวเลือก "ทั้งแผนก" ที่นี่โดยตั้งใจ
         *
         * WorkOrder::scopeVisibleInProjectsFor() ให้หัวหน้าแผนกเห็นเท่าผู้ใช้ทั่วไป
         * คือเฉพาะงานที่ตนเกี่ยวข้อง ตัวกรองนี้ทำได้แค่ "แคบลง" จากสิ่งที่เห็นอยู่แล้ว
         * ถ้าใส่ตัวเลือกนี้ไปตอนนี้ หัวหน้าจะกดแล้วได้ผลเกือบเท่างานของตัวเอง ซึ่งหลอกผู้ใช้
         * การทำให้ถูกต้องต้องขยายขอบเขตการมองเห็น ซึ่งเป็นการแก้สิทธิ์ ไม่ใช่แก้ตัวกรอง
         */

        return $options;
    }

    /** @return array{label: string, description: string, icon: string} */
    public static function active(User $user, string $scope): array
    {
        foreach (self::forUser($user) as $option) {
            if ($option['value'] === $scope) {
                return ['label' => $option['label'], 'description' => $option['description'], 'icon' => $option['icon']];
            }
        }

        $fallback = self::forUser($user)[0];

        return ['label' => $fallback['label'], 'description' => $fallback['description'], 'icon' => $fallback['icon']];
    }
}
