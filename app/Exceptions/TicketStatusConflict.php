<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * สถานะของ Ticket เปลี่ยนไปแล้วหลังจากที่ผู้ใช้เปิดหน้ามา
 *
 * เกิดเมื่อเจ้าหน้าที่สองคนกดเปลี่ยนสถานะ Ticket เดียวกันพร้อมกัน คนแรกสำเร็จ
 * คนหลังได้ข้อผิดพลาดนี้แทนการเขียนทับ พกสถานะปัจจุบันติดมาให้ controller ตอบกลับ (409)
 */
class TicketStatusConflict extends RuntimeException
{
    public function __construct(public readonly string $currentStatus)
    {
        parent::__construct('สถานะของ Ticket เปลี่ยนไปแล้ว กรุณารีเฟรชหน้าแล้วลองใหม่');
    }
}
