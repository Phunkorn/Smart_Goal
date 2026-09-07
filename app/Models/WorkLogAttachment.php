<?php

namespace App\Models;

use App\Models\Concerns\KeepsFileUntilPurged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ไฟล์แนบของบันทึกงานประจำวัน
 *
 * โครงสร้างและพฤติกรรมลบไฟล์ตาม WorkOrderListAttachment ทุกประการ
 * ไฟล์อยู่ใน private disk เสิร์ฟผ่าน MediaController เท่านั้น
 */
class WorkLogAttachment extends Model
{
    use KeepsFileUntilPurged;

    protected $fillable = [
        'work_log_id',
        'file_path',
        'original_name',
        'file_type',
        'uploaded_by',
    ];

    public function workLog(): BelongsTo
    {
        return $this->belongsTo(WorkLog::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
