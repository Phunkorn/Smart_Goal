{{--
    ตัวแบ่งหน้าของรายงาน Smart Ticket — "แสดง x–y จาก n รายการ" และเลขหน้าแบบมีช่วง (… เมื่อหน้ามาก)
    URL คงตัวกรอง คำค้น ไว้ (TicketReportService ใส่ไว้ใน appends)
    จอโทรศัพท์ซ่อนเลขหน้าตรงกลาง เหลือ "ก่อนหน้า / หน้า x จาก y / ถัดไป" ไม่ให้ล้นจอ

    @param string $label  ชื่อสำหรับ screen reader
--}}
@if ($paginator->hasPages())
    <nav class="ticket-report__pager" aria-label="{{ $label ?? 'การแบ่งหน้า' }}">
        <p class="ticket-report__pager-summary">แสดง {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} จาก {{ number_format($paginator->total()) }} รายการ</p>

        <ul class="ticket-report__pages">
            <li class="ticket-report__pages-edge">
                @if ($paginator->onFirstPage())
                    <span class="ticket-report__page is-disabled" aria-disabled="true" aria-label="หน้าก่อนหน้า"><i class="bi bi-chevron-left" aria-hidden="true"></i></span>
                @else
                    <a class="ticket-report__page" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="หน้าก่อนหน้า"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                @endif
            </li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="ticket-report__pages-number"><span class="ticket-report__page is-gap" aria-hidden="true">…</span></li>
                @else
                    @foreach ($element as $page => $url)
                        <li class="ticket-report__pages-number">
                            @if ($page === $paginator->currentPage())
                                <span class="ticket-report__page is-current" aria-current="page" aria-label="หน้า {{ $page }} (หน้าปัจจุบัน)">{{ $page }}</span>
                            @else
                                <a class="ticket-report__page" href="{{ $url }}" aria-label="ไปหน้า {{ $page }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li class="ticket-report__pages-status" aria-hidden="true">หน้า {{ number_format($paginator->currentPage()) }} จาก {{ number_format($paginator->lastPage()) }}</li>

            <li class="ticket-report__pages-edge">
                @if ($paginator->hasMorePages())
                    <a class="ticket-report__page" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="หน้าถัดไป"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                @else
                    <span class="ticket-report__page is-disabled" aria-disabled="true" aria-label="หน้าถัดไป"><i class="bi bi-chevron-right" aria-hidden="true"></i></span>
                @endif
            </li>
        </ul>
    </nav>
@endif
