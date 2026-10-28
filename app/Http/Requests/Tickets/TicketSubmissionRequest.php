<?php

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Tickets\Concerns\ValidatesTicketPhotos;
use App\Services\TicketSubmissionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * กติกาที่ฟอร์มแจ้งปัญหาทั้งสองช่องทางใช้ร่วมกัน: สาขา รายละเอียด เบอร์โทร รหัสรีโมท รูป และ submission_key
 *
 * สาขา: select ส่ง location_id เป็น id ของสาขา หรือ "other" เมื่อเลือก "อื่น ๆ"
 * "other" ถูกแปลงเป็น null ก่อน validate แล้ว location_name กลายเป็นช่องบังคับ
 * การตรวจว่าสาขายังเปิดใช้งานอยู่ทำที่ TicketSubmissionService ที่เดียว
 *
 * เบอร์โทรติดต่อกลับบังคับทั้งสองช่องทาง (ตัวเลข 10 หลัก) — ที่พิมพ์หรือวางมาพร้อมขีด
 * หรือช่องว่างถูกตัดเหลือเฉพาะตัวเลขก่อนตรวจ ส่วนรหัส AnyDesk/รีโมทไม่บังคับ และรับเฉพาะ
 * เลขประจำเครื่อง (TicketSubmissionService::REMOTE_ACCESS_PATTERN) จึงใส่รหัสผ่านลงไปไม่ได้
 *
 * ไม่มีฟิลด์ผู้แจ้งหรือสถานะในกติกาชุดนี้ — validated() จึงไม่มีทางพาค่าเหล่านั้น
 * เข้าไปถึง service ได้ (กัน mass assignment ตั้งแต่ชั้นนี้)
 * แผนกอยู่ในกติกาของฟอร์มสาธารณะเท่านั้น (StorePublicTicketRequest) เพราะแผนกของ
 * ผู้ใช้ภายในอ่านจากบัญชีที่ login ไม่ใช่จากฟอร์ม
 */
abstract class TicketSubmissionRequest extends FormRequest
{
    use ValidatesTicketPhotos;

    public const LOCATION_OTHER = 'other';

    public const DESCRIPTION_MIN = 10;

    public const DESCRIPTION_MAX = 5000;

    protected function prepareForValidation(): void
    {
        if ($this->input('location_id') === self::LOCATION_OTHER) {
            $this->merge(['location_id' => null]);
        }

        if (is_string($this->input('contact'))) {
            $this->merge(['contact' => preg_replace('/\D+/', '', $this->input('contact'))]);
        }

        if (is_string($this->input('remote_access'))) {
            $this->merge(['remote_access' => trim($this->input('remote_access'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    protected function sharedRules(): array
    {
        return [
            'location_id' => ['nullable', 'integer'],
            'location_name' => ['nullable', 'required_without:location_id', 'string', 'max:'.TicketSubmissionService::NAME_MAX],
            'description' => ['required', 'string', 'min:'.self::DESCRIPTION_MIN, 'max:'.self::DESCRIPTION_MAX],
            'contact' => ['required', 'string', 'regex:'.TicketSubmissionService::PHONE_PATTERN],
            'remote_access' => ['nullable', 'string', 'regex:'.TicketSubmissionService::REMOTE_ACCESS_PATTERN],
            'submission_key' => ['required', 'uuid'],
            ...$this->photoRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'location_name.required_without' => 'กรุณาเลือกสาขา หรือระบุสาขา/สถานที่เอง',
            'location_name.max' => 'ชื่อสาขา/สถานที่ยาวเกิน :max ตัวอักษร',
            'location_id.integer' => 'สาขาที่เลือกไม่ถูกต้อง',
            'description.required' => 'กรุณาระบุรายละเอียดปัญหา',
            'description.min' => 'กรุณาอธิบายปัญหาอย่างน้อย :min ตัวอักษร',
            'description.max' => 'รายละเอียดยาวเกิน :max ตัวอักษร',
            'contact.required' => 'กรุณาระบุเบอร์โทรติดต่อกลับ',
            'contact.regex' => 'เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0',
            'contact.string' => 'เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0',
            'remote_access.regex' => 'กรอกเฉพาะเลข AnyDesk / รหัสรีโมท (ตัวเลข 6–20 หลัก) — ห้ามใส่รหัสผ่าน',
            'remote_access.string' => 'กรอกเฉพาะเลข AnyDesk / รหัสรีโมท (ตัวเลข 6–20 หลัก) — ห้ามใส่รหัสผ่าน',
            'submission_key.*' => 'แบบฟอร์มไม่สมบูรณ์ กรุณาเปิดหน้านี้ใหม่แล้วส่งอีกครั้ง',
            ...$this->photoMessages(),
        ];
    }
}
