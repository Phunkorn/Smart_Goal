<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รูปภาพประกอบ Ticket — ไฟล์ private เสิร์ฟผ่าน MediaController ตามสิทธิ์ของ Ticket แม่
 * ห้ามสร้าง URL จาก file_path โดยตรง
 */
class TicketAttachment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(TicketActivity::class, 'ticket_activity_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
