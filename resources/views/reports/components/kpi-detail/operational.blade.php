{{--
    เนื้อในกล่องรายละเอียดของการ์ดตัวเลข — รายงานปฏิบัติงาน (HTML ชิ้นเดียว ไม่มี layout)

    รายการมาจาก OperationalWorkloadReportService::kpiDetail() ชุดเดียวกับที่การ์ดนับ
    การ์ดอัตรา (ปิดรายการ ตรงเวลา) แสดงทุกรายการที่เป็นฐาน พร้อมป้ายว่าถูกนับหรือไม่
    ลิงก์ "ดูในบันทึกงาน" ไปไทม์ไลน์ของวันนั้น เฉพาะผู้ดูที่ผ่าน WorkLogPolicy::viewDay

    @param array $detail
    @param \Closure $pageUrl
--}}
@php
    $kpi = $detail['kpi'];
    $markLabels = [
        'close_rate' => ['ปิดแล้ว', 'ยังไม่ปิด'],
        'on_time' => ['ตรงเวลา', 'ไม่ตรงเวลา'],
    ];
@endphp
<div class="report-kpi-detail" data-kpi-detail data-kpi-key="{{ $detail['key'] }}" data-kpi-total="{{ $detail['total'] }}">
    <div class="report-kpi-detail__summary">
        <strong class="report-kpi-detail__value">{{ $kpi['value'] }}</strong>
        <p>
            {{ $kpi['description'] }}
            @if($detail['counted'] !== null)
                <span class="report-kpi-detail__ratio">{{ $markLabels[$detail['key']][0] }} {{ number_format($detail['counted']) }} จาก {{ number_format($detail['total']) }} รายการ</span>
            @endif
        </p>
    </div>

    @if($detail['total'] === 0)
        <div class="report-empty report-kpi-detail__empty">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            <strong>ไม่มีรายการในเดือนนี้</strong>
        </div>
    @else
        <ol class="report-kpi-detail__list" start="{{ $detail['from'] + 1 }}">
            @foreach($detail['items'] as $item)
                <li class="report-kpi-detail__item">
                    <div class="report-kpi-detail__main">
                        <span class="report-kpi-detail__title">{{ $item['title'] !== '' ? $item['title'] : 'ไม่ระบุชื่องาน' }}</span>
                        <span class="report-kpi-detail__meta">
                            <span>{{ $item['date_label'] }}</span>
                            @if($detail['showPerson'] && $item['person'])<span>{{ $item['person'] }}</span>@endif
                            <span>{{ $item['kind_label'] }}</span>
                            <span>{{ $item['category'] }}</span>
                            @if($item['hours'] !== '—')<span>{{ $item['hours'] }} ชม.</span>@endif
                        </span>
                        @if($item['reason'] !== '—')
                            <span class="report-kpi-detail__people">{{ $item['reason'] }}</span>
                        @endif
                        @if($item['url'])
                            <a class="report-kpi-detail__link" href="{{ $item['url'] }}">
                                ดูในบันทึกงาน <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        @endif
                    </div>
                    <div class="report-kpi-detail__side">
                        @include('reports.components.operational.status-text', ['tone' => $item['status_tone'], 'label' => $item['status_label']])
                        @if($item['counted'] !== null)
                            <span class="report-kpi-detail__mark {{ $item['counted'] ? 'is-counted' : '' }}">
                                <i class="bi {{ $item['counted'] ? 'bi-check2' : 'bi-dash' }}" aria-hidden="true"></i>{{ $markLabels[$detail['key']][$item['counted'] ? 0 : 1] }}
                            </span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>

        @include('reports.components.kpi-detail.pager')
    @endif
</div>
