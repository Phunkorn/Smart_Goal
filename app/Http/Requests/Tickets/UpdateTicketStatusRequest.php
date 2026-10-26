<?php

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Tickets\Concerns\ValidatesTicketPhotos;
use App\Models\Ticket;
use App\Support\TicketDesign;
use App\Support\TodayWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * เปลี่ยนสถานะ Ticket — รูปแบบข้อมูลเท่านั้น
 *
 * สิทธิ์ของ action นั้น ๆ, ความถูกต้องของการเปลี่ยนสถานะ, ข้อความบังคับ และการชนกัน
 * (expected_status) ถูกตัดสินใน TicketWorkflowService กับแถวที่ล็อกไว้ที่เดียว
 * ที่นี่จึงตรวจแค่ว่าผู้ใช้เห็น Ticket นี้ได้ (กัน IDOR ตั้งแต่ก่อนเข้า service)
 *
 * รูปหลังแก้ไข (photos[]) แนบได้เฉพาะการปิดงาน — กติการูปชุดเดียวกับฟอร์มแจ้งปัญหาและบันทึก (ValidatesTicketPhotos)
 */
class UpdateTicketStatusRequest extends FormRequest
{
    use ValidatesTicketPhotos;

    public const NOTE_MAX = 2000;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('view', $this->route('ticket'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $photoRules = $this->photoRules();

        return [
            'action' => ['required', Rule::in(array_keys(Ticket::TRANSITIONS))],
            'expected_status' => ['required', Rule::in(array_keys(TicketDesign::statuses()))],
            'note' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
            'follow_up_date' => [
                'nullable',
                'date_format:Y-m-d',
                // วันติดตามเป็นวันตามปฏิทินธุรกิจ (กรุงเทพ) ไม่ใช่วันของ UTC
                'after_or_equal:'.TodayWorkspace::businessNow()->toDateString(),
            ],
            'photos' => ['prohibited_unless:action,'.Ticket::ACTION_CLOSE, ...$photoRules['photos']],
            'photos.*' => $photoRules['photos.*'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'action.*' => 'ไม่รู้จักการดำเนินการนี้',
            'expected_status.*' => 'ข้อมูลสถานะไม่ครบ กรุณารีเฟรชหน้าแล้วลองใหม่',
            'note.max' => 'ข้อความยาวเกิน :max ตัวอักษร',
            'follow_up_date.date_format' => 'วันติดตามถัดไปไม่ถูกต้อง',
            'follow_up_date.after_or_equal' => 'วันติดตามถัดไปต้องเป็นวันนี้หรือหลังจากนี้',
            'photos.prohibited_unless' => 'แนบรูปได้เฉพาะตอนปิดงาน',
            ...$this->photoMessages(),
        ];
    }
}
