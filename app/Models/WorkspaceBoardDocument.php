<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * เนื้อหาของกระดานไอเดียหนึ่งใบ (ชิ้นงานทั้งหมดในรูป JSON ก้อนเดียว)
 *
 * primary key เป็น workspace_board_id ไม่ใช่ id ที่วิ่งเอง เพราะหนึ่งกระดาน
 * มี document ได้ใบเดียวเสมอ การทำแบบนี้ได้ unique constraint มาฟรีจากฐานข้อมูล
 * และทำให้ไม่มีทางเกิดสถานะ "กระดานหนึ่งใบมีเนื้อหาสองชุด" ที่ต้องมาเลือกว่า
 * ชุดไหนคือชุดจริง
 *
 * content_version ต้องเพิ่มค่าผ่าน App\Services\WorkspaceBoardDocumentService
 * เท่านั้น ซึ่งใช้คำสั่ง UPDATE ... WHERE content_version = ? แล้วตรวจจำนวนแถว
 * ที่ถูกแก้ ห้ามอ่านค่ามาเทียบในโค้ดแล้วค่อยสั่ง save() เพราะสองแท็บที่กดบันทึก
 * พร้อมกันจะผ่านการเทียบทั้งคู่แล้วเขียนทับกันเงียบ ๆ
 */
class WorkspaceBoardDocument extends Model
{
    protected $primaryKey = 'workspace_board_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'workspace_board_id',
        'document',
        'byte_size',
    ];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'content_version' => 'integer',
            'byte_size' => 'integer',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(WorkspaceBoard::class, 'workspace_board_id');
    }
}
