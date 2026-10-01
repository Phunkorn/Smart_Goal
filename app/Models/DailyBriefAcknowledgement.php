<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * แถวละหนึ่งวันต่อผู้ใช้ — มีแถวของวันนี้แปลว่ารับทราบสรุปประจำวันแล้ว
 *
 * เขียนผ่าน App\Services\DailyBriefService::acknowledge() เท่านั้น
 */
class DailyBriefAcknowledgement extends Model
{
    protected $fillable = [
        'user_id',
        'brief_date',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'brief_date' => 'date',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
