{{--
    การ์ดหลักของผู้แจ้งบน /tickets — การ์ดเดียว เปลี่ยนตามสถานะจริงของ Ticket ที่ยังไม่ปิด (TicketDesign::reporterState)
      ไม่มีใบเปิด → "แจ้งปัญหา IT" (เปิด modal ฟอร์มเดิม · ไม่มี JavaScript ไปหน้า /tickets/create)
      new → "รอเจ้าหน้าที่รับงาน" · active → "เจ้าหน้าที่รับงานแล้ว" · follow_up → "อยู่ระหว่างติดตามงาน"
    ใบที่ยังไม่ปิดเปิดดูได้ผ่าน modal อ่านอย่างเดียว (#ticket-reporter-modal) — ไม่มีปุ่มแจ้งปัญหาใหม่จนกว่าจะปิดงาน
    ภาพประกอบเป็นของตกแต่ง (alt ว่าง) width/height จองพื้นที่ไว้ก่อนโหลด

    ปุ่ม "ประวัติ Ticket" (เมื่อเคยแจ้งแล้ว) อยู่ในการ์ดข้างปุ่มหลัก เปิด modal ของ components/reporter-history
    ใบที่ปิดแล้วดูได้จากประวัติ — ไม่มีแถว "Ticket ล่าสุด" ใต้การ์ดอีก

    พารามิเตอร์: $state, $openTicket (?Ticket), $canCreate (bool), $hasModal (bool),
               $historyTotal (int จำนวนใบของผู้แจ้งทั้งหมด)
--}}
@php
    use App\Support\ThaiDate;
    use App\Support\TicketDesign;
@endphp
<div class="ticket-reporter" data-ticket-reporter-region>
    <section class="ticket-reporter__card" aria-labelledby="ticket-reporter-title" data-state="{{ $state['key'] }}">
        <div class="ticket-reporter__body">
            <span class="ticket-reporter__eyebrow">Smart Ticket</span>
            <h1 class="ticket-reporter__title" id="ticket-reporter-title">{{ $state['title'] }}</h1>
            <p class="ticket-reporter__text">{{ $state['description'] }}</p>

            @if ($openTicket)
                <dl class="ticket-reporter__meta">
                    <div><dt>เลขที่ Ticket</dt><dd data-ticket-number>{{ $openTicket->ticket_number }}</dd></div>
                    <div><dt>แจ้งเมื่อ</dt><dd><time datetime="{{ $openTicket->created_at->toIso8601String() }}">{{ TicketDesign::when($openTicket->created_at) }}</time></dd></div>
                    @if ($openTicket->follow_up_date)
                        <div><dt>ติดตามอีกครั้ง</dt><dd>{{ ThaiDate::longDay($openTicket->follow_up_date) }}</dd></div>
                    @endif
                </dl>
            @endif

            <div class="ticket-reporter__actions">
                @if ($state['action'] === 'report' && $canCreate)
                    <a class="ticket-button ticket-button--primary ticket-reporter__button" href="{{ route('tickets.create') }}"
                        data-bs-toggle="modal" data-bs-target="#ticket-submit-modal" data-ticket-open-modal role="button">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> แจ้งปัญหา IT
                    </a>
                @elseif ($state['action'] === 'view' && $hasModal)
                    <button type="button" class="ticket-button ticket-button--primary ticket-reporter__button"
                        data-bs-toggle="modal" data-bs-target="#ticket-reporter-modal">
                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i> ดูรายละเอียด Ticket
                    </button>
                @endif

                @if ($historyTotal > 0)
                    <button type="button" class="ticket-button ticket-button--ghost ticket-reporter__history"
                        data-bs-toggle="modal" data-bs-target="#ticket-history-modal" data-ticket-history-open>
                        <i class="bi bi-clock-history" aria-hidden="true"></i> ประวัติ Ticket
                        <span class="ticket-reporter__history-count" aria-hidden="true">{{ $historyTotal }}</span>
                        <span class="visually-hidden">ทั้งหมด {{ $historyTotal }} รายการ</span>
                    </button>
                @endif
            </div>
        </div>

        <img class="ticket-reporter__art" src="{{ asset($state['image']) }}"
            width="{{ $state['size'][0] }}" height="{{ $state['size'][1] }}" alt="" decoding="async">
    </section>
</div>
