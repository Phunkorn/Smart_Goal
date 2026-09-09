@php
    /*
     * สรุปงานประจำของช่วงที่เลือก
     *
     * ตอบคำถามของหัวหน้าโดยตรง: ทำครบไหม เริ่มช้ากี่รายการ เกินเวลากี่รายการ
     * และมีอะไรค้างที่ยังไม่มีใครตอบว่าเกิดอะไรขึ้น ตัวเลขทุกใบกดแล้วกรองตารางด้านล่างได้
     * โดยพา query string เดิมไปด้วย ตัวกรองช่วงเวลาและแผนกจึงไม่ถูกล้างทิ้ง
     */
    $routineTiles = [
        ['key' => null, 'label' => 'งานประจำทั้งหมด', 'value' => $routineSummary['total'], 'icon' => 'bi-arrow-repeat', 'tone' => 'neutral'],
        ['key' => 'on_time', 'label' => 'ทำเสร็จตรงเวลา', 'value' => $routineSummary['on_time'], 'icon' => 'bi-check2-circle', 'tone' => 'success'],
        ['key' => 'late_start', 'label' => 'เริ่มช้า', 'value' => $routineSummary['late_start'], 'icon' => 'bi-hourglass-split', 'tone' => 'warning'],
        ['key' => 'late_completion', 'label' => 'เสร็จเกินเวลา', 'value' => $routineSummary['late_completion'], 'icon' => 'bi-clock-history', 'tone' => 'warning'],
        ['key' => 'skipped', 'label' => 'ไม่ได้ทำ', 'value' => $routineSummary['skipped'], 'icon' => 'bi-slash-circle', 'tone' => 'muted'],
        ['key' => 'unclosed', 'label' => 'ยังไม่ปิดรายการ', 'value' => $routineSummary['unclosed'], 'icon' => 'bi-exclamation-triangle', 'tone' => 'danger'],
    ];

    $averageLabel = $routineSummary['average_minutes'] > 0
        ? ($routineSummary['average_minutes'] >= 60
            ? number_format($routineSummary['average_minutes'] / 60, 1).' ชม.'
            : $routineSummary['average_minutes'].' นาที')
        : '-';
@endphp

<article class="report-panel report-routine" aria-labelledby="routine-summary-title">
    <div class="report-panel__heading">
        <div>
            <h2 id="routine-summary-title">งานประจำ</h2>
            <p>{{ $filters['period_label'] }} · {{ $filters['routine_focus_label'] }}</p>
        </div>
        <div class="report-routine__rates">
            <span class="report-routine__rate"><b>{{ $routineSummary['completion_rate'] }}%</b> อัตราการทำครบ</span>
            <span class="report-routine__rate"><b>{{ $averageLabel }}</b> เวลาเฉลี่ยต่อรายการ</span>
        </div>
    </div>

    <div class="report-routine__tiles">
        @foreach($routineTiles as $tile)
            @php($isActive = ($filters['routine_focus'] ?? null) === $tile['key'])
            <a class="report-routine__tile report-routine__tile--{{ $tile['tone'] }} {{ $isActive ? 'is-active' : '' }}"
                href="{{ request()->fullUrlWithQuery(['routine_focus' => $tile['key'], 'page' => null]) }}"
                aria-current="{{ $isActive ? 'true' : 'false' }}">
                <i class="bi {{ $tile['icon'] }}" aria-hidden="true"></i>
                <strong>{{ number_format($tile['value']) }}</strong>
                <span>{{ $tile['label'] }}</span>
            </a>
        @endforeach
    </div>

    @if($routineSummary['total'] === 0)
        <p class="report-routine__empty">ยังไม่มีงานประจำในช่วงเวลาและตัวกรองที่เลือก</p>
    @endif
</article>
