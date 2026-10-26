<?php

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
use App\Services\TicketSubmissionService;
use Illuminate\Validation\Validator;

/**
 * แจ้งปัญหาจากผู้ใช้ภายใน — ผู้แจ้งและแผนกมาจากบัญชีที่ login ใน TicketSubmissionService
 *
 * authorize: TicketPolicy::create (เฉพาะ Reporter — Handler / หัวหน้าแผนก / admin แจ้งไม่ได้)
 * after: มี Ticket ที่ยังไม่ปิดอยู่แล้ว → ปฏิเสธพร้อมเลขใบนั้น (service และ unique index ตรวจซ้ำ)
 */
class StoreTicketRequest extends TicketSubmissionRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Ticket::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->sharedRules();
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $open = app(TicketSubmissionService::class)->openTicketForRequester($this->user()->id);

                // กดส่งซ้ำด้วยคีย์เดิมหลังบันทึกแล้ว ปล่อยให้ service คืนใบเดิม ไม่ใช่ข้อความปฏิเสธ
                if ($open && ! $this->isRetryOf($open)) {
                    $validator->errors()->add('form', TicketSubmissionService::openTicketMessage($open));
                }
            },
        ];
    }

    private function isRetryOf(Ticket $open): bool
    {
        return app(TicketSubmissionService::class)->submissionFor('user:'.$this->user()->id, $this->input('submission_key')) === $open->id;
    }
}
