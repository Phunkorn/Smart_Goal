{{--
    เวลาข้อมูลของหน้ารายการ Ticket (คิวงาน tickets/index และรายการของหัวหน้าแผนก tickets/department)
    ไม่มีปุ่มรีเฟรช — tickets/desk.js โหลดข้อมูลใหม่เองแล้วแทนที่ [data-ticket-stamp] นี้ทุกครั้ง (aria-live อ่านเวลาใหม่ให้)
    พารามิเตอร์: $renderedAt
--}}
<p class="ticket-desk__stamp" data-ticket-stamp aria-live="polite">
    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> อัปเดตอัตโนมัติ · ข้อมูล ณ <time datetime="{{ $renderedAt->toIso8601String() }}">{{ \App\Support\TicketDesign::when($renderedAt) }}</time>
</p>
