<section class="report-kpis" aria-label="ตัวชี้วัดสำคัญ">
    @foreach([
        ['label' => 'งานที่สร้าง', 'value' => number_format($totalJobs), 'icon' => 'bi-clipboard-data', 'tone' => 'blue'],
        ['label' => 'เสร็จในช่วงนี้', 'value' => number_format($completedJobs), 'icon' => 'bi-check2-circle', 'tone' => 'green'],
        ['label' => 'งานที่ยังไม่เสร็จ', 'value' => number_format($activeJobs), 'icon' => 'bi-hourglass-split', 'tone' => 'purple'],
        ['label' => 'งานล่าช้า', 'value' => number_format($overdueJobs), 'icon' => 'bi-exclamation-triangle', 'tone' => 'red'],
        ['label' => 'อัตราสำเร็จ', 'value' => $completionRate.'%', 'icon' => 'bi-graph-up-arrow', 'tone' => 'amber'],
    ] as $kpi)
        <article class="report-kpi report-tone-{{ $kpi['tone'] }}">
            <span class="report-kpi__icon"><i class="bi {{ $kpi['icon'] }}" aria-hidden="true"></i></span>
            <div><span>{{ $kpi['label'] }}</span><strong>{{ $kpi['value'] }}</strong></div>
        </article>
    @endforeach
</section>
