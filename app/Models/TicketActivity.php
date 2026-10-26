<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * หนึ่งเหตุการณ์ใน timeline ของ Ticket — เพิ่มได้อย่างเดียว
 */
class TicketActivity extends Model
{
    public const TYPE_CREATED = 'created';

    public const TYPE_JOINED = 'joined';

    public const TYPE_LEFT = 'left';

    public const TYPE_STATUS_CHANGED = 'status_changed';

    public const TYPE_NOTE = 'note';

    public const TYPE_COORDINATOR_SET = 'coordinator_set';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * ตัวตนทางประวัติ: user_ref_id = user_id ตอนเกิดเหตุการณ์ และไม่มี FK
     * บัญชีถูกลบถาวรแล้ว user_id เป็น null แต่ยังรู้ว่าแถวนี้เป็นของใคร (ดู migration 2026_10_23_000008)
     */
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            $activity->user_ref_id ??= $activity->user_id;
        });
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
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

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }
}
