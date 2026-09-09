@if ($paginator->hasPages())
    <nav class="notification-center__pagination d-flex flex-column flex-md-row align-items-center justify-content-between gap-3" aria-label="การแบ่งหน้าการแจ้งเตือน">
        <p class="notification-center__pagination-summary small mb-0 text-center text-md-start">
            แสดง <span class="fw-semibold">{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}</span>
            จากทั้งหมด <span class="fw-semibold">{{ number_format($paginator->total()) }}</span> รายการ
        </p>

        <ul class="pagination flex-wrap justify-content-center gap-2 mb-0">
            <li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}">
                @if ($paginator->onFirstPage())
                    <span class="page-link" aria-disabled="true" aria-label="หน้าก่อนหน้า">&lsaquo;</span>
                @else
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="หน้าก่อนหน้า">&lsaquo;</a>
                @endif
            </li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item disabled"><span class="page-link" aria-disabled="true">{{ $element }}</span></li>
                @elseif (is_array($element))
                    @foreach ($element as $page => $url)
                        <li class="page-item {{ $page == $paginator->currentPage() ? 'active' : '' }}">
                            @if ($page == $paginator->currentPage())
                                <span class="page-link" aria-current="page" aria-label="หน้า {{ $page }}">{{ $page }}</span>
                            @else
                                <a class="page-link" href="{{ $url }}" aria-label="ไปหน้า {{ $page }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li class="page-item {{ $paginator->hasMorePages() ? '' : 'disabled' }}">
                @if ($paginator->hasMorePages())
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="หน้าถัดไป">&rsaquo;</a>
                @else
                    <span class="page-link" aria-disabled="true" aria-label="หน้าถัดไป">&rsaquo;</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
