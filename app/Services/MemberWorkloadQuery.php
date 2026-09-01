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
        $memberId = $member instanceof User ? $member->id : $member;

        return WorkOrder::query()->where(function (EloquentBuilder $query) use ($memberId): void {
            $query->where('user_id', $memberId)
                ->orWhereHas('collaborators', fn (EloquentBuilder $collaborators) => $collaborators
                    ->where('users.id', $memberId)
                    ->where('work_order_collaborators.status', self::ACCEPTED_COLLABORATOR_STATUS));
        });
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
