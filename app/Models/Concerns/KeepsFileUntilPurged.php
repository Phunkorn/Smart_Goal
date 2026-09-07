<?php

namespace App\Models\Concerns;

use App\Support\ProtectedMedia;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ไฟล์แนบที่ลบแล้วยังอยู่บนดิสก์จนกว่าจะถูกลบถาวร
 *
 * ระบบบอกผู้ใช้ว่า "ข้อมูลที่ถูกลบจะเก็บไว้ 30 วันก่อนลบถาวร" แต่เดิมไฟล์แนบทุกชนิด
 * ถูกลบออกจากดิสก์ทันทีที่กดลบ ถังขยะจึงกู้ได้แค่แถวในฐานข้อมูล ส่วนตัวไฟล์หายไปแล้ว
 * และไม่มีทางเรียกกลับมาได้เลย ที่แย่กว่านั้นคือการลบโปรเจกต์หนึ่งโปรเจกต์ทำให้ไฟล์แนบ
 * ทั้งหมดถูกทำลาย ทั้งที่ตัวโปรเจกต์ยังกู้คืนได้ ผู้ใช้จึงกู้มาได้โปรเจกต์ที่ไฟล์หายหมด
 *
 * trait นี้รวมสองอย่างที่ต้องมาคู่กันเสมอไว้ด้วยกัน คือ SoftDeletes กับกฎที่ว่า
 * "แตะไฟล์เฉพาะตอนลบถาวร" ถ้าแยกกันเมื่อไร โมเดลที่ใส่ SoftDeletes แต่ลืมแก้ hook
 * จะยังทำลายไฟล์ตั้งแต่ลบชั่วคราว ซึ่งเป็นความเสียหายที่มองไม่เห็นจนกว่าจะมีคนกดกู้คืน
 *
 * bootKeepsFileUntilPurged() ถูก Eloquent เรียกให้เองตอน boot โมเดล จึงไม่ชนกับ
 * booted() ที่แต่ละโมเดลอาจมีเป็นของตัวเอง
 *
 * ข้อควรระวัง: FK แบบ cascadeOnDelete ในฐานข้อมูลไม่ยิง event ของ Eloquent
 * การลบตัวแม่ถาวรจึงต้องไล่ forceDelete() ไฟล์แนบทีละตัวก่อน มิฉะนั้นไฟล์จะค้าง
 * อยู่ใน storage โดยไม่มีอะไรอ้างถึงและไม่มีใครลบได้อีก — ดู TrashRetention::purgeFiles()
 */
trait KeepsFileUntilPurged
{
    use SoftDeletes;

    protected static function bootKeepsFileUntilPurged(): void
    {
        static::deleting(function (self $attachment): void {
            // ไฟล์คือสิ่งเดียวในระบบที่ทิ้งแล้วสร้างกลับมาไม่ได้
            // การลบชั่วคราวจึงต้องไม่แตะมันเด็ดขาด
            if ($attachment->isForceDeleting()) {
                ProtectedMedia::deleteAttachment($attachment->file_path);
            }
        });
    }
}
