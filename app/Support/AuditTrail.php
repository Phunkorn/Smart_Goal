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

    /**
     * เหตุการณ์การเข้าออกระบบ
     *
     * ต้องเป็นเมธอดแยกจาก log() เพราะ log() อ่านผู้ทำรายการจาก Auth::id()
     * ซึ่งเป็น null เสมอตอนเข้าสู่ระบบไม่สำเร็จ และตอน logout ก็ต้องบันทึกก่อนที่ session จะถูกล้าง
     *
     * เก็บเฉพาะชื่อผู้ใช้ที่กรอกเข้ามากับเหตุผล ห้ามเก็บรหัสผ่านหรือ payload ของ request
     * เพราะบันทึกนี้ผู้ดูแลระบบทุกคนเปิดอ่านได้
     */
    public static function authEvent(string $action, ?User $user, string $username, ?string $reason = null): void
    {
        ActivityLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $user ? User::class : null,
            'subject_id' => $user?->id,
            'description' => $reason ?? self::authDescription($action, $user?->name ?? $username),
            'changes' => array_filter([
                'username' => $username,
                'reason' => $reason,
            ], fn ($value) => $value !== null && $value !== ''),
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    private static function authDescription(string $action, string $who): string
    {
        return match ($action) {
            'login' => 'เข้าสู่ระบบ: '.$who,
            'logout' => 'ออกจากระบบ: '.$who,
            'login_failed' => 'เข้าสู่ระบบไม่สำเร็จ: '.$who,
            'login_locked' => 'ถูกล็อกชั่วคราวจากการพยายามเข้าสู่ระบบหลายครั้ง: '.$who,
            default => $action.': '.$who,
        };
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
