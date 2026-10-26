<?php

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Tickets\Concerns\ValidatesTicketPhotos;
use Illuminate\Foundation\Http\FormRequest;

/**
 * บันทึกการดำเนินงาน — สิทธิ์ตรวจซ้ำกับแถวที่ล็อกไว้ใน TicketNoteService
 */
class StoreTicketNoteRequest extends FormRequest
{
    use ValidatesTicketPhotos;

    public const BODY_MAX = 2000;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('addNote', $this->route('ticket'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::BODY_MAX],
            ...$this->photoRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required' => 'กรุณาพิมพ์บันทึกการดำเนินงาน',
            'body.max' => 'บันทึกยาวเกิน :max ตัวอักษร',
            ...$this->photoMessages(),
        ];
    }
}
