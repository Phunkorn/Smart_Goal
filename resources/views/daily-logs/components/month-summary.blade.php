@php
    // เรขาคณิตของโดนัท: วงกลมรัศมี 15.9155 มีเส้นรอบวง 100 หน่วยพอดี
    // ความยาวแต่ละชิ้นจึงเท่ากับเปอร์เซ็นต์ของสถานะนั้นโดยตรง
    $monthTotal = $monthSummary['total_count'];
    $monthStatuses = $monthSummary['by_status'];
    $segmentGap = count($monthStatuses) > 1 ? 0.8 : 0;
    $segmentCursor = 0.0;
    $donutSegments = [];
    foreach ($monthStatuses as $statusKey => $status) {
        $share = $monthTotal > 0 ? $status['count'] / $monthTotal * 100 : 0;
        $length = max($share - $segmentGap, 0.2);
        $donutSegments[] = [
            'key' => $statusKey,
            'dasharray' => sprintf('%.3F %.3F', $length, 100 - $length),
            'dashoffset' => sprintf('%.3F', -$segmentCursor),
        ];
        $segmentCursor += $share;
    }
    $donutLabel = 'สัดส่วนสถานะงาน: '.collect($monthStatuses)
        ->map(fn (array $status): string => $status['label'].' '.$status['count'].' รายการ')
        ->implode(', ');
    $percentLabel = fn (float $value): string => rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    $completionRate = $monthTotal > 0 ? round($monthSummary['done_count'] / $monthTotal * 100, 1) : 0.0;
    $kindMax = max(1, ...array_map(fn (array $kind): int => $kind['count'], array_values($monthSummary['by_kind'])));
    $topCategories = array_slice($monthSummary['by_category'], 0, 6, true);
    $categoryMax = max(1, ...array_map(fn (array $category): int => $category['minutes'], $topCategories ?: [['minutes' => 0]]));
@endphp
<section class="daily-month" aria-label="สรุปรายเดือน">
    @if($monthTotal === 0)
        <div class="daily-month__empty">
            <i class="bi bi-pie-chart" aria-hidden="true"></i>
            <p>ยังไม่มี WorkLog ในเดือนนี้</p>
        </div>
    @else
        <div class="daily-month__overview">
            <figure class="daily-month__status-card">
                <figcaption class="daily-month__card-title">สถานะงานเดือนนี้</figcaption>
                <div class="daily-month__donut" role="img" aria-label="{{ $donutLabel }}">
                    <svg viewBox="0 0 42 42" aria-hidden="true" focusable="false">
                        <circle class="daily-month__donut-track" cx="21" cy="21" r="15.9155"></circle>
                        @foreach($donutSegments as $segment)
                            <circle class="daily-month__donut-segment"
                                    data-status="{{ $segment['key'] }}"
                                    cx="21" cy="21" r="15.9155"
                                    style="stroke-dasharray: {{ $segment['dasharray'] }}; stroke-dashoffset: {{ $segment['dashoffset'] }}; --segment-index: {{ $loop->index }};"></circle>
                        @endforeach
                    </svg>
                    <div class="daily-month__donut-center">
                        <strong>{{ $monthTotal }}</strong>
                        <small>รายการ</small>
                    </div>
                </div>
                <ul class="daily-month__legend">
                    @foreach($monthStatuses as $statusKey => $status)
                        <li data-status="{{ $statusKey }}" style="--legend-index: {{ $loop->index }};">
                            <span class="daily-month__swatch" aria-hidden="true"></span>
                            <span class="daily-month__legend-label">{{ $status['label'] }}</span>
                            <strong>{{ $status['count'] }}</strong>
                            <small>{{ $percentLabel($status['percent']) }}</small>
                        </li>
                    @endforeach
                </ul>
            </figure>

            <div class="daily-month__details">
                <div class="daily-month__metrics">
                    <div class="daily-month__metric daily-month__metric--rate">
                        <small>อัตราเสร็จ</small>
                        <strong>{{ $percentLabel($completionRate) }}</strong>
                        <span class="daily-month__bar" aria-hidden="true"><span style="--bar-value: {{ $completionRate / 100 }};"></span></span>
                        <em>เสร็จ {{ $monthSummary['done_count'] }} จาก {{ $monthTotal }} รายการ</em>
                    </div>
                    <div class="daily-month__metric">
                        <small><i class="bi bi-stopwatch" aria-hidden="true"></i> เวลาที่บันทึก</small>
                        <strong>{{ $monthDurationLabel }}</strong>
                    </div>
                    <div class="daily-month__metric">
                        <small><i class="bi bi-play-circle" aria-hidden="true"></i> กำลังทำ / รอเริ่ม</small>
                        <strong>{{ $monthSummary['open_count'] }}</strong>
                    </div>
                    <div class="daily-month__metric">
                        <small><i class="bi bi-calendar-x" aria-hidden="true"></i> ไม่ได้ทำ</small>
                        <strong>{{ $monthSummary['skipped_count'] }}</strong>
                    </div>
                </div>

                <div class="daily-month__block">
                    <h3 class="daily-month__card-title">แยกตามประเภทงาน</h3>
                    <ul class="daily-month__rows">
                        @foreach($monthSummary['by_kind'] as $kindKey => $kind)
                            <li>
                                <span class="daily-month__row-icon daily-month__row-icon--{{ $kind['tone'] }}" aria-hidden="true"><i class="bi {{ $kind['icon'] }}"></i></span>
                                <span class="daily-month__row-main">
                                    <span class="daily-month__row-line">
                                        <span>{{ $kind['label'] }}</span>
                                        <strong>{{ $kind['count'] }} รายการ</strong>
                                    </span>
                                    <span class="daily-month__bar daily-month__bar--{{ $kind['tone'] }}" aria-hidden="true"><span style="--bar-value: {{ $kind['count'] / $kindMax }};"></span></span>
                                </span>
                                <small>{{ $monthKindDurationLabels[$kindKey] }}</small>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        @if($topCategories !== [])
            <div class="daily-month__block daily-month__block--categories">
                <h3 class="daily-month__card-title">หมวดงานที่ใช้เวลามากที่สุด</h3>
                <ul class="daily-month__rows daily-month__rows--grid">
                    @foreach($topCategories as $categoryIndex => $category)
                        <li>
                            <span class="daily-month__row-main">
                                <span class="daily-month__row-line">
                                    <span>{{ $category['name'] }}</span>
                                    <strong>{{ $monthCategoryDurationLabels[$categoryIndex] }}</strong>
                                </span>
                                <span class="daily-month__bar" aria-hidden="true"><span style="--bar-value: {{ $category['minutes'] / $categoryMax }};"></span></span>
                            </span>
                            <small>{{ $category['count'] }} รายการ</small>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</section>
