<?php

namespace App\Models;

use App\Models\Concerns\KeepsFileUntilPurged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รูปภาพที่แนบมากับความคิดเห็นในงาน
 *
 * ไฟล์อยู่ใน private disk เสิร์ฟผ่าน MediaController เท่านั้น
 * ห้ามสร้าง URL สาธารณะจาก file_path และห้ามส่ง file_path ออกไปที่ Blade,
 * JSON หรือ JavaScript — TaskCommentPresenter เป็นผู้เติม URL ที่ผ่านการตรวจสิทธิ์
 */
class WorkOrderUpdateAttachment extends Model
{
    use KeepsFileUntilPurged;

    protected $fillable = [
        'work_order_update_id',
        'file_path',
        'original_name',
        'file_type',
        'byte_size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['byte_size' => 'integer'];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(WorkOrderUpdate::class, 'work_order_update_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
