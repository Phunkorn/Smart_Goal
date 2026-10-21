{{--
    เนื้อในกล่องรายละเอียดของการ์ดตัวเลข — รายงานโปรเจกต์ (HTML ชิ้นเดียว ไม่มี layout)

    รายการมาจาก ProjectReportService::kpiDetail() ชุดเดียวกับที่การ์ดนับ จำนวนรวมจึงเท่ากับเลขบนการ์ด
    ชื่องานเป็นลิงก์ไปหน้างานเดิม (tasks.show) เฉพาะงานที่ผู้ดูผ่าน WorkOrderPolicy::view
    ส่วนที่เหลือเป็นข้อความธรรมดา ไม่มีลิงก์ที่กดแล้วได้ 403

    @param array $detail
    @param \Closure $pageUrl
    @param string $periodPhrase
--}}
@php
    $kpi = $detail['kpi'];
    $date = fn ($value) => $value ? $value->locale('th')->translatedFormat('j M').' '.($value->year + 543) : null;
    $context = function (array $item) use ($detail, $date): string {
        return match ($detail['key']) {
            'completed' => 'ปิดงาน '.($date($item['completed_at']) ?? '—'),
            'active' => $item['due_at'] ? 'กำหนดส่ง '.$date($item['due_at']) : 'ไม่มีกำหนดส่ง',
            default => $item['date'] ? 'สร้าง '.$date($item['date']) : '',
        };
    };
@endphp
<div class="report-kpi-detail" data-kpi-detail data-kpi-key="{{ $detail['key'] }}" data-kpi-total="{{ $detail['total'] }}">
    <div class="report-kpi-detail__summary">
        <strong class="report-kpi-detail__value">{{ number_format($detail['total']) }} <small>{{ $kpi['unit'] }}</small></strong>
        <p>{{ $kpi['description'] }} · {{ $periodPhrase }}</p>
    </div>

    @if($detail['total'] === 0)
        <div class="report-empty report-kpi-detail__empty">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            <strong>ไม่มีรายการใน{{ $periodPhrase }}</strong>
        </div>
    @else
        <ol class="report-kpi-detail__list" start="{{ $detail['from'] + 1 }}">
            @foreach($detail['items'] as $item)
                @if($detail['type'] === 'projects')
                    <li class="report-kpi-detail__item">
                        <div class="report-kpi-detail__main">
                            <span class="report-kpi-detail__title">{{ $item['name'] }}</span>
                            <span class="report-kpi-detail__meta">
                                <span>{{ number_format($item['total']) }} งาน</span>
                                <span>เสร็จ {{ number_format($item['done']) }}</span>
                                @if($item['late'] > 0)<span class="is-late">ล่าช้า {{ number_format($item['late']) }}</span>@endif
                            </span>
                            @if($item['people'])
                                <span class="report-kpi-detail__people">ผู้รับผิดชอบ: {{ implode(', ', $item['people']) }}</span>
                            @endif
                        </div>
                    </li>
                @elseif($detail['type'] === 'subtasks')
                    <li class="report-kpi-detail__item">
                        <div class="report-kpi-detail__main">
                            @if($item['url'])
                                <a class="report-kpi-detail__title" href="{{ $item['url'] }}">{{ $item['topic'] }}</a>
                            @else
                                <span class="report-kpi-detail__title">{{ $item['topic'] }}</span>
                            @endif
                            <span class="report-kpi-detail__meta">
                                <span>งานแม่: {{ $item['parent']['topic'] }}</span>
                                <span>{{ $item['parent']['project'] }}</span>
                                <span>{{ $item['assignee']['name'] ?? 'ไม่ระบุผู้รับผิดชอบ' }}</span>
                            </span>
                        </div>
                        <div class="report-kpi-detail__side">
                            <span class="project-report__status project-report__status--{{ $item['status']['key'] }}">{{ $item['status']['label'] }}</span>
                            <small>{{ $item['due_at'] ? 'กำหนดส่ง '.$date($item['due_at']) : 'ไม่มีกำหนดส่ง' }}</small>
                        </div>
                    </li>
                @else
                    <li class="report-kpi-detail__item">
                        <div class="report-kpi-detail__main">
                            @if($item['url'])
                                <a class="report-kpi-detail__title" href="{{ $item['url'] }}">{{ $item['topic'] }}</a>
                            @else
                                <span class="report-kpi-detail__title">{{ $item['topic'] }}</span>
                            @endif
                            <span class="report-kpi-detail__meta">
                                <span>{{ $item['project']['name'] }}</span>
                                <span>{{ $item['assignee']['name'] ?? 'ไม่ระบุผู้รับผิดชอบ' }}</span>
                                <span>{{ $item['role']['detail'] ?? $item['role']['label'] }}</span>
                            </span>
                            @if($item['scope']['key'] === \App\Services\ProjectReportService::SCOPE_CROSS)
                                <span class="report-kpi-detail__chip">
                                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i>{{ $item['scope']['detail'] ?? $item['scope']['label'] }}
                                </span>
                            @endif
                        </div>
                        <div class="report-kpi-detail__side">
                            <span class="project-report__status project-report__status--{{ $item['status']['key'] }}">{{ $item['status']['label'] }}</span>
                            <small>{{ $context($item) }}</small>
                        </div>
                    </li>
                @endif
            @endforeach
        </ol>

        @include('reports.components.kpi-detail.pager')
    @endif
</div>
