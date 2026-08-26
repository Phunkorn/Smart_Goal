<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * รายชื่อผู้ที่เลือกเป็นผู้ร่วมงานได้ — แหล่งความจริงจุดเดียว
 *
 * เดิมกติกาแตกกันสองหน้า: MyTaskController ไม่กรอง is_active แต่ตัดตัวเองออก
 * ส่วน WorkBoardController กรอง is_active แต่ไม่ตัดตัวเอง ทำให้ผู้ใช้เห็นรายชื่อไม่เท่ากัน
 * ทั้งที่เป็น Task Workspace เดียวกัน
 *
 * กติกาที่ใช้: role = user, เปิดใช้งานอยู่, และไม่ใช่ตัวผู้เรียกเอง
 * (server ก็ตัดผู้เรียกออกอยู่แล้วที่ TaskCollaboratorController::addCollaborators)
 */
final class TaskCollaboratorOptions
{
    /**
     * @return Collection<int, User>
     */
    public static function forActor(?User $actor): Collection
    {
        return User::query()
            ->with('department:id,department_name')
            ->where('role', 'user')
            ->where('is_active', true)
            ->when($actor, fn ($query) => $query->where('id', '!=', $actor->id))
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'department_id', 'profile_image']);
    }
}
