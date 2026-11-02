{{--
    รายการงานทั้งหมด — เปิดจากปุ่ม "ดูทั้งหมด" ในสรุปประจำวัน ซ้อนเป็นชั้นบน
    ปิดกล่องนี้แล้วกลับไปที่สรุปเดิม ไม่ออกจากหน้า (resources/js/components/daily-brief.js)

    $ranked = true ใช้แถวแบบจัดอันดับชุดเดียวกับการ์ดงานล่าช้า อันดับจึงต่อจาก 01 ไปตลอด
    รายการ ส่วนงานประจำใช้แถวปกติ ทั้งสองแบบกดแล้วนับเป็นการรับทราบก่อนพาไปที่งาน
--}}
<div class="daily-brief-list" data-daily-brief-list="{{ $key }}" hidden
    role="dialog" aria-modal="true" aria-labelledby="dailyBriefList{{ ucfirst($key) }}Title">
    <div class="daily-brief-list__panel">
        <header class="daily-brief-list__head">
            <span class="daily-brief__card-icon" aria-hidden="true"><i class="bi {{ $icon }}"></i></span>
            <h3 id="dailyBriefList{{ ucfirst($key) }}Title">{{ $title }}</h3>
            <span class="daily-brief__card-count">{{ count($rows) }} งาน</span>
            <button type="button" class="daily-brief-list__close" data-daily-brief-list-close aria-label="กลับไปที่สรุปประจำวัน">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="daily-brief-list__body">
            @if($ranked ?? false)
                <ol class="daily-brief__ranked">
                    @foreach($rows as $index => $row)
                        @include('daily-brief.partials.overdue-row', ['row' => $row, 'rank' => $index + 1])
                    @endforeach
                </ol>
            @else
                <div class="daily-brief__list">
                    @foreach($rows as $row)
                        @include('daily-brief.partials.task-row', ['row' => $row, 'meta' => $row['status_label']])
                    @endforeach
                </div>
            @endif
        </div>
        <footer class="daily-brief-list__footer">
            <button type="button" class="btn-outline-line" data-daily-brief-list-close>
                <i class="bi bi-arrow-left" aria-hidden="true"></i> กลับไปที่สรุป
            </button>
        </footer>
    </div>
</div>
