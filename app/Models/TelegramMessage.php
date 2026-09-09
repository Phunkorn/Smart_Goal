<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    /** ถูกจองโดยรอบ drain หนึ่ง ๆ แล้ว กันไม่ให้ request อื่นหยิบแถวเดียวกันไปส่งซ้ำ */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * แถวที่ค้างสถานะ sending นานเกินนี้ถือว่ารอบที่จองไว้ตายกลางคัน แล้วให้จองใหม่ได้
     *
     * จำเป็นเพราะการส่งเกิดตอนปิดท้าย request ถ้า PHP ถูกฆ่าระหว่างนั้น
     * (timeout ของ shared hosting, deploy ทับ) แถวจะค้างอยู่อย่างนั้นตลอดไป
     */
    public const STALE_CLAIM_MINUTES = 5;

    protected $fillable = [
        'user_id',
        'system_notification_id',
        'chat_id',
        'text',
        'status',
        'attempts',
        'last_error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(SystemNotification::class, 'system_notification_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
