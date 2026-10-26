{{--
    ปุ่ม "รับงาน" และ "เข้าร่วม Ticket" — ชุดเดียวที่ใช้ทั้งการ์ดการดำเนินการ (หน้ารายละเอียด),
    แถวของคิวงาน และ modal ดูรายละเอียดบนหน้ารับแจ้งปัญหา
    แสดงเฉพาะปุ่มที่ TicketPolicy อนุญาต ผู้เรียกส่งผลการตรวจสิทธิ์มาเอง — ไม่ตัดสินสิทธิ์ใน JavaScript
    เป็น form จริง ทำงานได้โดยไม่พึ่ง JavaScript (หน้ารับแจ้งปัญหาส่งแบบ AJAX แล้วโหลดคิวใหม่ — tickets/desk.js)

    รับงานเป็นสีเขียว (success) ไอคอน clipboard-check ทุกที่ — ไม่ใช้สี primary
    $compact (แถวของคิว): ปุ่มไอคอนล้วน ชื่อปุ่มอยู่ที่ aria-label/title
    Ticket ที่รอรับงานมีแค่ "รับงาน" (คนกดเข้าร่วมอัตโนมัติ) ที่รับงานแล้วมีแค่ "เข้าร่วม" — ตาม TicketPolicy::start / join

    พารามิเตอร์: $ticket, $canStart (bool), $canJoin (bool — ผ่าน policy และยังไม่ได้เข้าร่วม), $compact (bool, ไม่ส่ง = false)
--}}
@php
    use App\Models\Ticket;
    use App\Support\TicketDesign;

    $compact ??= false;
    $startLabel = TicketDesign::actionLabel(Ticket::ACTION_START);
@endphp
@if ($canStart)
    <form method="POST" action="{{ route('tickets.status.update', $ticket) }}" data-ticket-action-form>
        @csrf
        @method('PATCH')
        <input type="hidden" name="action" value="{{ Ticket::ACTION_START }}">
        <input type="hidden" name="expected_status" value="{{ $ticket->status }}">
        @if ($compact)
            <button type="submit" class="ticket-button ticket-button--success ticket-button--icon ticket-actions__button"
                aria-label="{{ $startLabel }} {{ $ticket->ticket_number }}" title="{{ $startLabel }}">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i>
            </button>
        @else
            <button type="submit" class="ticket-button ticket-button--success ticket-actions__button">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i> {{ $startLabel }}<span class="visually-hidden"> {{ $ticket->ticket_number }}</span>
            </button>
        @endif
    </form>
@endif

@if ($canJoin)
    <form method="POST" action="{{ route('tickets.participants.store', $ticket) }}" data-ticket-action-form>
        @csrf
        @if ($compact)
            <button type="submit" class="ticket-button ticket-button--outline ticket-button--icon ticket-actions__button" data-ticket-join
                aria-label="เข้าร่วม Ticket {{ $ticket->ticket_number }}" title="เข้าร่วม Ticket">
                <i class="bi bi-person-plus" aria-hidden="true"></i>
            </button>
        @else
            <button type="submit" class="ticket-button ticket-button--outline ticket-actions__button" data-ticket-join>
                <i class="bi bi-person-plus" aria-hidden="true"></i> เข้าร่วม Ticket<span class="visually-hidden"> {{ $ticket->ticket_number }}</span>
            </button>
        @endif
    </form>
@endif
