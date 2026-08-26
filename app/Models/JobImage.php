<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobImage extends Model
{
    /**
     * ความยาวสูงสุดของคอลัมน์ file_type
     *
     * ค่าที่เก็บคือ MIME type ที่ตรวจจากเนื้อไฟล์ด้วย getMimeType() ซึ่งของ Office 2007+
     * ยาวถึง 73 ตัวอักษร (เช่น presentationml.presentation) เดิมคอลัมน์กว้างเพียง 50
     * ทำให้ MySQL โยน SQLSTATE[22001] ทุกครั้งที่แนบไฟล์ .docx .xlsx หรือ .pptx
     *
     * ค่านี้ใช้เป็น runtime invariant สำหรับ test เท่านั้น
     * migration ประกาศความกว้างเป็นตัวเลขตรง ๆ เพื่อไม่ให้ผูกกับ Model ที่อาจเปลี่ยนภายหลัง
     * test คุมว่า MIME ทุกตัวใน ALLOWED_ATTACHMENT_MIMES ต้องไม่ยาวเกินค่านี้
     */
    public const FILE_TYPE_MAX_LENGTH = 255;

    protected $fillable = [
        'job_id',
        'file_path',
        'original_name',
        'file_type',
        'uploaded_by',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'job_id', 'job_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
