<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramLinkToken extends Model
{
    /** อายุของรหัสผูกบัญชี — สั้นพอที่รหัสหลุดแล้วใช้ไม่ได้ แต่พอให้เปิดแอป Telegram ทัน */
    public const LIFETIME_MINUTES = 10;

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
