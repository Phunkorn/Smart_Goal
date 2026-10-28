{{--
    ช่องติดต่อกลับของฟอร์มแจ้งปัญหา: เบอร์โทร (บังคับ) และรหัส AnyDesk/รีโมท (ไม่บังคับ) — ที่เดียว
    ใช้ที่ขั้นที่ 2 ของหน้าสาธารณะ /support, ขั้นที่ 3 ของ modal แจ้งปัญหา (submit-modal)
    และฟอร์มหน้าเดียว (form-fields)

    พารามิเตอร์: $fieldIcons   — (ไม่บังคับ) true = มีไอคอนวงกลมหน้าป้ายช่อง ใช้ที่หน้าสาธารณะ
                $contactValue — (ไม่บังคับ) เบอร์ตั้งต้น ฝั่งภายในส่งเบอร์จากโปรไฟล์มา
                                หน้าสาธารณะไม่ส่ง (ผู้แจ้งกรอกเอง)

    ปุ่ม ? เปิด dialog วิธีหาเลข AnyDesk (components/anydesk-help-dialog) ซึ่งต้องถูก include
    ไว้นอก <form> ของหน้านั้นด้วย — พฤติกรรมเปิด/ปิด/คืนโฟกัสอยู่ที่ components/help-dialog.js
--}}
@php
    $contactValue ??= null;
@endphp

{{--
    เบอร์โทร: ตัวเลข 10 หลักเท่านั้น — tickets/form.js ตัดอักขระที่ไม่ใช่ตัวเลขแล้วจำกัด 10 หลัก (data-ticket-digits)
    ไม่ใช้ maxlength เพราะเบราว์เซอร์ตัดข้อความที่วางก่อนตัดขีด ("081-234-5678" จะเหลือเลขไม่ครบ) · server ตัดซ้ำและตรวจรูปแบบ
--}}
<div class="ticket-form__field">
    <label class="ticket-form__label{{ ($fieldIcons ?? false) ? ' ticket-form__label--icon' : '' }}" for="ticket-contact">
        @if ($fieldIcons ?? false)<span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-telephone-fill"></i></span>@endif
        <span>เบอร์โทรติดต่อกลับ <span class="ticket-form__required" aria-hidden="true">*</span></span>
    </label>
    <input id="ticket-contact" type="tel" name="contact" inputmode="numeric"
        class="form-control @error('contact') is-invalid @enderror"
        value="{{ old('contact', $contactValue) }}" required autocomplete="tel"
        pattern="0[0-9]{9}" data-ticket-digits="10"
        data-pattern-message="เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0"
        placeholder="0812345678"
        aria-describedby="ticket-contact-hint @error('contact') ticket-contact-error @enderror">
    <p class="ticket-form__hint" id="ticket-contact-hint">ตัวเลข 10 หลัก ไม่ต้องใส่ขีด</p>
    @error('contact')
        <p class="ticket-form__error" id="ticket-contact-error">{{ $message }}</p>
    @enderror
</div>

{{-- ปุ่ม ? อยู่ข้างป้าย (ไม่อยู่ใน <label> — ปุ่มในป้ายทำให้การคลิกกำกวม) เปิด dialog วิธีหาเลข AnyDesk --}}
<div class="ticket-form__field">
    <div class="ticket-form__label-row">
        <label class="ticket-form__label{{ ($fieldIcons ?? false) ? ' ticket-form__label--icon' : '' }}" for="ticket-remote">
            @if ($fieldIcons ?? false)<span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-display"></i></span>@endif
            <span>AnyDesk / รหัสรีโมท <span class="ticket-form__optional">(ไม่บังคับ)</span></span>
        </label>
        <button type="button" class="ticket-help-trigger" aria-haspopup="dialog" aria-controls="ticket-anydesk-help"
            data-help-dialog-open="ticket-anydesk-help" aria-label="วิธีหาเลข AnyDesk" title="วิธีหาเลข AnyDesk">
            <i class="bi bi-question-circle" aria-hidden="true"></i>
        </button>
    </div>
    {{-- เลขเครื่องเท่านั้น (ตัวเลข เว้นวรรค ขีด) ตรงกับ TicketSubmissionService::REMOTE_ACCESS_PATTERN — ไม่รับรหัสผ่าน --}}
    <input id="ticket-remote" type="text" name="remote_access" inputmode="numeric"
        class="form-control @error('remote_access') is-invalid @enderror"
        value="{{ old('remote_access') }}" autocomplete="off"
        maxlength="20" pattern="[0-9][0-9 \-]{5,19}"
        data-pattern-message="กรอกเฉพาะเลข AnyDesk / รหัสรีโมท (ตัวเลข 6–20 หลัก) — ห้ามใส่รหัสผ่าน"
        placeholder="เช่น 123 456 789"
        aria-describedby="ticket-remote-hint @error('remote_access') ticket-remote-error @enderror">
    <p class="ticket-form__hint" id="ticket-remote-hint">กรอกเฉพาะเลขเครื่อง ห้ามใส่รหัสผ่าน</p>
    @error('remote_access')
        <p class="ticket-form__error" id="ticket-remote-error">{{ $message }}</p>
    @enderror
</div>
