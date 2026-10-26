{{--
    Modal ดูรายละเอียด Ticket ของหน้ารับแจ้งปัญหา — เปลือกว่างหนึ่งชุดต่อหน้า
    tickets/desk.js โหลดเนื้อหาจาก tickets.preview (components/preview) มาแทน [data-ticket-preview-content] เมื่อกดดูรายละเอียด
    ไม่มี JavaScript: ลิงก์ในคิวพาไปหน้ารายละเอียดเต็มตามปกติ
    เปิด/ปิด โฟกัส และ Escape เป็นของ Bootstrap Modal ตัวเดียว
--}}
<div class="modal fade ticket-preview" id="ticket-preview-modal" tabindex="-1"
    aria-labelledby="ticket-preview-title" aria-hidden="true" data-ticket-preview-modal>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down ticket-preview__dialog">
        <div class="modal-content ticket-preview__content" data-ticket-preview-content aria-busy="true">
            @include('tickets.components.preview-loading')
        </div>
    </div>
    <template data-ticket-preview-loading>@include('tickets.components.preview-loading')</template>
</div>
