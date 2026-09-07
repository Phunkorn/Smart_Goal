<?php

namespace App\Support;

use App\Models\TrashLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderList;
use App\Models\WorkOrderListAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ถังขยะ 30 วัน — กู้คืน ลบถาวร และเก็บกวาดไฟล์
 *
 * ชนิดข้อมูลที่รองรับและวิธีกู้คืนอยู่ที่ App\Support\TrashRestorers ที่เดียว
 * คลาสนี้เหลือหน้าที่ลงมือทำตามทะเบียนนั้น
 */
class TrashRetention
{
    public static function summary(TrashLog $trash): array
    {
        $payload = $trash->payload_json ?? [];
        $entity = class_basename((string) $trash->entity_type);
        $data = self::mainPayload($trash);

        $name = match ($trash->entity_type) {
            User::class => $data['name'] ?? 'พนักงาน #'.$trash->entity_id,
            WorkOrder::class => $data['job_topic'] ?? 'งาน #'.$trash->entity_id,
            WorkOrderList::class => $data['name'] ?? 'รายการ #'.$trash->entity_id,
            default => $data['original_name']
                ?? $data['name']
                ?? $data['job_topic']
                ?? $data['title']
                ?? $data['department_name']
                ?? $entity.' #'.$trash->entity_id,
        };

        $department = $payload['assignee']['department']['department_name']
            ?? $payload['user']['department_name']
            ?? $data['department_name']
            ?? $data['department_id']
            ?? '-';

        return [
            'entity_label' => TrashRestorers::labelFor($trash->entity_type),
            'name' => $name,
            'department' => is_numeric($department) ? 'แผนก #'.$department : $department,
            'short_description' => Str::limit($name, 80),
            'days_left' => self::daysLeft($trash),
            'can_restore' => self::canRestore($trash),
            'is_file' => TrashRestorers::ownsFile($trash->entity_type),
        ];
    }

    public static function restore(TrashLog $trash): Model
    {
        return DB::transaction(function () use ($trash) {
            $model = self::restoreModel($trash);

            AuditTrail::log('restored', $model, 'กู้คืนข้อมูลที่ถูกลบ: '.self::summary($trash)['name'], [
                'trash_log_id' => $trash->id,
                'entity_type' => $trash->entity_type,
                'entity_id' => $trash->entity_id,
            ]);

            $trash->delete();

            return $model;
        });
    }

    /**
     * ลบถาวรทันทีตามคำสั่งของผู้ดูแลระบบ โดยไม่รอครบ 30 วัน
     *
     * แยกจาก purgeExpired() เพราะที่นี่คือการตัดสินใจของคน ไม่ใช่การหมดอายุตามเวลา
     * และต้องมีบันทึกกิจกรรมกำกับว่าใครเป็นคนสั่ง
     */
    public static function purge(TrashLog $trash, ?User $actor = null): void
    {
        $summary = self::summary($trash);

        DB::transaction(function () use ($trash, $summary, $actor) {
            self::forceDeleteEntity($trash);

            AuditTrail::log('purged', null, 'ลบถาวร: '.$summary['name'], [
                'entity_type' => $trash->entity_type,
                'entity_id' => $trash->entity_id,
                'purged_by' => $actor?->id,
                'before' => $trash->payload_json,
            ]);

            $trash->delete();
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

        return TrashRestorers::supports($trash->entity_type);
    }

    private static function restoreModel(TrashLog $trash): Model
    {
        abort_unless(self::canRestore($trash), 422, 'รายการนี้ไม่สามารถกู้คืนได้');

        /** @var class-string<Model> $class */
        $class = $trash->entity_type;

        if (TrashRestorers::strategyFor($class) === TrashRestorers::SOFT) {
            $model = self::findWithTrashed($class, $trash->entity_id);
            abort_unless($model, 404, 'ไม่พบข้อมูลต้นฉบับสำหรับกู้คืน');

            if (method_exists($model, 'trashed') && $model->trashed()) {
                $model->restore();
            }

            return $model->refresh();
        }

        // กลยุทธ์ PAYLOAD — แถวหายจากฐานข้อมูลแล้ว ต้องสร้างใหม่จากสำเนา
        $existing = $class::query()->find($trash->entity_id);

        if ($existing) {
            return $existing;
        }

        $payload = self::mainPayload($trash);
        abort_unless($payload !== [], 422, 'ไม่มีข้อมูลสำรองสำหรับกู้คืนรายการนี้');

        $model = new $class;
        $model->forceFill($payload);
        $model->save();

        self::restoreChildAttachments($trash, $model);

        return $model;
    }

    /**
     * สร้างแถวไฟล์แนบของโปรเจกต์กลับมาหลังกู้คืนตัวโปรเจกต์
     *
     * โปรเจกต์ไม่มี SoftDeletes แถวไฟล์แนบจึงถูก FK cascade ลบไปพร้อมกัน
     * ตัวไฟล์บนดิสก์ยังอยู่ (MyTaskController::destroyList ตั้งใจไม่แตะ) จึงต้อง
     * สร้างเฉพาะแถวกลับมาชี้ไฟล์เดิม
     */
    private static function restoreChildAttachments(TrashLog $trash, Model $model): void
    {
        if ($trash->entity_type !== WorkOrderList::class) {
            return;
        }

        foreach ($trash->payload_json['attachments'] ?? [] as $row) {
            if (! is_array($row) || ! isset($row['file_path'])) {
                continue;
            }

            $attachment = new WorkOrderListAttachment;
            $attachment->forceFill($row);
            $attachment->save();
        }
    }

    /**
     * ลบข้อมูลออกจากฐานข้อมูลจริง พร้อมเก็บกวาดไฟล์บนดิสก์
     *
     * ต้องลบไฟล์แนบลูกผ่าน Eloquent ทีละตัว "ก่อน" ลบตัวแม่ เพราะ FK แบบ
     * cascadeOnDelete ลบแถวลูกให้จริงแต่ไม่ยิง Eloquent event ไฟล์จึงไม่ถูกแตะ
     * และค้างอยู่ใน storage โดยไม่มีอะไรอ้างถึงและไม่มีใครลบได้อีกตลอดไป
     */
    private static function forceDeleteEntity(TrashLog $trash): void
    {
        if (! TrashRestorers::supports($trash->entity_type)) {
            return;
        }

        /** @var class-string<Model> $class */
        $class = $trash->entity_type;
        $model = self::findWithTrashed($class, $trash->entity_id);

        if (! $model) {
            // แถวหายไปแล้ว แต่ไฟล์ที่ payload อ้างถึงอาจยังอยู่
            self::deletePayloadFiles($trash);

            return;
        }

        foreach (TrashRestorers::fileRelationsFor($class) as $relation) {
            if (! method_exists($model, $relation)) {
                continue;
            }

            $children = $model->{$relation}();

            if (in_array(SoftDeletes::class, class_uses_recursive($children->getModel()), true)) {
                $children = $children->withTrashed();
            }

            $children->get()->each(fn (Model $child) => $child->forceDelete());
        }

        self::forgetProfileImage($model);

        if (method_exists($model, 'forceDelete')) {
            $model->forceDelete();

            return;
        }

        $model->delete();
    }

    /**
     * รูปโปรไฟล์ถูกเก็บไว้จนถึงตอนนี้เพื่อให้กู้คืนบัญชีแล้วรูปยังอยู่
     */
    private static function forgetProfileImage(Model $model): void
    {
        if (! $model instanceof User || ! $model->profile_image) {
            return;
        }

        Storage::disk('public')->delete($model->profile_image);
    }

    /**
     * เก็บกวาดไฟล์ของแถวที่หายไปจากฐานข้อมูลแล้ว โดยอ่าน path จากสำเนาใน payload
     */
    private static function deletePayloadFiles(TrashLog $trash): void
    {
        $payload = self::mainPayload($trash);

        if (isset($payload['file_path']) && is_string($payload['file_path'])) {
            ProtectedMedia::deleteAttachment($payload['file_path']);
        }

        foreach ($trash->payload_json['attachments'] ?? [] as $row) {
            if (is_array($row) && isset($row['file_path']) && is_string($row['file_path'])) {
                ProtectedMedia::deleteAttachment($row['file_path']);
            }
        }
    }

    /**
     * @param  class-string<Model>  $class
     */
    private static function findWithTrashed(string $class, int|string $id): ?Model
    {
        $query = $class::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    /**
     * แผนกที่เลือกกรองได้ในถังขยะ
     *
     * payload เก็บชื่อแผนกไว้คนละตำแหน่งตามชนิดข้อมูล จึงต้องกวาดทุกตำแหน่งที่เป็นไปได้
     * แล้วผสมกับรายชื่อตั้งต้น เพื่อให้ตัวเลือกไม่หายไปตอนถังขยะยังว่าง
     *
     * @return Collection<int, string>
     */
    public static function departmentOptions(): Collection
    {
        return collect(['IT', 'Marketing', 'Account', 'Callcenter'])
            ->merge(TrashLog::query()->pluck('payload_json')->flatMap(function ($payload) {
                $payload = is_string($payload) ? json_decode($payload, true) : $payload;

                return [
                    $payload['work_order']['department_name'] ?? null,
                    $payload['user']['department_name'] ?? null,
                    $payload['assignee']['department']['department_name'] ?? null,
                ];
            }))
            ->filter()
            ->unique()
            ->values();
    }

    private static function mainPayload(TrashLog $trash): array
    {
        $payload = $trash->payload_json ?? [];

        if (! is_array($payload)) {
            return [];
        }

        $key = $trash->entity_type ? TrashRestorers::payloadKeyFor($trash->entity_type) : null;

        if ($key !== null && isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }

        return $payload['work_order']
            ?? $payload['user']
            ?? $payload['list']
            ?? $payload['attachment']
            ?? $payload;
    }
}
