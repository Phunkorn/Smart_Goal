<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * มีคนอื่นบันทึกกระดานใบนี้ไปแล้วหลังจากที่ผู้ใช้คนนี้โหลดเนื้อหามา
 *
 * พก "สถานะปัจจุบันบนเซิร์ฟเวอร์" ติดมาด้วย เพื่อให้ controller ตอบกลับพร้อมเนื้อหา
 * ฉบับล่าสุดในคำขอเดียว ผู้ใช้จึงกดปุ่มเดียวแล้วเห็นของจริงทันที ไม่ต้องรอ
 * อีกหนึ่ง round-trip
 */
class WorkspaceVersionConflict extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $currentState
     */
    public function __construct(public readonly array $currentState)
    {
        parent::__construct('กระดานถูกแก้ไขโดยผู้ใช้คนอื่นแล้ว');
    }
}
