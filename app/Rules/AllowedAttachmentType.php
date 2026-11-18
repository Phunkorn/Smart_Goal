<?php

namespace App\Rules;

use App\Support\AttachmentPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * ชนิดไฟล์แนบที่ระบบรับ — ใช้แทน mimes: ของ Laravel
 *
 * mimes: เดานามสกุลจาก MIME ที่ตรวจจากเนื้อไฟล์ แต่ไฟล์ Excel ที่ตั้งรหัสผ่านมี MIME
 * application/encrypted ซึ่งไม่มีนามสกุลคู่ จึงตกด้วยข้อความ "ต้องเป็นไฟล์ชนิด: ..." เสมอ
 * กฎนี้ให้ผลเหมือน mimes: เดิมทุกกรณี ต่างกันแค่ยอมรับ Excel ที่ตั้งรหัสผ่าน
 * และใช้ข้อความผิดพลาดชุดเดิม (validation.mimes)
 */
class AllowedAttachmentType implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && $value->isValid() && AttachmentPolicy::contentTypeIsAllowed($value)) {
            return;
        }

        $fail('validation.mimes')->translate([
            'values' => implode(', ', AttachmentPolicy::extensions()),
        ]);
    }
}
