<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MemberWorkloadQuery
{
    public const ACCEPTED_COLLABORATOR_STATUS = 'accepted';

    /** Direct workload excludes creator, leader and project-context visibility. */
    public function forMember(User|int $member): EloquentBuilder
    {
        return WorkOrder::query()->where(fn (EloquentBuilder $query) => $this->direct(
            $query,
            $member instanceof User ? $member->id : $member
        ));
    }

    /**
     * ชุดงานที่ Workspace ของสมาชิกต้อง "วาด" — ภาระงานตรงของเขา บวกงานแม่ของงานย่อยที่เขาทำ
     *
     * ต่างจาก forMember() ตรงที่ไม่ใช่คำตอบของ "ภาระงานของเขามีเท่าไร" ทุกมุมมองวาดงานย่อย
     * เป็นลูกของแถวงานแม่เท่านั้น เมื่อสมาชิกถูกเพิ่มเข้าเฉพาะงานย่อย งานแม่จะไม่อยู่ในชุด
     * ของ forMember() แล้วงานย่อยนั้นก็ไม่มีที่ยืน หน้าจึงว่างทั้งที่เขามีงานอยู่
     *
     * ตัวเลขภาระงาน (totals, statusCounts) ต้องนับจากงานที่ผ่าน isDirectWorkload() เท่านั้น
     * ไม่ใช่จากชุดนี้ เพราะงานแม่ที่ติดมาเป็นบริบท ไม่ใช่งานของสมาชิก
     */
    public function forMemberWorkspace(User|int $member): EloquentBuilder
    {
        $memberId = $member instanceof User ? (int) $member->id : (int) $member;

        return WorkOrder::query()->where(function (EloquentBuilder $query) use ($memberId): void {
            $query->where(fn (EloquentBuilder $direct) => $this->direct($direct, $memberId))
                ->orWhereHas('children', fn (EloquentBuilder $child) => $this->direct(
                    $child->where('approval_status', 'approved'),
                    $memberId
                ));
        });
    }

    /**
     * งานใบนี้เป็นภาระงานตรงของสมาชิกคนนี้หรือไม่ — คำถามเดียวกับ forMember() แต่ถามกับงานที่โหลดมาแล้ว
     *
     * ใช้แยก "งานของสมาชิก" ออกจาก "งานแม่ที่ติดมาเป็นบริบท" ตอนนับตัวเลขของ Workspace
     */
    public function isDirectWorkload(WorkOrder $task, User|int $member): bool
    {
        $memberId = $member instanceof User ? (int) $member->id : (int) $member;

        if ((int) $task->user_id === $memberId) {
            return true;
        }

        if ($task->relationLoaded('collaborators')) {
            return $task->collaborators->contains(
                fn ($person) => (int) $person->id === $memberId
                    && $person->pivot?->status === self::ACCEPTED_COLLABORATOR_STATUS
            );
        }

        return $task->collaborators()
            ->where('users.id', $memberId)
            ->wherePivot('status', self::ACCEPTED_COLLABORATOR_STATUS)
            ->exists();
    }

    /** เงื่อนไข "ภาระงานตรง" ที่ forMember() และ forMemberWorkspace() ใช้ร่วมกัน */
    private function direct(EloquentBuilder $query, int $memberId): EloquentBuilder
    {
        return $query->where('user_id', $memberId)
            ->orWhereHas('collaborators', fn (EloquentBuilder $collaborators) => $collaborators
                ->where('users.id', $memberId)
                ->where('work_order_collaborators.status', self::ACCEPTED_COLLABORATOR_STATUS));
    }

    /**
     * Unique (member_id, work_order_id) pairs for aggregate directory queries.
     *
     * @param  iterable<int, int|string>  $memberIds
     */
    public function memberships(iterable $memberIds): QueryBuilder
    {
        $ids = Collection::make($memberIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        $assignees = DB::table('work_orders')
            ->selectRaw('user_id as member_id, job_id as work_order_id')
            ->whereNull('deleted_at')
            ->whereIn('user_id', $ids);

        $collaborators = DB::table('work_order_collaborators')
            ->join('work_orders', 'work_orders.job_id', '=', 'work_order_collaborators.work_order_id')
            ->selectRaw('work_order_collaborators.user_id as member_id, work_order_collaborators.work_order_id')
            ->whereNull('work_orders.deleted_at')
            ->where('work_order_collaborators.status', self::ACCEPTED_COLLABORATOR_STATUS)
            ->whereIn('work_order_collaborators.user_id', $ids);

        return $assignees->union($collaborators);
    }
}
