<?php

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Tickets\Concerns\ValidatesTicketPhotos;
use App\Services\TicketSubmissionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * กติกาที่ฟอร์มแจ้งปัญหาทั้งสองช่องทางใช้ร่วมกัน: สาขา รายละเอียด รูป และ submission_key
 *
 * สาขา: select ส่ง location_id เป็น id ของสาขา หรือ "other" เมื่อเลือก "อื่น ๆ"
 * "other" ถูกแปลงเป็น null ก่อน validate แล้ว location_name กลายเป็นช่องบังคับ
 * การตรวจว่าสาขายังเปิดใช้งานอยู่ทำที่ TicketSubmissionService ที่เดียว
 *
 * ไม่มีฟิลด์ผู้แจ้ง แผนก หรือสถานะในกติกา — validated() จึงไม่มีทางพาค่าเหล่านั้น
 * เข้าไปถึง service ได้ (กัน mass assignment ตั้งแต่ชั้นนี้)
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
    }

    /** @return array<string, array<int, mixed>> */
    protected function sharedRules(): array
    {
        return [
            'location_id' => ['nullable', 'integer'],
            'location_name' => ['nullable', 'required_without:location_id', 'string', 'max:'.TicketSubmissionService::NAME_MAX],
            'description' => ['required', 'string', 'min:'.self::DESCRIPTION_MIN, 'max:'.self::DESCRIPTION_MAX],
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
            'submission_key.*' => 'แบบฟอร์มไม่สมบูรณ์ กรุณาเปิดหน้านี้ใหม่แล้วส่งอีกครั้ง',
            ...$this->photoMessages(),
        ];
    }
}
