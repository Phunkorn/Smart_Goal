<?php

namespace App\Http\Requests\Tickets\Settings;

use App\Models\TicketLocation;
use App\Services\TicketSubmissionService;
use Illuminate\Validation\Rule;

/**
 * เพิ่มหรือแก้สาขา — ชื่อซ้ำไม่ได้ (เทียบกับทุกสาขา รวมที่ปิดใช้งาน เพราะเปิดกลับได้)
 */
class SaveTicketLocationRequest extends TicketSettingsRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $location = $this->route('location');

        return [
            'name' => [
                'required',
                'string',
                'max:'.TicketSubmissionService::NAME_MAX,
                Rule::unique('ticket_locations', 'name')->ignore($location instanceof TicketLocation ? $location->id : null),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'กรุณาระบุชื่อสาขา/สถานที่',
            'name.max' => 'ชื่อยาวเกิน :max ตัวอักษร',
            'name.unique' => 'มีสาขา/สถานที่ชื่อนี้อยู่แล้ว',
            'is_active.*' => 'สถานะการใช้งานไม่ถูกต้อง',
        ];
    }
}
