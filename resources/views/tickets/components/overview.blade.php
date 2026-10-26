{{--
    ภาพรวมของหน้ารับแจ้งปัญหา — ตัวเลขจริงจาก TicketQueryService (ไม่มีกราฟแนวโน้มหรือตัวเลขเปรียบเทียบ)
    การ์ดเป็นตัวกรองสถานะของคิวงานด้วย (แทนแท็บสถานะ) — คงคำค้นหา สาขา และขอบเขตไว้ (TicketDesign::queueStatusUrl)
      Ticket ทั้งหมด = ทุกสถานะรวมกัน · ลิงก์ล้างทั้งตัวกรองสถานะและวัน
      งานในวันนี้   = Ticket ที่แจ้งในวันนี้ตามเวลากรุงเทพ (createdToday) · ลิงก์กรองคิวด้วย day=today
      รอรับงาน / กำลังดำเนินการ / งานที่ต้องติดตาม / ปิดงานแล้ว = จำนวนทั้งระบบต่อสถานะ เป็นลิงก์กรองคิวตามสถานะนั้น
    สถานะกับวันเป็นชุดเลือกอย่างใดอย่างหนึ่ง (TicketDesign::queueStatusUrl ตั้งค่าทั้งคู่ทุกครั้ง)
    การ์ดที่ใช้อยู่จึงมีใบเดียวและมี aria-current="page" · ทุกใบมีบรรทัดหมายเหตุ จึงสูงเท่ากันและป้ายตรงแนวกัน
    ไอคอนของการ์ดสถานะมาจาก TicketDesign::STATUSES (ที่เดียว) · ตัวอักษรน้ำหนักกลาง ตัวเลขเท่านั้นที่เด่น (tickets/desk.css)
    ตัวเลขไม่ขึ้นกับตัวกรองของคิว · "ปิดงานแล้ว" บอกจำนวนที่ปิดวันนี้จาก closed_at (เวลากรุงเทพ)

    พารามิเตอร์: $overview = ['counts' => [status => int], 'closed_today' => int], $createdToday (int),
                $filters (ตัวกรองของคิวที่ normalize แล้ว)
--}}
@php
    use App\Models\Ticket;
    use App\Services\TicketQueryService;
    use App\Support\TicketDesign;

    $statuses = TicketDesign::statuses();
    $total = array_sum($overview['counts']);
    // ลำดับตามหน้าจอ: ภาพรวม → งานวันนี้ → รอรับงาน (ต้องลงมือก่อน) → กำลังดำเนินการ → งานที่ต้องติดตาม → ปิดงาน
    $statusCards = [Ticket::STATUS_NEW, Ticket::STATUS_ACTIVE, Ticket::STATUS_FOLLOW_UP, Ticket::STATUS_CLOSED];
@endphp
<section class="ticket-overview" aria-labelledby="ticket-overview-title" data-ticket-overview>
    <h2 class="visually-hidden" id="ticket-overview-title">ภาพรวม Ticket ทั้งระบบ · เลือกการ์ดเพื่อกรองรายการตามสถานะหรือเฉพาะที่แจ้งวันนี้</h2>

    <a class="ticket-overview__item ticket-overview__item--total" href="{{ TicketDesign::queueStatusUrl($filters, '') }}" data-ticket-overview="all"
        @if ($filters['status'] === '' && $filters['day'] === '') aria-current="page" @endif>
        <span class="ticket-overview__icon" aria-hidden="true"><i class="bi bi-ticket-perforated"></i></span>
        <span class="ticket-overview__body">
            <span class="ticket-overview__label">Ticket ทั้งหมด</span>
            <span class="ticket-overview__figure">
                <strong class="ticket-overview__value">{{ number_format($total) }}</strong>
                <span class="ticket-overview__unit">รายการ</span>
            </span>
            <span class="ticket-overview__note">ทุกสถานะรวมกัน</span>
        </span>
    </a>

    <a class="ticket-overview__item ticket-overview__item--today" href="{{ TicketDesign::queueStatusUrl($filters, '', TicketQueryService::DAY_TODAY) }}"
        data-ticket-overview="today" @if ($filters['day'] === TicketQueryService::DAY_TODAY) aria-current="page" @endif>
        <span class="ticket-overview__icon" aria-hidden="true"><i class="bi bi-calendar-plus"></i></span>
        <span class="ticket-overview__body">
            <span class="ticket-overview__label">งานในวันนี้</span>
            <span class="ticket-overview__figure">
                <strong class="ticket-overview__value">{{ number_format($createdToday) }}</strong>
                <span class="ticket-overview__unit">รายการ</span>
            </span>
            <span class="ticket-overview__note">แจ้งวันนี้</span>
        </span>
    </a>

    @foreach ($statusCards as $status)
        @php($design = $statuses[$status])
        <a class="ticket-overview__item ticket-overview__item--{{ $design['tone'] }}" href="{{ TicketDesign::queueStatusUrl($filters, $status) }}"
            data-ticket-overview="{{ $status }}" @if ($filters['status'] === $status) aria-current="page" @endif>
            <span class="ticket-overview__icon" aria-hidden="true"><i class="bi {{ $design['icon'] }}"></i></span>
            <span class="ticket-overview__body">
                <span class="ticket-overview__label">{{ $status === Ticket::STATUS_CLOSED ? 'ปิดงานแล้ว' : $design['label'] }}</span>
                <span class="ticket-overview__figure">
                    <strong class="ticket-overview__value">{{ number_format($overview['counts'][$status]) }}</strong>
                    <span class="ticket-overview__unit">รายการ</span>
                </span>
                <span class="ticket-overview__note">
                    {{ $status === Ticket::STATUS_CLOSED ? 'ปิดวันนี้ '.number_format($overview['closed_today']).' รายการ' : 'ทั้งระบบ' }}
                </span>
            </span>
        </a>
    @endforeach
</section>
