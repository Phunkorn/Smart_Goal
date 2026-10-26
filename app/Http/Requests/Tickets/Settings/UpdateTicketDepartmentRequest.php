<?php

namespace App\Http\Requests\Tickets\Settings;

class UpdateTicketDepartmentRequest extends TicketSettingsRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'ticket_responsible' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ticket_responsible.*' => 'สถานะความรับผิดชอบไม่ถูกต้อง',
        ];
    }
}
