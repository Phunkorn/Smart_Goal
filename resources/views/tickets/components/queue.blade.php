{{--
    คิวงานของหน้ารับแจ้งปัญหา — หัวคิว (ค้นหา + ตัวกรอง) แท็บสถานะ ตาราง และการแบ่งหน้า (TicketQueryService::paginate)
    ทุก Ticket เข้าถึงได้ผ่านตัวกรองและการแบ่งหน้า ตัวกรองถูก normalize ที่ server ก่อนใช้

    หัวคิว: ชื่อ + ช่องค้นหา + ปุ่ม "ตัวกรอง" อยู่แถวเดียวกัน ปุ่มเปิด/พับแผงสาขา/ขอบเขต (tickets/index.js)
    ไม่มี JavaScript แผงแสดงอยู่เสมอและปุ่มถูกซ่อน จึงกรองได้ครบเหมือนเดิม · แผงเปิดเองเมื่อมีตัวกรองสาขา/ขอบเขตใช้อยู่

    ตาราง markup ชุดเดียว: ตั้งแต่ 1280px เป็นตาราง แคบกว่านั้นแต่ละแถวเป็นการ์ด (tickets/list.css + responsive.css)
    ข้อมูลทุกช่องแสดงเสมอ ไม่ซ่อนตาม breakpoint · โหมดตารางความกว้างคอลัมน์มาจาก <colgroup> (ไม่ตามข้อความในแถว)
    หัวข้อและชื่อยาวตัดด้วย … และ title เก็บข้อความเต็ม · เหตุผลยกเลิกตัดบรรทัดแสดงเต็ม
    เจ็ดคอลัมน์ บรรทัดเดียวต่อแถว: เลข Ticket · หัวข้อปัญหา · ผู้แจ้ง · สาขา/สถานที่ · เวลาแจ้ง · สถานะ · ดำเนินการ
    ดำเนินการ = ปุ่มรับงาน/เข้าร่วม · ยกเลิก Ticket ใหม่ · ดูรายละเอียด ตามสิทธิ์ของผู้ดู
    ผู้แจ้งและสาขาเป็น snapshot บน Ticket (requester_name, location_name) — ไม่มีคิวรีเพิ่มต่อแถว · ผู้แจ้งแสดงชื่ออย่างเดียว
    ดำเนินการ = ปุ่มไอคอนรับงาน/เข้าร่วม (components/claim-actions แบบ compact) ตาม TicketPolicy — viewer_participating มาจาก withExists ในคิวรีเดียว
    ดูรายละเอียดเปิด modal (components/preview-modal + tickets/desk.js) ลิงก์ยังชี้หน้ารายละเอียดเต็มสำหรับเปิดแท็บใหม่/ไม่มี JS
    [data-ticket-queue-results] คือส่วนที่ tickets/desk.js โหลดใหม่แบบ async — ฟอร์มค้นหาไม่ถูกแทนที่ จึงไม่เสียสิ่งที่กำลังพิมพ์
    แผนก ช่องทางที่แจ้ง และรายชื่อเจ้าหน้าที่ที่เข้าร่วมอยู่ในหน้ารายละเอียดของ Ticket (ไม่ได้ลบข้อมูล) — คิวนี้ไม่โหลดผู้เข้าร่วม
    ไม่มีคอลัมน์ความสำคัญ เพราะ Ticket ไม่มีฟิลด์นี้ในระบบ · ไม่มี checkbox/bulk action เพราะระบบไม่มีฟังก์ชันรองรับ
--}}
@php
    use App\Services\TicketQueryService;
    use App\Support\TicketDesign;

    $activeFilters = array_filter($filters, fn ($value) => $value !== '');
    $hasFilterBesidesStatus = collect($activeFilters)->except('status')->isNotEmpty();
    // ตัวกรองที่อยู่ในแผง (สาขา/ขอบเขต) — ค้นหาและสถานะมีที่แสดงของตัวเองนอกแผง
    $panelFilterCount = collect($filters)->only(['location', 'scope'])->filter(fn ($value) => $value !== '')->count();
    $viewer = auth()->user();
    // รายการของหัวหน้าแผนก (tickets/department) — อ่านอย่างเดียว ไม่มีคอลัมน์รับงาน/เข้าร่วม
    $readonly ??= false;
    // แท็บสถานะ (นับตามตัวกรองปัจจุบัน) — หน้ารับแจ้งปัญหาปิดไว้ เพราะการ์ดภาพรวมด้านบนเป็นตัวกรองสถานะแทน
    $statusTabs ??= true;
@endphp
<section class="ticket-queue panel" id="ticket-queue" aria-labelledby="ticket-queue-title">
    <form class="ticket-queue__head ticket-filters" method="GET" action="{{ route('tickets.index') }}#ticket-queue" role="search" data-auto-submit-form>
        {{-- สถานะและวันเลือกจากการ์ดภาพรวม ไม่มีช่องในฟอร์มนี้ — ส่งกลับไปด้วยเพื่อให้การค้นหาไม่ล้างตัวกรองที่การ์ดตั้งไว้ --}}
        @if ($filters['status'] !== '')
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
        @endif

        @if ($filters['day'] !== '')
            <input type="hidden" name="day" value="{{ $filters['day'] }}">
        @endif

        <h2 class="ticket-section-title" id="ticket-queue-title">รายการ Ticket</h2>

        <div class="ticket-filters__bar">
            <div class="ticket-filters__search">
                <label class="visually-hidden" for="ticket-search">ค้นหา Ticket ด้วยเลข รายละเอียด หรือชื่อผู้แจ้ง</label>
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="ticket-search" class="form-control" type="search" name="q" value="{{ $filters['q'] }}"
                    placeholder="ค้นหาเลข Ticket, หัวข้อปัญหา, ผู้แจ้ง..." maxlength="100" data-auto-submit-debounce="500">
            </div>

            <button type="button" class="ticket-button ticket-button--ghost ticket-filters__toggle" hidden
                aria-expanded="{{ $panelFilterCount > 0 ? 'true' : 'false' }}" aria-controls="ticket-filter-panel" data-ticket-filter-toggle>
                <i class="bi bi-funnel" aria-hidden="true"></i> ตัวกรอง
                @if ($panelFilterCount > 0)
                    <span class="ticket-filters__badge" aria-label="ใช้อยู่ {{ $panelFilterCount }} ตัวกรอง">{{ $panelFilterCount }}</span>
                @endif
            </button>
        </div>

        <div class="ticket-filters__panel" id="ticket-filter-panel" data-ticket-filter-panel @if ($panelFilterCount > 0) data-open @endif>
            <div class="ticket-filters__field">
                <label class="ticket-filters__label" for="ticket-location-filter">สาขา/สถานที่</label>
                <select id="ticket-location-filter" class="form-select" name="location" data-auto-submit>
                    <option value="">ทุกสาขา</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected($filters['location'] === (string) $location->id)>{{ $location->name }}</option>
                    @endforeach
                    <option value="{{ TicketQueryService::LOCATION_OTHER }}" @selected($filters['location'] === TicketQueryService::LOCATION_OTHER)>อื่น ๆ (ระบุเอง)</option>
                </select>
            </div>

            @if ($scopeOptions !== [])
                <div class="ticket-filters__field">
                    <label class="ticket-filters__label" for="ticket-scope-filter">แสดง</label>
                    <select id="ticket-scope-filter" class="form-select" name="scope" data-auto-submit>
                        <option value="">ทั้งหมด</option>
                        @foreach ($scopeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['scope'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="ticket-filters__actions">
                <noscript><button type="submit" class="ticket-button ticket-button--primary">แสดงผล</button></noscript>
                @if ($activeFilters !== [])
                    <a class="ticket-button ticket-button--ghost" href="{{ route('tickets.index') }}#ticket-queue">
                        <i class="bi bi-x-lg" aria-hidden="true"></i> ล้างตัวกรอง
                    </a>
                @endif
            </div>
        </div>
    </form>

    <div class="ticket-queue__results" data-ticket-queue-results>
    <p class="visually-hidden ticket-results" aria-live="polite">
        @unless ($tickets->isEmpty())
            พบ {{ number_format($tickets->total()) }} รายการ
        @endunless
    </p>

    @if ($statusTabs)
        <nav class="ticket-tabs" aria-label="กรองตามสถานะ">
            <a class="ticket-tabs__item {{ $filters['status'] === '' ? 'is-active' : '' }}" href="{{ TicketDesign::queueStatusUrl($filters, '') }}"
                @if($filters['status'] === '') aria-current="page" @endif>
                ทั้งหมด <span class="ticket-tabs__count">{{ number_format(array_sum($statusCounts)) }}</span>
            </a>
            @foreach (TicketDesign::statuses() as $status => $design)
                <a class="ticket-tabs__item {{ $filters['status'] === $status ? 'is-active' : '' }}" href="{{ TicketDesign::queueStatusUrl($filters, $status) }}"
                    @if($filters['status'] === $status) aria-current="page" @endif>
                    {{ $design['label'] }} <span class="ticket-tabs__count">{{ number_format($statusCounts[$status]) }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    @if ($tickets->isEmpty())
        <div class="ticket-empty" aria-live="polite">
            <span class="ticket-empty__icon" aria-hidden="true"><i class="bi {{ $activeFilters === [] ? 'bi-inbox' : 'bi-search' }}"></i></span>
            @if ($activeFilters === [])
                <h3>ยังไม่มี Ticket</h3>
                <p>เมื่อมีผู้แจ้งปัญหา Ticket จะเข้าคิวที่นี่</p>
            @else
                <h3>ไม่พบ Ticket ตามตัวกรอง</h3>
                <p>ลองเปลี่ยนคำค้นหาหรือสถานะ{{ $hasFilterBesidesStatus ? ' หรือล้างตัวกรองทั้งหมด' : '' }}</p>
                <a class="ticket-button ticket-button--ghost" href="{{ route('tickets.index') }}#ticket-queue">ล้างตัวกรอง</a>
            @endif
        </div>
    @else
        <table class="ticket-queue__table">
            <caption class="visually-hidden">คิว Ticket หน้า {{ $tickets->currentPage() }} จาก {{ $tickets->lastPage() }}</caption>
            <colgroup>
                <col class="ticket-queue__col--number">
                <col class="ticket-queue__col--problem">
                <col class="ticket-queue__col--requester">
                <col class="ticket-queue__col--location">
                <col class="ticket-queue__col--time">
                <col class="ticket-queue__col--status">
                <col class="ticket-queue__col--action">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">Ticket</th>
                    <th scope="col">หัวข้อปัญหา</th>
                    <th scope="col">ผู้แจ้ง</th>
                    <th scope="col">สาขา/สถานที่</th>
                    <th scope="col">เวลาแจ้ง</th>
                    <th scope="col">สถานะ</th>
                    <th scope="col">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tickets as $ticket)
                    @php
                        $status = TicketDesign::ticketStatus($ticket);
                        $cancelReason = $status['label'] === 'ยกเลิก' ? $ticket->closingActivity?->body : null;
                        $when = TicketDesign::whenParts($ticket->created_at);
                        $canStart = ! $readonly && $viewer->can('start', $ticket);
                        $canJoin = ! $readonly && $viewer->can('join', $ticket) && ! $ticket->viewer_participating;
                        $canCancel = ! $readonly && $ticket->status === \App\Models\Ticket::STATUS_NEW && $viewer->can('cancel', $ticket);
                    @endphp
                    <tr id="ticket-row-{{ $ticket->id }}" data-ticket-row="{{ $ticket->id }}">
                        <th scope="row" class="ticket-queue__ticket">
                            <a class="ticket-queue__number" href="{{ route('tickets.show', $ticket) }}"
                                data-ticket-preview="{{ route('tickets.preview', $ticket) }}">{{ $ticket->ticket_number }}</a>
                        </th>
                        <td class="ticket-queue__problem">
                            <span class="ticket-queue__summary" title="{{ $ticket->summary }}">{{ $ticket->summary }}</span>
                        </td>
                        <td data-label="ผู้แจ้ง">
                            <span class="ticket-queue__name" title="{{ $ticket->requester_name }}">{{ $ticket->requester_name }}</span>
                        </td>
                        <td data-label="สาขา/สถานที่">
                            <span class="ticket-queue__location" title="{{ $ticket->location_name }}">{{ $ticket->location_name }}</span>
                        </td>
                        <td data-label="เวลาแจ้ง">
                            <time class="ticket-queue__time" datetime="{{ $ticket->created_at->toIso8601String() }}"><span>{{ $when['date'] }}</span> <span>{{ $when['time'] }}</span></time>
                        </td>
                        <td data-label="สถานะ">
                            <div class="ticket-queue__status-content">
                                <span class="badge-soft {{ $status['tone'] }} ticket-status">{{ $status['label'] }}</span>
                                @if ($cancelReason)
                                    <span class="ticket-queue__cancel-reason">เหตุผล: {{ $cancelReason }}</span>
                                @endif
                            </div>
                        </td>
                        {{-- ดำเนินการ: รับงาน/เข้าร่วม ยกเลิก Ticket ใหม่ และดูรายละเอียด --}}
                        <td class="ticket-queue__claim" data-label="ดำเนินการ">
                            <div class="ticket-actions ticket-queue__claim-actions">
                                @if ($canStart || $canJoin)
                                    @include('tickets.components.claim-actions', ['compact' => true])
                                @endif
                                @if ($canCancel)
                                    <button type="button" class="ticket-button ticket-button--danger ticket-button--icon ticket-actions__button"
                                        data-bs-toggle="modal" data-bs-target="#ticket-modal-cancel-{{ $ticket->id }}"
                                        aria-label="ยกเลิกงาน {{ $ticket->ticket_number }}" title="ยกเลิกงาน">
                                        <i class="bi bi-x-circle" aria-hidden="true"></i>
                                    </button>
                                @endif
                                <a class="ticket-queue__open" href="{{ route('tickets.show', $ticket) }}"
                                    data-ticket-preview="{{ route('tickets.preview', $ticket) }}"
                                    aria-label="ดูรายละเอียด {{ $ticket->ticket_number }}" title="ดูรายละเอียด">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @foreach ($tickets as $ticket)
            @if (! $readonly && $ticket->status === \App\Models\Ticket::STATUS_NEW && $viewer->can('cancel', $ticket))
                @include('tickets.components.status-modal', [
                    'action' => \App\Models\Ticket::ACTION_CANCEL, 'modalId' => 'ticket-modal-cancel-'.$ticket->id,
                    'queueTicketId' => $ticket->id, 'title' => 'ยกเลิกงาน '.$ticket->ticket_number,
                    'noteLabel' => 'เหตุผลที่ยกเลิก', 'placeholder' => 'ระบุเหตุผลที่ยกเลิก Ticket นี้',
                    'submitLabel' => 'ยืนยันยกเลิกงาน', 'tone' => 'danger', 'withDate' => false,
                ])
            @endif
        @endforeach

        {{ $tickets->fragment('ticket-queue')->links('tickets.components.pagination') }}
    @endif
    </div>
</section>
