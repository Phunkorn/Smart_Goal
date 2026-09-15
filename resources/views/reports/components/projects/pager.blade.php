{{--
    แบ่งหน้าของหน้า "ดูรายละเอียดทั้งหมด" — ลิงก์ทุกอันพก query ที่ server ตรวจแล้ว

    @param \Illuminate\Pagination\LengthAwarePaginator $pageRows
--}}
@php
    $current = $pageRows->currentPage();
    $last = $pageRows->lastPage();
    $pages = collect(range(1, $last))->filter(fn (int $page) => $page === 1 || $page === $last || abs($page - $current) <= 1)->values();
@endphp
<footer class="project-report__table-foot">
    <p role="status">แสดง {{ number_format($pageRows->firstItem()) }} - {{ number_format($pageRows->lastItem()) }} จาก {{ number_format($pageRows->total()) }} รายการ</p>
    <nav class="project-report__pager" aria-label="เปลี่ยนหน้ารายการงาน">
        @if($pageRows->onFirstPage())
            <span class="project-report__page is-disabled" aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
        @else
            <a class="project-report__page" href="{{ $pageRows->previousPageUrl() }}" aria-label="หน้าก่อนหน้า"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
        @endif

        @foreach($pages as $page)
            @if(! $loop->first && $page - $pages[$loop->index - 1] > 1)
                <span class="project-report__page is-gap" aria-hidden="true">…</span>
            @endif
            @if($page === $current)
                <span class="project-report__page is-current" aria-current="page">{{ $page }}</span>
            @else
                <a class="project-report__page" href="{{ $pageRows->url($page) }}" aria-label="หน้า {{ $page }}">{{ $page }}</a>
            @endif
        @endforeach

        @if($pageRows->hasMorePages())
            <a class="project-report__page" href="{{ $pageRows->nextPageUrl() }}" aria-label="หน้าถัดไป"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        @else
            <span class="project-report__page is-disabled" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
        @endif
    </nav>
</footer>
