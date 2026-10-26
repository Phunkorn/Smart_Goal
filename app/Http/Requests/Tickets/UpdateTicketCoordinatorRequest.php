<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ตั้งหรือยกเลิก (ค่าว่าง) ผู้ประสานงาน — ว่าผู้ถูกเลือกยังเป็น agent และกำลังเข้าร่วมอยู่หรือไม่
 * ตรวจใน TicketParticipantService กับแถวที่ล็อกไว้
 */
class UpdateTicketCoordinatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('setCoordinator', $this->route('ticket'));
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'coordinator_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'coordinator_id.*' => 'ผู้ประสานงานที่เลือกไม่ถูกต้อง',
        ];
    }
}
