{{--
    KPI หกใบของรายงานประจำเดือน พร้อมเทียบกับเดือนก่อน

    ไอคอนใช้โทนน้ำเงิน/เทาเป็นหลัก ยกเว้นงานค้าง/เกินเวลาที่เป็นสีแดงเพราะเป็นความหมายของสถานะ
    ลูกศรเทียบเดือนก่อนเป็นเขียว/แดงตาม "ดีขึ้นหรือแย่ลง" ไม่ใช่ตาม "เพิ่มหรือลด"
--}}
@php
    $icons = [
        'hours' => 'bi-clock',
        'routine' => 'bi-journal-text',
        'field' => 'bi-geo-alt',
        'close_rate' => 'bi-check2-square',
        'on_time' => 'bi-stopwatch',
        'pending' => 'bi-exclamation-circle',
    ];
@endphp

<section class="operational-kpis" aria-label="สรุปตัวเลขประจำเดือน">
    @foreach($kpis as $key => $kpi)
        <article class="operational-kpi operational-kpi--{{ $key }}" data-operational-kpi="{{ $key }}">
            <span class="operational-kpi__icon" aria-hidden="true"><i class="bi {{ $icons[$key] ?? 'bi-bar-chart' }}"></i></span>
            <div class="operational-kpi__body">
                <span class="operational-kpi__label">{{ $kpi['label'] }}</span>
                <strong class="operational-kpi__value">{{ $kpi['value'] }}</strong>
                @if($kpi['trend'])
                    <span class="operational-kpi__trend is-{{ $kpi['trend']['tone'] }}">
                        @if($kpi['trend']['direction'] !== 'flat')
                            <i class="bi bi-arrow-{{ $kpi['trend']['direction'] }}-short" aria-hidden="true"></i>
                        @endif
                        {{ $kpi['trend']['text'] }}
                        <small>จากเดือนก่อน</small>
                    </span>
                @else
                    <span class="operational-kpi__trend is-empty">ไม่มีข้อมูลเดือนก่อนให้เทียบ</span>
                @endif
            </div>
        </article>
    @endforeach
</section>
