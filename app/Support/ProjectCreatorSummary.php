<?php

namespace App\Support;

use App\Models\WorkOrder;
use Illuminate\Support\Collection;

final class ProjectCreatorSummary
{
    public static function forListIds(Collection $listIds): Collection
    {
        $ids = $listIds->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return WorkOrder::query()
            ->whereIn('work_order_list_id', $ids)
            ->with('creator:id,name,role')
            ->get(['job_id', 'work_order_list_id', 'created_by'])
            ->groupBy('work_order_list_id')
            ->map(function (Collection $tasks): array {
                $creators = $tasks->pluck('creator')->filter();
                $uniqueCreators = $creators->unique('id')->values();
                $uniformAdmin = $creators->count() === $tasks->count()
                    && $uniqueCreators->count() === 1
                    && $uniqueCreators->first()?->role === 'admin';

                return [
                    'uniform_admin_id' => $uniformAdmin ? $uniqueCreators->first()->id : null,
                    'uniform_admin_name' => $uniformAdmin ? $uniqueCreators->first()->name : null,
                    'has_mixed_creators' => ! $uniformAdmin,
                ];
            });
    }
}
