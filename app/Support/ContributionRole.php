<?php

namespace App\Support;

use App\Models\WorkOrder;

/**
 * บทบาทของผู้ใช้คนหนึ่งที่มีต่องานหนึ่งใบ
 *
 * รายงานทุกหน้านับ "ผลงาน" ด้วยนิยามเดียวกับ WorkOrder::scopeContributedBy()
 * คือผู้รับผิดชอบ ผู้สร้าง หัวหน้างาน หรือผู้ร่วมงานที่ตอบรับแล้ว
 * เมื่อรวมสี่ทางเข้าด้วยกัน ตัวเลขรวมจะกลืนความต่างว่างานใบไหนเรา "ทำเอง"
 * และใบไหนเรา "ไปร่วม" คลาสนี้จึงคืนบทบาทกลับมาให้ตารางและ CSV แสดงกำกับได้
 *
 * ลำดับความสำคัญไล่จากความรับผิดชอบมากไปน้อย และคืนบทบาทเดียวต่อหนึ่งงาน
 * เพราะคนหนึ่งเป็นได้หลายบทบาทพร้อมกัน (เช่นสร้างงานแล้วมอบหมายให้ตัวเอง)
 */
final class ContributionRole
{
    public const OWNER = 'owner';

    public const LEADER = 'leader';

    public const CREATOR = 'creator';

    public const COLLABORATOR = 'collaborator';

    private const META = [
        self::OWNER => ['label' => 'ผู้รับผิดชอบ', 'tone' => 'blue'],
        self::LEADER => ['label' => 'หัวหน้างาน', 'tone' => 'purple'],
        self::CREATOR => ['label' => 'ผู้สร้างงาน', 'tone' => 'cyan'],
        self::COLLABORATOR => ['label' => 'ผู้ร่วมงาน', 'tone' => 'teal'],
    ];

    /**
     * @return array{key: string, label: string, tone: string}
     */
    public static function for(WorkOrder $job, int $userId): array
    {
        $key = self::keyFor($job, $userId);

        return ['key' => $key, ...self::META[$key]];
    }

    public static function label(WorkOrder $job, int $userId): string
    {
        return self::META[self::keyFor($job, $userId)]['label'];
    }

    /**
     * งานที่ผู้ใช้ถือความรับผิดชอบหลัก ใช้แยก KPI ออกจากงานที่ไปร่วมกับคนอื่น
     */
    public static function isPrimary(WorkOrder $job, int $userId): bool
    {
        return (int) $job->user_id === $userId;
    }

    private static function keyFor(WorkOrder $job, int $userId): string
    {
        if ((int) $job->user_id === $userId) {
            return self::OWNER;
        }

        if ((int) $job->leader_user_id === $userId) {
            return self::LEADER;
        }

        if ((int) $job->created_by === $userId) {
            return self::CREATOR;
        }

        return self::COLLABORATOR;
    }
}
