<section class="daily-month" aria-labelledby="dailyMonthTitle">
    <div class="daily-month__head">
        <div>
            <h2 id="dailyMonthTitle">สรุปรายเดือน</h2>
            <p>จาก WorkLog ที่เกิดขึ้นจริง ไม่รวมแผนที่ยังไม่ถึงกำหนด</p>
        </div>
        <div class="daily-month__navigation">
            <a href="{{ route('daily-logs.index', array_filter(['view' => 'monthly', 'month' => $calendarMonth->copy()->subMonth()->format('Y-m'), 'user' => $isOwnDay ? null : $owner->id])) }}" aria-label="เดือนก่อนหน้า"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
            <strong>{{ $calendarMonth->locale('th')->translatedFormat('F') }} {{ $calendarMonth->year + 543 }}</strong>
            <a href="{{ route('daily-logs.index', array_filter(['view' => 'monthly', 'month' => $calendarMonth->copy()->addMonth()->format('Y-m'), 'user' => $isOwnDay ? null : $owner->id])) }}" aria-label="เดือนถัดไป"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </div>
    </div>
    <div class="daily-month__metrics">
        <div><small>รายการทั้งหมด</small><strong>{{ $monthSummary['total_count'] }}</strong></div>
        <div><small>เสร็จแล้ว</small><strong>{{ $monthSummary['done_count'] }}</strong></div>
        <div><small>กำลังทำ / รอเริ่ม</small><strong>{{ $monthSummary['open_count'] }}</strong></div>
        <div><small>ไม่ได้ทำ</small><strong>{{ $monthSummary['skipped_count'] }}</strong></div>
        <div><small>เวลาที่บันทึก</small><strong>{{ $monthDurationLabel }}</strong></div>
    </div>
    <div class="daily-month__kinds">
        @foreach($monthSummary['by_kind'] as $kindKey => $kind)
            <div>
                <span>{{ $kind['label'] }}</span>
                <strong>{{ $kind['count'] }} รายการ</strong>
                <small>{{ $monthKindDurationLabels[$kindKey] }}</small>
            </div>
        @endforeach
    </div>
    @if($monthSummary['total_count'] === 0)
        <p class="daily-month__empty">ยังไม่มี WorkLog ในเดือนนี้</p>
    @endif
</section>
