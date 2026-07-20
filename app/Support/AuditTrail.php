<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\TrashLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditTrail
{
    public static function log(string $action, ?Model $subject = null, ?string $description = null, ?array $changes = null): void
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'changes' => $changes,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    public static function trash(Model $entity, ?User $deletedBy = null, ?array $payload = null): void
    {
        TrashLog::create([
            'entity_type' => $entity::class,
            'entity_id' => (int) $entity->getKey(),
            'payload_json' => $payload ?? $entity->attributesToArray(),
            'deleted_by' => $deletedBy?->id ?? Auth::id(),
            'deleted_at' => now(),
            'purge_after' => now()->addDays(30),
        ]);
    }
}
