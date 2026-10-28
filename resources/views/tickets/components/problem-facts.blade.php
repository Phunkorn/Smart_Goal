{{--
    ข้อเท็จจริงของ Ticket (ผู้แจ้ง แผนก สาขา ช่องทาง เวลา) — ที่เดียวของรายการนี้
    ใช้ในหัว Ticket ของหน้ารายละเอียด (tickets/show) และในการ์ดรายละเอียดปัญหาของ modal (components/problem)
    พารามิเตอร์: $ticket · $compact (bool, ค่าเริ่มต้น false) — true เมื่อผู้เปิดคือผู้แจ้งของใบนี้ (components/readonly-preview)
    โหมด compact ซ่อนข้อมูลที่ผู้แจ้งรู้อยู่แล้วหรือซ้ำกับความคืบหน้า: ชื่อ แผนก ช่องทางติดต่อ AnyDesk LINE ช่องทางที่แจ้ง
    และเวลารับงานครั้งแรก/ปิดงาน เหลือสาขา/สถานที่และวันติดตามอีกครั้ง · หัวหน้าแผนกและ Handler เห็นครบเหมือนเดิม

    ไอคอน: คลาส bootstrap-icons บน div ของแต่ละช่อง (ticket-fact bi bi-*) วาดเป็น ::before — dl มีได้เฉพาะ div/dt/dd
    จอตั้งแต่ 768px เรียงเป็นคอลัมน์ละสองช่องพร้อมเส้นคั่น (tickets/detail.css)
--}}
@php
    use App\Models\Ticket;
    use App\Support\ThaiDate;
    use App\Support\TicketDesign;

    $compact ??= false;
@endphp
<dl class="ticket-facts">
    @unless ($compact)
        <div class="ticket-fact bi bi-person"><dt>ผู้แจ้ง</dt><dd>{{ $ticket->requester_name }}</dd></div>
        {{-- ฟอร์มสาธารณะให้ผู้แจ้งเลือกแผนกเอง (PublicTicketDepartments) · Ticket เก่าที่ไม่มีค่ายังขึ้นตามช่องทางที่แจ้ง --}}
        <div class="ticket-fact bi bi-building"><dt>แผนก</dt><dd>{{ $ticket->requester_department_name ?? ($ticket->source === Ticket::SOURCE_PUBLIC ? 'บุคคลภายนอก' : 'ไม่ระบุแผนก') }}</dd></div>
    @endunless
    <div class="ticket-fact bi bi-geo-alt-fill"><dt>สาขา/สถานที่</dt><dd>{{ $ticket->location_name }}</dd></div>
    {{-- ทั้งสองช่องทางบังคับเบอร์โทร 10 หลัก (กดโทรได้) · Ticket เก่าอาจเป็นข้อความอิสระหรือว่าง จึงแสดงตามเดิม --}}
    @if (! $compact && $ticket->contact)
        <div class="ticket-fact bi bi-telephone"><dt>ช่องทางติดต่อ</dt><dd>
            @if (preg_match(\App\Services\TicketSubmissionService::PHONE_PATTERN, $ticket->contact))
                <a href="tel:{{ $ticket->contact }}">{{ $ticket->contact }}</a>
            @else
                {{ $ticket->contact }}
            @endif
        </dd></div>
    @endif
    @if (! $compact && $ticket->remote_access)
        <div class="ticket-fact bi bi-display"><dt>AnyDesk / รีโมท</dt><dd>{{ $ticket->remote_access }}</dd></div>
    @endif
    {{-- แสดงเฉพาะชื่อใน LINE — userId ของ LINE ไม่ถูกแสดงที่ใด (Ticket::$hidden) --}}
    @if (! $compact && $ticket->line_display_name)
        <div class="ticket-fact bi bi-line"><dt>LINE</dt><dd>{{ $ticket->line_display_name }}</dd></div>
    @endif
    @unless ($compact)
        <div class="ticket-fact bi bi-chat-square-text"><dt>ช่องทางที่แจ้ง</dt><dd>{{ $ticket->source === Ticket::SOURCE_PUBLIC ? 'Smart Ticket' : 'Smart Goals' }}</dd></div>
    @endunless
    @if (! $compact && $ticket->first_responded_at)
        <div class="ticket-fact bi bi-calendar3"><dt>รับงานครั้งแรก</dt><dd>{{ TicketDesign::when($ticket->first_responded_at) }}</dd></div>
    @endif
    @if ($ticket->follow_up_date)
        <div class="ticket-fact bi bi-calendar-event"><dt>ติดตามอีกครั้ง</dt><dd>{{ ThaiDate::longDay($ticket->follow_up_date) }}</dd></div>
    @endif
    @if (! $compact && $ticket->closed_at)
        <div class="ticket-fact bi bi-check2-circle"><dt>ปิดงานเมื่อ</dt><dd>{{ TicketDesign::when($ticket->closed_at) }}</dd></div>
    @endif
</dl>
