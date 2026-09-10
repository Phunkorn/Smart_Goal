<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;

/**
 * รายชื่อคนที่เกี่ยวข้องกับงานหนึ่งใบ สำหรับแสดงในตารางรายงาน
 *
 * ป้าย "บทบาท" อย่างเดียวบอกได้แค่ประเภทความเกี่ยวข้อง แต่ตรวจสอบไม่ได้ว่า
 * มีการมอบหมายงานกันจริงหรือไม่ และใครเป็นหัวหน้าโปรเจกต์ที่รับผิดชอบผลงานชิ้นนั้น
 * คลาสนี้จึงคืน "ชื่อคน" ออกมาให้รายงานแสดงตรง ๆ ทั้งฝั่งหัวหน้าแผนกและฝั่งพนักงาน
 *
 * ผู้มอบหมายใช้ assigned_by ก่อนแล้วจึงตกไปที่ created_by เพราะงานที่นำเข้าจากระบบเดิม
 * บางใบไม่มี assigned_by แต่ยังบอกได้ว่าใครเป็นคนตั้งงาน และจะไม่แสดงเมื่อเป็นคนเดียว
 * กับผู้รับผิดชอบ เพราะงานที่สร้างเองไม่ได้เกิดจากการมอบหมาย
 */
final class TaskTeamSummary
{
    /**
     * @return array{
     *     assignee: array{name: string, is_me: bool}|null,
     *     leader: array{name: string, is_me: bool}|null,
     *     assigner: array{name: string, is_me: bool}|null,
     *     collaborators: list<array{name: string, is_me: bool}>,
     *     my_role: array{key: string, label: string, tone: string},
     *     is_delegated: bool,
     * }
     */
    public static function for(WorkOrder $job, int $viewerId): array
    {
        $assignerId = (int) ($job->assigned_by ?: $job->created_by);
        $isDelegated = $assignerId > 0 && $assignerId !== (int) $job->user_id;

        return [
            'assignee' => self::person($job->assignee, $viewerId),
            'leader' => self::person($job->leader, $viewerId),
            'assigner' => $isDelegated ? self::person($job->assigner ?? $job->creator, $viewerId) : null,
            'collaborators' => $job->collaborators
                ->filter(fn (User $member) => $member->pivot->status === 'accepted')
                ->map(fn (User $member) => self::person($member, $viewerId))
                ->values()
                ->all(),
            'my_role' => ContributionRole::for($job, $viewerId),
            'is_delegated' => $isDelegated,
        ];
    }

    /**
     * ความสัมพันธ์เหล่านี้เป็น nullOnDelete ได้ จึงต้องรองรับกรณีบัญชีถูกลบไปแล้ว
     *
     * @return array{name: string, is_me: bool}|null
     */
    private static function person(?User $user, int $viewerId): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'name' => $user->name,
            'is_me' => (int) $user->id === $viewerId,
        ];
    }
}
