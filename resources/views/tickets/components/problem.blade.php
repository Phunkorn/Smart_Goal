{{--
    การ์ดรายละเอียดปัญหา — หน้ารายละเอียด (tickets/show) และ modal ดูรายละเอียด (components/preview ของคิว, components/readonly-preview)
    ปัญหาที่แจ้ง (กล่องพื้นอ่อน) แล้วข้อเท็จจริงพร้อมไอคอน (components/problem-facts — ที่เดียว)
    พารามิเตอร์: $ticket · $compact (bool, ค่าเริ่มต้น false) — ส่งต่อให้ problem-facts
--}}
<section class="ticket-detail__problem panel ticket-panel" aria-labelledby="ticket-problem-title">
    <h2 class="ticket-panel__title" id="ticket-problem-title">
        <span class="ticket-panel__icon" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span>
        รายละเอียดปัญหา
    </h2>
    <p class="ticket-detail__description">{{ $ticket->description }}</p>

    @include('tickets.components.problem-facts', ['compact' => $compact ?? false])
</section>
