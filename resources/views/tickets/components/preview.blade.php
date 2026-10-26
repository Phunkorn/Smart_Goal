{{--
    เนื้อหา modal ดูรายละเอียด Ticket บนหน้ารับแจ้งปัญหา — TicketController::preview ส่ง HTML นี้ (ไม่มีเลย์เอาต์)
    tickets/desk.js แทนที่เนื้อหาใน .modal-content ของ components/preview-modal ด้วยไฟล์นี้ทั้งก้อน

    ส่วนรายละเอียด รูป ผู้เข้าร่วม และประวัติใช้ component เดียวกับหน้ารายละเอียด (tickets/show)
    ปุ่มเข้าร่วมคือ components/claim-actions ชุดเดียวกับแถวของคิว — modal ไม่มีปุ่มรับงาน (รับงานจากแถวของคิวหรือหน้ารายละเอียด)
    การดำเนินงานอื่น (ติดตาม ปิดงาน บันทึก) อยู่ที่หน้ารายละเอียดเต็ม — ลิงก์อยู่ท้าย modal

    พารามิเตอร์: $ticket (โหลดความสัมพันธ์แล้ว), $canJoin
--}}
@php
    use App\Support\TicketDesign;

    $status = TicketDesign::status($ticket->status);
@endphp
<header class="modal-header ticket-preview__header">
    <div class="ticket-preview__heading">
        <span class="ticket-preview__eyebrow">Smart Ticket</span>
        <div class="ticket-detail__heading">
            <h2 class="modal-title" id="ticket-preview-title">{{ $ticket->ticket_number }}</h2>
            <span class="badge-soft {{ $status['tone'] }} ticket-status">{{ $status['label'] }}</span>
        </div>
        <p class="ticket-detail__when">
            แจ้งเมื่อ <time datetime="{{ $ticket->created_at->toIso8601String() }}">{{ TicketDesign::when($ticket->created_at) }}</time>
            @if ($ticket->reopen_count > 0)
                · เปิดใหม่ {{ $ticket->reopen_count }} ครั้ง
            @endif
        </p>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดหน้าต่างรายละเอียด"></button>
</header>

<div class="modal-body ticket-preview__body">
    @include('tickets.components.problem')

    @if ($ticket->attachments->isNotEmpty())
        <section class="ticket-detail__photos panel ticket-panel" aria-labelledby="ticket-photos-title">
            <h2 class="ticket-panel__title" id="ticket-photos-title">รูปภาพประกอบ <span class="ticket-panel__count">{{ $ticket->attachments->count() }}</span></h2>
            @include('tickets.components.photo-gallery', ['photos' => $ticket->attachments])
        </section>
    @endif

    @include('tickets.components.participants')

    @include('tickets.components.timeline')
</div>

<footer class="modal-footer ticket-preview__footer">
    <a class="ticket-button ticket-button--ghost" href="{{ route('tickets.show', $ticket) }}">
        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> เปิดหน้ารายละเอียดเต็ม
    </a>
    @if ($canJoin)
        <div class="ticket-actions ticket-preview__actions">
            @include('tickets.components.claim-actions', ['canStart' => false])
        </div>
    @endif
</footer>
