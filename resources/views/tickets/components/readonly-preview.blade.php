{{--
    รายละเอียด Ticket แบบอ่านอย่างเดียว — ผู้แจ้ง (modal บน /tickets) และหัวหน้าแผนก (modal ของรายการแผนก ผ่าน TicketController::preview)
    เนื้อหาเป็นส่วนของ .modal-content (หัว · เนื้อหา · ท้าย) ใส่ใน modal ได้ทั้งแบบ render จาก server และโหลดผ่าน desk.js

    แสดงเฉพาะสิ่งที่เปิดเผยให้ผู้แจ้งได้: เลข สถานะ วันที่แจ้ง รายละเอียดปัญหา สาขา รูปตอนแจ้ง
    (ผู้แจ้งเปิดใบของตัวเอง → ไม่แสดงชื่อ/แผนก/ช่องทางติดต่อของตัวเองซ้ำ — components/problem โหมด compact
     และแสดง "ผู้รับผิดชอบ" ของใบนี้ — components/readonly-responsible)
    และความคืบหน้า (การแจ้ง + การเปลี่ยนสถานะ — TicketDesign::reporterProgress) พร้อม "ผลการแก้ไข" เมื่อปิดงาน
    ไม่มีบันทึกการดำเนินงาน รูปของบันทึก หรือปุ่ม/ฟอร์มใด — สิทธิ์จริงตรวจที่ server (TicketPolicy)
    รายชื่อเจ้าหน้าที่เห็นได้เฉพาะผู้แจ้งของใบนั้น (ชื่อ แผนก รูปโปรไฟล์) หัวหน้าแผนกไม่เห็น

    พารามิเตอร์: $ticket (โหลด activities และ attachments ตอนแจ้งแล้ว — TicketController::loadReadonly)
               $asModalContent (bool) — true เมื่อ desk.js โหลดเข้า modal ร่วมของรายการ
--}}
@php
    use App\Support\TicketDesign;

    $status = TicketDesign::ticketStatus($ticket);
    // ผู้แจ้งเปิดใบของตัวเอง: ซ่อนข้อมูลที่รู้อยู่แล้ว/ซ้ำกับความคืบหน้า และแสดง "ผู้รับผิดชอบ"
    // หัวหน้าแผนกเปิดใบของลูกทีมยังเห็นผู้แจ้งและแผนกครบ และไม่เห็นรายชื่อเจ้าหน้าที่
    $compact = auth()->check() && $ticket->isRequestedBy(auth()->user());
    // โหลดเข้า modal ร่วมของรายการ (preview-modal, aria-labelledby="ticket-preview-title") ต้องใช้ id เดียวกัน
    $titleId = ($asModalContent ?? false) ? 'ticket-preview-title' : 'ticket-readonly-title-'.$ticket->id;
@endphp
<header class="modal-header ticket-preview__header">
    <div class="ticket-preview__heading">
        <span class="ticket-preview__eyebrow">Smart Ticket</span>
        <div class="ticket-detail__heading">
            <h2 class="modal-title" id="{{ $titleId }}" data-ticket-readonly-title>{{ $ticket->ticket_number }}</h2>
            <span class="badge-soft {{ $status['tone'] }} ticket-status">{{ $status['label'] }}</span>
        </div>
        <p class="ticket-detail__when">
            แจ้งเมื่อ <time datetime="{{ $ticket->created_at->toIso8601String() }}">{{ TicketDesign::when($ticket->created_at) }}</time>
        </p>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดหน้าต่างรายละเอียด"></button>
</header>

<div class="modal-body ticket-preview__body" data-ticket-readonly>
    @include('tickets.components.problem', ['compact' => $compact])

    @if ($compact)
        @include('tickets.components.readonly-responsible')
    @endif

    @if ($ticket->attachments->isNotEmpty())
        <section class="ticket-detail__photos panel ticket-panel" aria-labelledby="{{ $titleId }}-photos">
            <h3 class="ticket-panel__title" id="{{ $titleId }}-photos">รูปภาพประกอบ <span class="ticket-panel__count">{{ $ticket->attachments->count() }}</span></h3>
            @include('tickets.components.photo-gallery', ['photos' => $ticket->attachments])
        </section>
    @endif

    <section class="ticket-detail__timeline panel ticket-panel" aria-labelledby="{{ $titleId }}-progress">
        <h3 class="ticket-panel__title" id="{{ $titleId }}-progress">ความคืบหน้า</h3>
        <ol class="ticket-timeline">
            @foreach ($ticket->activities as $activity)
                @php($progress = TicketDesign::reporterProgress($activity))
                @continue(! $progress['show'])
                @php($design = TicketDesign::activity($activity))
                <li class="ticket-timeline__item">
                    <span class="ticket-timeline__icon badge-soft {{ $design['tone'] }}" aria-hidden="true"><i class="bi {{ $design['icon'] }}"></i></span>
                    <div class="ticket-timeline__content">
                        <p class="ticket-timeline__title"><strong>{{ $design['title'] }}</strong></p>
                        <time class="ticket-timeline__time" datetime="{{ $activity->created_at->toIso8601String() }}">{{ TicketDesign::when($activity->created_at) }}</time>
                        @if ($progress['body'] !== null)
                            <div class="ticket-timeline__body">
                                <span class="ticket-timeline__body-label">{{ $design['body_label'] }}</span>
                                <p>{{ $progress['body'] }}</p>
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
</div>

<footer class="modal-footer ticket-preview__footer">
    <button type="button" class="ticket-button ticket-button--ghost" data-bs-dismiss="modal">ปิด</button>
</footer>
