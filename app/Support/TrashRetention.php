<?php

namespace App\Support;

use App\Models\TrashLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrashRetention
{
    public static function summary(TrashLog $trash): array
    {
        $payload = $trash->payload_json ?? [];
        $entity = class_basename($trash->entity_type);
        $data = self::mainPayload($trash);

        $name = match ($trash->entity_type) {
            User::class => $data['name'] ?? 'พนักงาน #'.$trash->entity_id,
            WorkOrder::class => $data['job_topic'] ?? 'งาน #'.$trash->entity_id,
            WorkOrderList::class => $data['name'] ?? 'รายการ #'.$trash->entity_id,
            default => $data['name'] ?? $data['job_topic'] ?? $entity.' #'.$trash->entity_id,
        };

        $department = $payload['assignee']['department']['department_name']
            ?? $payload['user']['department_name']
            ?? $data['department_name']
            ?? $data['department_id']
            ?? '-';

        return [
            'entity_label' => match ($trash->entity_type) {
                User::class => 'พนักงาน',
                WorkOrder::class => 'งาน',
                WorkOrderList::class => 'รายการงาน',
                default => $entity,
            },
            'name' => $name,
            'department' => is_numeric($department) ? 'แผนก #'.$department : $department,
            'short_description' => Str::limit($name, 80),
            'days_left' => self::daysLeft($trash),
            'can_restore' => self::canRestore($trash),
        ];
    }

    public static function restore(TrashLog $trash): Model
    {
        return DB::transaction(function () use ($trash) {
            $model = self::restoreModel($trash);

            AuditTrail::log('restored', $model, 'Restored deleted item: '.self::summary($trash)['name'], [
                'trash_log_id' => $trash->id,
                'entity_type' => $trash->entity_type,
                'entity_id' => $trash->entity_id,
            ]);

            $trash->delete();

            return $model;
        });
    }

    public static function purgeExpired(): int
    {
        $count = 0;

        TrashLog::whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($logs) use (&$count) {
                foreach ($logs as $trash) {
                    DB::transaction(function () use ($trash, &$count) {
                        self::forceDeleteEntity($trash);
                        $trash->delete();
                        $count++;
                    });
                }
            });

        return $count;
    }

    public static function daysLeft(TrashLog $trash): ?int
    {
        if (! $trash->purge_after) {
            return null;
        }

        return max(0, (int) now()->diffInDays($trash->purge_after, false));
    }

    public static function canRestore(TrashLog $trash): bool
    {
        if ($trash->purge_after && $trash->purge_after->isPast()) {
            return false;
        }

        if (! class_exists($trash->entity_type)) {
            return false;
        }

        return in_array($trash->entity_type, [User::class, WorkOrder::class, WorkOrderList::class], true);
    }

    private static function restoreModel(TrashLog $trash): Model
    {
        abort_unless(self::canRestore($trash), 422, 'รายการนี้ไม่สามารถกู้คืนได้');

        if (in_array($trash->entity_type, [User::class, WorkOrder::class], true)) {
            /** @var class-string<Model> $class */
            $class = $trash->entity_type;
            $model = $class::withTrashed()->find($trash->entity_id);
            abort_unless($model, 404, 'ไม่พบข้อมูลต้นฉบับสำหรับกู้คืน');

            if (method_exists($model, 'restore') && $model->trashed()) {
                $model->restore();
            }

            return $model->refresh();
        }

        if ($trash->entity_type === WorkOrderList::class) {
            $existing = WorkOrderList::find($trash->entity_id);
            if ($existing) {
                return $existing;
            }

            $payload = $trash->payload_json['list'] ?? [];
            abort_unless(! empty($payload), 422, 'ไม่มีข้อมูลรายการสำหรับกู้คืน');

            $list = new WorkOrderList;
            $list->forceFill($payload);
            $list->save();

            return $list;
        }

        abort(422, 'รายการนี้ไม่รองรับการกู้คืน');
    }

    private static function forceDeleteEntity(TrashLog $trash): void
    {
        if (! class_exists($trash->entity_type)) {
            return;
        }

        if (in_array($trash->entity_type, [User::class, WorkOrder::class], true)) {
            /** @var class-string<Model> $class */
            $class = $trash->entity_type;
            $model = $class::withTrashed()->find($trash->entity_id);

            if ($model && method_exists($model, 'forceDelete')) {
                $model->forceDelete();
            }
        }
    }

    private static function mainPayload(TrashLog $trash): array
    {
        $payload = $trash->payload_json ?? [];

        return $payload['work_order']
            ?? $payload['user']
            ?? $payload['list']
            ?? $payload;
    }
}
