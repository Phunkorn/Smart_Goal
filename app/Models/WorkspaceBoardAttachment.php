<?php

namespace App\Models;

use App\Models\Concerns\KeepsFileUntilPurged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รูปภาพที่แนบอยู่บนกระดานไอเดีย
 *
 * โครงสร้างและพฤติกรรมลบไฟล์ตาม WorkLogAttachment และ WorkOrderListAttachment
 * ไฟล์อยู่ใน private disk เสิร์ฟผ่าน MediaController เท่านั้น
 *
 * ห้ามสร้าง URL สาธารณะจาก file_path และห้ามส่ง file_path ออกไปที่ Blade,
 * JSON หรือ JavaScript ชิ้นงานบนกระดานอ้างถึงรูปด้วย id ของแถวนี้เท่านั้น
 * แล้ว WorkspaceBoardPresenter เป็นผู้เติม URL ที่ผ่านการตรวจสิทธิ์ให้ตอนอ่าน
 */
class WorkspaceBoardAttachment extends Model
{
    use KeepsFileUntilPurged;

    protected $fillable = [
        'workspace_board_id',
        'file_path',
        'original_name',
        'file_type',
        'image_width',
        'image_height',
        'byte_size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'image_width' => 'integer',
            'image_height' => 'integer',
            'byte_size' => 'integer',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceBoard::class, 'workspace_board_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
