{{-- สถานะระหว่างโหลดของ modal ดูรายละเอียด — tickets/desk.js คัดลอกจาก <template data-ticket-preview-loading> ทุกครั้งที่เปิด --}}
<header class="modal-header ticket-preview__header">
    <h2 class="modal-title ticket-preview__loading-title" id="ticket-preview-title">กำลังโหลดรายละเอียด Ticket…</h2>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดหน้าต่างรายละเอียด"></button>
</header>
<div class="modal-body ticket-preview__body ticket-preview__state" role="status">
    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
    <span>กำลังโหลด…</span>
</div>
