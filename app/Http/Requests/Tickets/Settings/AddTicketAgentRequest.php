<?php

namespace App\Http\Requests\Tickets\Settings;

/**
 * เพิ่ม agent รายบุคคล (เช่น คนจากแผนกอื่น) — ผู้ถูกเลือกต้องเป็นบัญชีที่ใช้งานได้
 * ตรวจซ้ำใน TicketSettingsService::setAgent() (viewer / ปิดใช้งาน เป็น agent ไม่ได้)
 */
class AddTicketAgentRequest extends TicketSettingsRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.*' => 'กรุณาเลือกผู้ใช้ที่จะเพิ่มเป็นเจ้าหน้าที่',
        ];
    }
}
