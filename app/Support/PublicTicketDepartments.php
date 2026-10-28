<?php

namespace App\Support;

/**
 * แผนกที่ผู้แจ้งจากฟอร์มสาธารณะ /support เลือกเองได้ — ที่เดียวของรายการนี้
 *
 * ใช้ร่วมกันสามที่: ตัวเลือกใน dropdown (components/department-field),
 * กติกา Rule::in ของ StorePublicTicketRequest และการตรวจชั้นสุดท้ายใน TicketSubmissionService
 *
 * เป็นค่าที่ผู้แจ้ง "แจ้งเอง" ไม่ใช่แผนกในระบบ จึงถูกบันทึกลง requester_department_name เท่านั้น
 * requester_department_id ของ Ticket สาธารณะยังเป็น null ตลอด เพราะ TicketPolicy และ
 * TicketQueryService ใช้คอลัมน์นั้นให้สิทธิ์หัวหน้าแผนกเห็น Ticket ของแผนกตัวเอง
 * ถ้าผูก id หัวหน้าแผนกจะเห็นเรื่องของบุคคลภายนอกขึ้นมาทันที
 */
final class PublicTicketDepartments
{
    /** @var array<int, string> */
    public const OPTIONS = ['Callcenter', 'Marketing', 'HR'];

    /** ตรงตัวพิมพ์เป๊ะ — ค่าที่ไม่อยู่ในรายการคือการแก้ HTML ไม่ใช่การพิมพ์ผิดของผู้แจ้ง */
    public static function allows(mixed $name): bool
    {
        return is_string($name) && in_array($name, self::OPTIONS, true);
    }
}
