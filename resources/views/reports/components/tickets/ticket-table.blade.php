{{--
    รายการ Ticket ในชุดของรายงาน — แบ่งหน้าที่ server (TicketReportService::TICKETS_PER_PAGE)

    ช่องค้นหาเป็นฟอร์ม GET ของตัวเอง (เลข Ticket / รายละเอียด / ชื่อผู้แจ้ง) กรองเฉพาะรายการนี้ ไม่กระทบกราฟและตัวเลขสรุป
    ช่วงเวลาและตัวกรองหลักส่งต่อเป็น hidden input จาก $query ชุดเดียวกับลิงก์แบ่งหน้า

    คอลัมน์: Ticket · หัวข้อปัญหา · แผนก/หน่วยงาน · ผู้แจ้ง · วันที่แจ้ง · ตอบรับ / แก้ไข · ผู้ดำเนินงาน · สถานะ · ดูรายละเอียด
    (สถานะเป็นคอลัมน์ข้อมูลสุดท้าย ลิงก์ดูรายละเอียดอยู่ขวาสุดเสมอ — ลำดับเดียวกันทั้ง colgroup หัวตาราง และเซลล์)
    ข้อมูลรองอยู่ใต้ข้อมูลหลักในช่องเดียวกัน (สาขา ใต้แผนก · ช่องทางที่แจ้ง ใต้ผู้แจ้ง · เวลา ใต้วันที่แจ้ง · เปิดใหม่ ใต้สถานะ)
    เวลาตอบรับ/แก้ไขแยกเป็นคอลัมน์ของตัวเองเพื่อไล่เทียบกันได้ทั้งตาราง — ยังไม่มีข้อมูล (ยังไม่รับ / ยังไม่ปิด) แสดง "—" ไม่ใช่ 0
    ไม่มี checkbox เพราะรายงานไม่มี bulk action · ตัวตนผู้ดำเนินงานใช้ snapshot และตัวตนทางประวัติ (บัญชีที่ลบถาวรยังรู้ชื่อ)

    ตารางตั้งแต่ 1200px แคบกว่านั้นแต่ละแถวเป็นการ์ด (markup ชุดเดียว — reports/tickets.css)
--}}
@php
    use App\Models\Ticket;
    use App\Services\TicketReportService;
    use App\Support\TicketDesign;

    $searching = $search !== '';
    $hiddenQuery = collect($query)->except(['q', 'page', 'people_page']);
@endphp
<section class="ticket-report__section ticket-report__list" id="ticket-report-tickets" aria-labelledby="ticket-report-tickets-title" data-ticket-report-list>
    <div class="ticket-report__section-head">
        <div>
            <h2 id="ticket-report-tickets-title">รายการ Ticket</h2>
            <p class="ticket-report__section-note">
                @if ($searching)
                    พบ {{ number_format($tickets->total()) }} จาก {{ number_format($total) }} รายการ · เรียงจากแจ้งล่าสุด
                @else
                    {{ number_format($total) }} รายการ · เรียงจากแจ้งล่าสุด
                @endif
            </p>
        </div>

        <form class="ticket-report__search" method="GET" action="{{ route('reports.tickets') }}#ticket-report-tickets" role="search" data-auto-submit-form>
            @foreach ($hiddenQuery as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <label class="visually-hidden" for="ticketReportSearch">ค้นหา Ticket ด้วยเลข รายละเอียด หรือชื่อผู้แจ้ง</label>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input id="ticketReportSearch" class="form-control" type="search" name="q" value="{{ $search }}"
                placeholder="ค้นหาในรายการ Ticket" maxlength="100" autocomplete="off" data-auto-submit-debounce="500">
            <noscript><button type="submit" class="ticket-report__button ticket-report__button--primary">ค้นหา</button></noscript>
        </form>
    </div>

    @if ($tickets->isEmpty())
        <div class="ticket-report__none" role="status">
            <strong>{{ $searching ? 'ไม่พบ Ticket ที่ตรงกับ “'.$search.'”' : 'ไม่มี Ticket ที่ตรงกับตัวกรอง' }}</strong>
            @if ($searching)
                <a class="ticket-report__button" href="{{ route('reports.tickets', $hiddenQuery->all()) }}#ticket-report-tickets">ล้างคำค้นหา</a>
            @endif
        </div>
    @else
        <table class="ticket-report__table ticket-report__table--cards ticket-report__table--tickets">
            {{-- สัดส่วนคอลัมน์บนจอกว้าง (reports/tickets.css) — โหมดการ์ดไม่ใช้ colgroup --}}
            <colgroup>
                <col class="ticket-report__col--ticket">
                <col class="ticket-report__col--summary">
                <col class="ticket-report__col--department">
                <col class="ticket-report__col--requester">
                <col class="ticket-report__col--when">
                <col class="ticket-report__col--durations">
                <col class="ticket-report__col--people">
                <col class="ticket-report__col--status">
                <col class="ticket-report__col--action">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">Ticket</th>
                    <th scope="col">หัวข้อปัญหา</th>
                    <th scope="col">แผนก/หน่วยงาน</th>
                    <th scope="col">ผู้แจ้ง</th>
                    <th scope="col">วันที่แจ้ง</th>
                    <th scope="col">ตอบรับ / แก้ไข</th>
                    <th scope="col">ผู้ดำเนินงาน</th>
                    <th scope="col">สถานะ</th>
                    <th scope="col" class="ticket-report__action"><span class="visually-hidden">รายละเอียด</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tickets as $ticket)
                    @php
                        $status = TicketDesign::status($ticket->status);
                        $source = TicketDesign::source($ticket->source);
                        $when = TicketDesign::whenParts($ticket->created_at);
                        $response = TicketReportService::durationOf($ticket, 'first_responded_at');
                        $resolution = TicketReportService::durationOf($ticket, 'closed_at');
                        // ผู้ประสานงาน/ผู้ปิดหาได้จากผู้เข้าร่วมที่โหลดมาแล้ว — ผู้ปิดใช้ตัวตนทางประวัติ (closed_by_ref_id)
                        // จึงยังรู้ชื่อแม้บัญชีถูกลบถาวร
                        $coordinatorId = $ticket->coordinator_id;
                        $closer = $ticket->closed_by_ref_id
                            ? $ticket->participants->first(fn ($p) => (int) ($p->user_ref_id ?? $p->user_id) === (int) $ticket->closed_by_ref_id)
                            : null;
                    @endphp
                    <tr data-ticket-row="{{ $ticket->id }}">
                        <th scope="row" class="ticket-report__number">{{ $ticket->ticket_number }}</th>
                        <td data-label="หัวข้อปัญหา" class="ticket-report__summary">{{ $ticket->summary }}</td>
                        {{-- เซลล์ที่มีข้อมูลรองห่อเป็นก้อนเดียว: โหมดการ์ดจัดเป็นสองคอลัมน์ (ป้าย | ค่า) --}}
                        <td data-label="แผนก/หน่วยงาน">
                            <div class="ticket-report__stack">
                                {{ $ticket->source === Ticket::SOURCE_PUBLIC ? 'Smart Ticket' : ($ticket->requester_department_name ?? 'ไม่ระบุแผนก') }}
                                <small class="ticket-report__muted">{{ $ticket->location_name }}</small>
                            </div>
                        </td>
                        <td data-label="ผู้แจ้ง">
                            <div class="ticket-report__stack">
                                {{ $ticket->requester_name }}
                                <small class="ticket-report__muted">{{ $source['label'] }}</small>
                            </div>
                        </td>
                        <td data-label="วันที่แจ้ง">
                            <div class="ticket-report__stack">
                                @if ($when)
                                    <span class="ticket-report__date-part">{{ $when['date'] }}</span>
                                    <small class="ticket-report__muted">{{ $when['time'] }}</small>
                                @else
                                    —
                                @endif
                            </div>
                        </td>
                        <td data-label="ตอบรับ / แก้ไข">
                            <dl class="ticket-report__durations">
                                <div>
                                    <dt>ตอบรับ</dt>
                                    <dd>@if ($response){{ $response }}@else—<span class="visually-hidden">ยังไม่ตอบรับ</span>@endif</dd>
                                </div>
                                <div>
                                    <dt>แก้ไข</dt>
                                    <dd>@if ($resolution){{ $resolution }}@else—<span class="visually-hidden">ยังไม่ปิดงาน</span>@endif</dd>
                                </div>
                            </dl>
                        </td>
                        <td data-label="ผู้ดำเนินงาน" data-cell="participants">
                            <div class="ticket-report__stack">
                                @if ($ticket->participants->isEmpty())
                                    <span class="ticket-report__muted">ยังไม่มีผู้รับงาน</span>
                                @else
                                    <ul class="ticket-report__people">
                                        @foreach ($ticket->participants as $participant)
                                            <li>
                                                {{ $participant->user_name }}
                                                @if ($coordinatorId && (int) $participant->user_id === (int) $coordinatorId)
                                                    <span class="ticket-report__role">ผู้ประสานงาน</span>
                                                @endif
                                                @if ($participant->left_at)
                                                    <span class="ticket-report__role ticket-report__role--muted">ออกแล้ว</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($closer)
                                        <small class="ticket-report__muted">ปิดโดย {{ $closer->user_name }}</small>
                                    @endif
                                @endif
                            </div>
                        </td>
                        <td data-label="สถานะ">
                            <div class="ticket-report__stack">
                                <span class="badge-soft {{ $status['tone'] }}">{{ $status['label'] }}</span>
                                @if ($ticket->reopen_count > 0)
                                    <small class="ticket-report__muted">เปิดใหม่ {{ number_format($ticket->reopen_count) }} ครั้ง</small>
                                @endif
                            </div>
                        </td>
                        <td class="ticket-report__action">
                            <a class="ticket-report__open" href="{{ route('tickets.show', $ticket) }}" aria-label="ดูรายละเอียด {{ $ticket->ticket_number }}" title="ดูรายละเอียด"><i class="bi bi-eye" aria-hidden="true"></i></a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $tickets->fragment('ticket-report-tickets')->links('reports.components.tickets.pager', ['label' => 'การแบ่งหน้ารายการ Ticket']) }}
    @endif
</section>
