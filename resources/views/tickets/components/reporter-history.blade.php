{{--
    modal "ประวัติ Ticket" ของผู้แจ้งบน /tickets — รายการใบที่ตัวเองแจ้งผ่าน Smart Goals ทุกสถานะ ใหม่สุดก่อน
    เปิดจากปุ่มใต้การ์ด (components/reporter-card) ไม่มี JavaScript เฉพาะ: เปิด/ปิด โฟกัส และ Escape เป็นของ Bootstrap Modal
    แต่ละแถวเป็นลิงก์ ?ticket={id} — หน้านี้เปิด modal รายละเอียดอ่านอย่างเดียวของใบนั้นให้เอง (TicketPolicy::view ตรวจที่ server)
    จึงไม่มีฟอร์มหรือปุ่มดำเนินการใดในนี้

    พารามิเตอร์: $history (Collection<Ticket> ใหม่สุดก่อน จำกัดจำนวนที่ controller), $historyTotal (int ทั้งหมด)
--}}
@php
    use App\Support\TicketDesign;
@endphp
@if ($historyTotal > 0)
    <div class="modal fade ticket-preview" id="ticket-history-modal" tabindex="-1"
        aria-labelledby="ticket-history-title" aria-hidden="true" data-ticket-reporter-history>
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down ticket-preview__dialog">
            <div class="modal-content ticket-preview__content">
                <header class="modal-header ticket-preview__header">
                    <div class="ticket-preview__heading">
                        <span class="ticket-preview__eyebrow">Smart Ticket</span>
                        <h2 class="modal-title" id="ticket-history-title">ประวัติ Ticket</h2>
                        <p class="ticket-detail__when">
                            @if ($historyTotal > $history->count())
                                แสดง {{ $history->count() }} รายการล่าสุดจากทั้งหมด {{ $historyTotal }} รายการ
                            @else
                                ทั้งหมด {{ $historyTotal }} รายการ
                            @endif
                        </p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดหน้าต่างประวัติ Ticket"></button>
                </header>

                <div class="modal-body ticket-preview__body">
                    <ul class="ticket-history" aria-labelledby="ticket-history-title">
                        @foreach ($history as $item)
                            @php($status = TicketDesign::status($item->status))
                            <li>
                                <a class="ticket-history__item" href="{{ route('tickets.index', ['ticket' => $item->id]) }}">
                                    <span class="ticket-history__top">
                                        <strong class="ticket-history__number">{{ $item->ticket_number }}</strong>
                                        <span class="badge-soft {{ $status['tone'] }} ticket-status">{{ $status['label'] }}</span>
                                    </span>
                                    <span class="ticket-history__summary">{{ $item->summary }}</span>
                                    <span class="ticket-history__when">
                                        แจ้งเมื่อ <time datetime="{{ $item->created_at->toIso8601String() }}">{{ TicketDesign::when($item->created_at) }}</time>
                                        @if ($item->closed_at)
                                            · ปิดงาน <time datetime="{{ $item->closed_at->toIso8601String() }}">{{ TicketDesign::when($item->closed_at) }}</time>
                                        @endif
                                    </span>
                                    <i class="bi bi-chevron-right ticket-history__chevron" aria-hidden="true"></i>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endif
