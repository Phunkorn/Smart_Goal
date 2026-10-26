{{--
    ตัวแบ่งหน้าของรายการ Ticket — ใช้ผ่าน $tickets->links('tickets.components.pagination')
    ซ้ายบอกช่วงรายการที่แสดงจากทั้งหมด ขวาคือ ก่อนหน้า · เลขหน้า · ถัดไป (ปุ่มลูกศรเป็นไอคอนล้วน ชื่ออยู่ที่ aria-label)
    เลขหน้าแสดงเฉพาะหน้าแรก หน้าสุดท้าย และหน้าข้างเคียง (… คั่น) จึงไม่ล้นจอแม้จำนวนหน้ามาก
    จอแคบกว่า 768px เลขหน้าถูกแทนด้วย "หน้า x จาก y" (ตัวควบคุมเดียวกัน คนละการแสดงผล — กฎอยู่ใน tickets/list.css)
    URL ของแต่ละหน้าคงตัวกรองไว้ด้วย withQueryString() (TicketQueryService::paginate)
--}}
@if ($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $pages = collect([1, $current - 1, $current, $current + 1, $last])
            ->filter(fn (int $page) => $page >= 1 && $page <= $last)
            ->unique()->sort()->values();
        $previousPage = null;
    @endphp
    <nav class="ticket-pager" aria-label="การแบ่งหน้ารายการ Ticket">
        <p class="ticket-pager__range">
            แสดง {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} จาก {{ number_format($paginator->total()) }} รายการ
        </p>

        <div class="ticket-pager__controls">
            @if ($paginator->onFirstPage())
                <span class="ticket-pager__link is-disabled" role="link" aria-disabled="true" aria-label="ก่อนหน้า">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </span>
            @else
                <a class="ticket-pager__link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="ก่อนหน้า">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </a>
            @endif

            <ol class="ticket-pager__pages">
                @foreach ($pages as $page)
                    @if ($previousPage !== null && $page - $previousPage > 1)
                        <li class="ticket-pager__gap" aria-hidden="true">…</li>
                    @endif
                    <li>
                        @if ($page === $current)
                            <span class="ticket-pager__page is-current" aria-current="page">{{ number_format($page) }}</span>
                        @else
                            <a class="ticket-pager__page" href="{{ $paginator->url($page) }}" aria-label="ไปหน้า {{ number_format($page) }}">{{ number_format($page) }}</a>
                        @endif
                    </li>
                    @php($previousPage = $page)
                @endforeach
            </ol>

            <span class="ticket-pager__status">
                หน้า {{ number_format($current) }} จาก {{ number_format($last) }}
            </span>

            @if ($paginator->hasMorePages())
                <a class="ticket-pager__link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="ถัดไป">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            @else
                <span class="ticket-pager__link is-disabled" role="link" aria-disabled="true" aria-label="ถัดไป">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </span>
            @endif
        </div>
    </nav>
@endif
