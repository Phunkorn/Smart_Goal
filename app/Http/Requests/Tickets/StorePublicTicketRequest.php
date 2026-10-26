<?php

namespace App\Http\Requests\Tickets;

use App\Services\LineLogin;
use App\Services\TicketSubmissionService;
use App\Support\TicketSubmissionGuard;
use Illuminate\Validation\Validator;

/**
 * แจ้งปัญหาจากฟอร์มสาธารณะ /support — ไม่ต้องเข้าสู่ระบบ
 *
 * บังคับ ชื่อผู้แจ้ง สาขา รายละเอียด และเบอร์โทร (ตัวเลข 10 หลัก) ส่วนรหัสรีโมทและรูปไม่บังคับ
 * เบอร์โทรที่พิมพ์/วางมาพร้อมขีดหรือช่องว่างถูกตัดเหลือตัวเลขก่อนตรวจ
 * honeypot และเวลากรอกขั้นต่ำตรวจหลังกติกาปกติผ่าน (TicketSubmissionGuard)
 * เมื่อเปิดใช้ LINE Login ต้องยืนยันตัวด้วย LINE ก่อนส่ง (ตัวตนอ่านจาก session ไม่ใช่จากฟอร์ม)
 */
class StorePublicTicketRequest extends TicketSubmissionRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('contact'))) {
            $this->merge(['contact' => preg_replace('/\D+/', '', $this->input('contact'))]);
        }

        if (is_string($this->input('remote_access'))) {
            $this->merge(['remote_access' => trim($this->input('remote_access'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'requester_name' => ['required', 'string', 'max:'.TicketSubmissionService::NAME_MAX],
            'contact' => ['required', 'string', 'regex:'.TicketSubmissionService::PHONE_PATTERN],
            'remote_access' => ['nullable', 'string', 'regex:'.TicketSubmissionService::REMOTE_ACCESS_PATTERN],
            ...$this->sharedRules(),
        ];
    }

    /** @return array{user_id: string, name: string}|null */
    public function lineIdentity(): ?array
    {
        return LineLogin::identityFrom($this->session());
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $reason = TicketSubmissionGuard::rejectionReason(
                    $this->input(TicketSubmissionGuard::HONEYPOT_FIELD),
                    $this->input(TicketSubmissionGuard::TOKEN_FIELD),
                );

                if ($reason !== null) {
                    $validator->errors()->add('form', $reason);

                    return;
                }

                $line = app(LineLogin::class);
                if ($line->unavailable()) {
                    $validator->errors()->add('form', TicketSubmissionService::SERVICE_UNAVAILABLE);
                } elseif ($line->required() && $this->lineIdentity() === null) {
                    $validator->errors()->add('form', TicketSubmissionService::LINE_REQUIRED);
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'requester_name.required' => 'กรุณาระบุชื่อผู้แจ้ง',
            'requester_name.max' => 'ชื่อผู้แจ้งยาวเกิน :max ตัวอักษร',
            'contact.required' => 'กรุณาระบุเบอร์โทรติดต่อกลับ',
            'contact.regex' => 'เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0',
            'contact.string' => 'เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0',
            'remote_access.regex' => 'กรอกเฉพาะเลข AnyDesk / รหัสรีโมท (ตัวเลข 6–20 หลัก) — ห้ามใส่รหัสผ่าน',
            'remote_access.string' => 'กรอกเฉพาะเลข AnyDesk / รหัสรีโมท (ตัวเลข 6–20 หลัก) — ห้ามใส่รหัสผ่าน',
        ];
    }
}
