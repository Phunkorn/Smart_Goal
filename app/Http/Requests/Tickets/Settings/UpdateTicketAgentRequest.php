<?php

namespace App\Http\Requests\Tickets\Settings;

use Illuminate\Validation\Rule;

/**
 * สิทธิ์ agent ของคนหนึ่งคน
 *   access: department = ตามแผนก, granted = เปิดรายคน, revoked = ปิดรายคน
 */
class UpdateTicketAgentRequest extends TicketSettingsRequest
{
    public const ACCESS = [
        'department' => null,
        'granted' => true,
        'revoked' => false,
    ];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'access' => ['required', Rule::in(array_keys(self::ACCESS))],
            'receives_alerts' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'access.*' => 'สิทธิ์ที่เลือกไม่ถูกต้อง',
            'receives_alerts.*' => 'ค่าการรับแจ้งเตือนไม่ถูกต้อง',
        ];
    }

    public function enabled(): ?bool
    {
        return self::ACCESS[$this->validated('access')];
    }
}
