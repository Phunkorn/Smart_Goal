<?php

namespace App\Http\Requests\Tickets;

use App\Services\LineLogin;
use App\Services\TicketSubmissionService;
use App\Support\PublicTicketDepartments;
use App\Support\TicketSubmissionGuard;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * แจ้งปัญหาจากฟอร์มสาธารณะ /support — ไม่ต้องเข้าสู่ระบบ
 *
 * บังคับ ชื่อผู้แจ้ง แผนก สาขา รายละเอียด และเบอร์โทร (ตัวเลข 10 หลัก) ส่วนรหัสรีโมทและรูปไม่บังคับ
 * เบอร์โทรและรหัสรีโมทใช้กติกาชุดเดียวกับฟอร์มภายใน (TicketSubmissionRequest::sharedRules)
 *
 * แผนกเป็นค่าที่ผู้แจ้งเลือกเองจากรายการปิด (PublicTicketDepartments) ไม่ใช่แผนกในระบบ
 * จึงไม่มีฟิลด์ requester_department_id ในกติกานี้ — Ticket สาธารณะมี requester_department_id
 * เป็น null ตลอด (ดู TicketSubmissionService::submitPublic)
 *
 * honeypot และเวลากรอกขั้นต่ำตรวจหลังกติกาปกติผ่าน (TicketSubmissionGuard)
 * เมื่อเปิดใช้ LINE Login ต้องยืนยันตัวด้วย LINE ก่อนส่ง (ตัวตนอ่านจาก session ไม่ใช่จากฟอร์ม)
 */
class StorePublicTicketRequest extends TicketSubmissionRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'requester_name' => ['required', 'string', 'max:'.TicketSubmissionService::NAME_MAX],
            'requester_department' => ['required', Rule::in(PublicTicketDepartments::OPTIONS)],
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
            // ค่าที่ไม่อยู่ในรายการคือการแก้ HTML ไม่ใช่การพิมพ์ผิดของผู้แจ้ง จึงใช้ข้อความเดียวกัน
            'requester_department.required' => 'กรุณาเลือกแผนก',
            'requester_department.in' => 'กรุณาเลือกแผนก',
        ];
    }
}
