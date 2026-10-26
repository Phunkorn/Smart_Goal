{{--
    การ์ด "การดำเนินการ" ของหน้ารายละเอียด — ปุ่มของเจ้าหน้าที่อยู่ที่นี่ที่เดียว (ทุกขนาดจอ)
    แสดงเฉพาะปุ่มที่ TicketPolicy อนุญาต ($can จาก controller) ทุกปุ่มเป็น form จริงหรือเปิด modal ที่มี form

    ลำดับ (ลำดับใน DOM = ลำดับบนจอ):
      รับงาน/เข้าร่วม → ปิดงาน (เขียวเข้ม) → กลับมาดำเนินการ / เปิดงานใหม่   — เต็มแถว
      ติดตามงาน · ยกเลิกงาน (แดง)                                         — ครึ่งแถว (ticket-actions__row)
    ปิดงานเป็นขั้นตอนที่รวมผลการแก้ไขและรูปหลังแก้ไขอยู่แล้ว (components/close-modal) จึงไม่มีปุ่มบันทึก/แนบรูปแยก
    ติดตามงานและยกเลิกงานถาม SweetAlert ก่อน แล้วจึงเปิด modal ที่ให้ระบุเหตุผล (tickets/detail.js — data-ticket-confirm-open)
    ยกเลิกงาน = ปิด Ticket แบบยกเลิก (Ticket::ACTION_CANCEL) ใช้สิทธิ์เดียวกับปิดงาน
    ไม่ใช่ sticky bar — อยู่ในลำดับเนื้อหาปกติ จึงไม่บังเนื้อหาส่วนอื่น
--}}
@php
    use App\Models\Ticket;
    use App\Support\TicketDesign;
@endphp
<section class="ticket-detail__actions panel ticket-panel" aria-labelledby="ticket-actions-title">
    <h2 class="ticket-panel__title" id="ticket-actions-title">การดำเนินการ</h2>

    <div class="ticket-actions">
        @include('tickets.components.claim-actions', ['canStart' => $can['start'], 'canJoin' => $can['join'] && ! $isParticipant])

        @if ($can['close'])
            {{-- ปุ่มหลักของงานที่กำลังทำ — เขียวเข้ม ตัวอักษรขาว เต็มแถว (tickets/detail.css) --}}
            <button type="button" class="ticket-button ticket-actions__button ticket-actions__button--close"
                data-bs-toggle="modal" data-bs-target="#ticket-modal-close">
                <i class="bi bi-check-circle" aria-hidden="true"></i> {{ TicketDesign::actionLabel(Ticket::ACTION_CLOSE) }}
            </button>
        @endif

        @if ($can['resume'])
            <form method="POST" action="{{ route('tickets.status.update', $ticket) }}" data-ticket-action-form>
                @csrf
                @method('PATCH')
                <input type="hidden" name="action" value="{{ Ticket::ACTION_RESUME }}">
                <input type="hidden" name="expected_status" value="{{ $ticket->status }}">
                <button type="submit" class="ticket-button ticket-button--primary ticket-actions__button">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> {{ TicketDesign::actionLabel(Ticket::ACTION_RESUME) }}
                </button>
            </form>
        @endif

        @if ($can['reopen'])
            <button type="button" class="ticket-button ticket-button--outline ticket-actions__button"
                data-bs-toggle="modal" data-bs-target="#ticket-modal-reopen">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> {{ TicketDesign::actionLabel(Ticket::ACTION_REOPEN) }}
            </button>
        @endif

        @if ($can['followUp'] || $can['close'])
            <div class="ticket-actions__row">
                @if ($can['followUp'])
                    <button type="button" class="ticket-button ticket-button--warning ticket-actions__button"
                        data-bs-target="#ticket-modal-follow-up" data-ticket-confirm-open
                        data-ticket-confirm="ต้องการปรับสถานะเป็น &quot;งานที่ต้องติดตาม&quot; ใช่ไหม? ขั้นถัดไปจะให้ระบุเหตุผลและวันติดตาม">
                        <i class="bi bi-hourglass-split" aria-hidden="true"></i> ติดตามงาน
                    </button>
                @endif

                @if ($can['close'])
                    <button type="button" class="ticket-button ticket-button--danger ticket-actions__button"
                        data-bs-target="#ticket-modal-cancel" data-ticket-confirm-open
                        data-ticket-confirm="ต้องการยกเลิกงานนี้ใช่ไหม? Ticket จะถูกปิดแบบยกเลิก และผู้แจ้งจะได้รับแจ้งเตือนพร้อมเหตุผล">
                        <i class="bi bi-x-circle" aria-hidden="true"></i> {{ TicketDesign::actionLabel(Ticket::ACTION_CANCEL) }}
                    </button>
                @endif
            </div>
        @endif
    </div>

    @if ($ticket->status === Ticket::STATUS_NEW && $can['start'])
        <p class="ticket-panel__hint">กด "รับงาน" แล้วคุณจะเข้าร่วม Ticket นี้และสถานะเปลี่ยนเป็นกำลังดำเนินการ — เจ้าหน้าที่คนอื่นยังเข้าร่วมเพิ่มได้</p>
    @elseif (! $isParticipant && $can['join'])
        <p class="ticket-panel__hint">เข้าร่วมก่อน แล้วจะติดตามงาน ปิดงาน และยกเลิกงานได้</p>
    @endif
</section>
