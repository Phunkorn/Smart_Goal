{{--
    ปุ่มส่งของฟอร์ม Smart Ticket — tickets/form.js สลับเป็นสถานะกำลังส่งและกันการกดซ้ำ
    พารามิเตอร์: $label (ค่าเริ่มต้น "ส่งแจ้งปัญหา") · $icon (ไม่บังคับ — คลาส bootstrap-icons หน้าข้อความ ซ่อนตอนกำลังส่ง)
               $busyLabel (ไม่บังคับ — ข้อความระหว่างส่ง ไม่ส่ง = "กำลังส่ง…" ของ form.js)
               $tone (ไม่บังคับ — สีปุ่ม ค่าเริ่มต้น primary เช่น success สำหรับปิดงาน)
--}}
<div class="ticket-form__actions">
    <button type="submit" class="ticket-button ticket-button--{{ $tone ?? 'primary' }} ticket-form__submit" data-ticket-submit
        @isset($busyLabel) data-busy-label="{{ $busyLabel }}" @endisset>
        @isset($icon)
            <i class="bi {{ $icon }} ticket-form__submit-icon" aria-hidden="true"></i>
        @endisset
        <span class="spinner-border spinner-border-sm" aria-hidden="true" data-ticket-submit-spinner hidden></span>
        <span data-ticket-submit-label>{{ $label ?? 'ส่งแจ้งปัญหา' }}</span>
    </button>
</div>
