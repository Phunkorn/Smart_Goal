<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Override สิทธิ์ agent รายบุคคล — ไม่มีแถว = ใช้ค่าของแผนก
 * ความหมายทั้งหมดอยู่ที่ App\Support\TicketAgentAccess
 */
class TicketAgent extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'receives_alerts' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
