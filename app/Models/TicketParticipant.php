<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * การเข้าร่วม Ticket ของเจ้าหน้าที่หนึ่งคน
 *
 * ออกจาก Ticket = ตั้ง left_at ไม่ลบแถว เข้าใหม่ = ล้าง left_at
 * user_name เป็น snapshot สำหรับแสดงผลเมื่อบัญชีถูกลบถาวร
 * user_ref_id = user_id ตอนเข้าร่วม ไม่มี FK — ยังระบุตัวคนได้หลังบัญชีถูกลบถาวร (migration 2026_10_23_000008)
 * เขียนผ่าน App\Services\TicketParticipantService เท่านั้น
 */
class TicketParticipant extends Model
{
    protected $guarded = ['*'];

    /** insertOrIgnore ของ TicketParticipantService ไม่ผ่าน event นี้ จึงใส่ค่าเองที่นั่นด้วย */
    protected static function booted(): void
    {
        static::creating(function (self $participant): void {
            $participant->user_ref_id ??= $participant->user_id;
        });
    }

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }
}
