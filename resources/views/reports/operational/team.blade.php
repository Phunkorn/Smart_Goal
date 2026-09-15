@extends('layouts.app')

@section('title', 'ภาพรวมทีม — รายงานปฏิบัติงาน')

@push('styles')
    @vite('resources/css/pages/report-operational.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/operational.js')
@endpush

@section('content')
{{--
    ภาพรวมทีม — หน้าแรกของหัวหน้าแผนกและ admin

    ตอบสองคำถามก่อนเลือกดูรายบุคคล: "ทั้งเดือนเป็นอย่างไร" และ "วันนี้แต่ละคนทำงานประจำหรือยัง"
    สมาชิกมาจาก scope ที่ตรวจสิทธิ์แล้ว — หัวหน้าเห็นเฉพาะแผนกตัวเอง admin เห็นทุกแผนก

    สถานะของวันนี้และกล่อง "ดูงาน" มีเฉพาะเดือนปัจจุบัน เดือนย้อนหลังปุ่มดูงานพาไปหน้าสรุปรายวัน
    ของคนนั้น เพราะงานทั้งเดือนของทุกคนยาวเกินกว่าจะฝังมาในหน้าเดียว
--}}
<div class="report-page report-operational report-operational--team" aria-labelledby="operational-report-title">
    @include('reports.components.operational.header', [
        'scope' => $scope,
        'routeName' => 'reports.operational',
        'title' => 'รายงานปฏิบัติงานประจำเดือน',
        'subtitle' => 'ภาพรวมทีม · '.$teamLabel,
        'teamOption' => true,
    ])

    <script type="application/json" id="report-chart-data">@json($chartData)</script>

    @include('reports.components.operational.kpis', ['kpis' => $kpis])

    @include('reports.components.operational.charts', [
        'scope' => $scope,
        'workTypes' => $workTypes,
        'totalHoursText' => $totalHoursText,
        'dailyDescription' => 'ชั่วโมงงานรวมของทีมทุกวันใน'.$scope->monthLabel(),
    ])

    <section class="report-panel operational-card" aria-labelledby="operational-team-title">
        <div class="operational-card__heading">
            <div>
                <h2 id="operational-team-title">
                    <i class="bi bi-people" aria-hidden="true"></i>
                    {{ $isCurrentMonth ? 'วันนี้ใครทำงานประจำแล้วบ้าง' : 'ภาพรวมรายคนของ'.$scope->monthLabel() }}
                </h2>
                <p>
                    @if($isCurrentMonth)
                        สถานะงานประจำของวันนี้ พร้อมภาพรวมของ{{ $scope->monthLabel() }} — กดดูงานเพื่อดูรายการงานของวันนี้
                    @else
                        ชั่วโมงงานและการปิดรายการของแต่ละคนในเดือนนั้น — กดดูงานเพื่อดูรายการงานทั้งเดือน
                    @endif
                </p>
            </div>
        </div>

        @if($isCurrentMonth)
            <ul class="operational-team-summary" aria-label="สรุปสถานะงานประจำของวันนี้">
                @foreach($todaySummary as $status)
                    <li class="operational-team-summary__item" data-today-status="{{ $status['key'] }}">
                        @include('reports.components.operational.status-text', ['tone' => $status['tone'], 'label' => $status['label']])
                        <strong>{{ number_format($status['count']) }}</strong>
                        <span>คน</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="operational-table-scroll">
            <table class="operational-table operational-table--team" data-operational-team-table>
                <caption class="visually-hidden">ภาพรวมการปฏิบัติงานรายคน</caption>
                <thead>
                    <tr>
                        <th scope="col">พนักงาน</th>
                        @if($isCurrentMonth)
                            <th scope="col" class="is-number">งานประจำวันนี้</th>
                            <th scope="col">สถานะวันนี้</th>
                        @endif
                        <th scope="col" class="is-number">ชั่วโมงเดือนนี้</th>
                        <th scope="col" class="is-number">รายการเดือนนี้</th>
                        <th scope="col" class="is-number">อัตราปิดรายการ</th>
                        <th scope="col" class="is-number">งานค้าง/เกินเวลา</th>
                        <th scope="col"><span class="visually-hidden">การดำเนินการ</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($people as $person)
                        @php($personQuery = ['month' => $scope->monthKey(), 'owner' => $person['id']])
                        <tr data-operational-team-row>
                            <th scope="row" data-label="พนักงาน">
                                {{ $person['name'] }}
                                <small>{{ $person['department'] }}</small>
                            </th>
                            @if($isCurrentMonth)
                                <td data-label="งานประจำวันนี้" class="is-number">{{ $person['today']['closed'] }}/{{ $person['today']['total'] }}</td>
                                <td data-label="สถานะวันนี้">
                                    @include('reports.components.operational.status-text', ['tone' => $person['today']['tone'], 'label' => $person['today']['label']])
                                </td>
                            @endif
                            <td data-label="ชั่วโมงเดือนนี้" class="is-number">{{ $person['hours_text'] }}</td>
                            <td data-label="รายการเดือนนี้" class="is-number">{{ number_format($person['count']) }}</td>
                            <td data-label="อัตราปิดรายการ" class="is-number">{{ $person['close_rate'] }}</td>
                            <td data-label="งานค้าง/เกินเวลา" class="is-number">
                                @if($person['pending'] > 0)
                                    <span class="operational-status is-red">{{ number_format($person['pending']) }}</span>
                                @else
                                    0
                                @endif
                            </td>
                            <td data-label="การดำเนินการ" class="is-action">
                                <div class="operational-row-actions">
                                    @if($isCurrentMonth)
                                        <button type="button" class="operational-button operational-button--small"
                                            data-team-work-open="{{ $person['id'] }}"
                                            data-team-work-name="{{ $person['name'] }}"
                                            data-team-work-department="{{ $person['department'] }}"
                                            data-team-work-count="{{ $person['today_items']->count() }}">
                                            <i class="bi bi-list-task" aria-hidden="true"></i> ดูงาน
                                        </button>
                                        {{-- เนื้อหาของกล่องถูก render มาพร้อมหน้า ไม่ต้องขอข้อมูลเพิ่มตอนกด --}}
                                        <template data-team-work-detail="{{ $person['id'] }}">
                                            @forelse($person['today_items'] as $item)
                                                <li class="operational-work-list__item">
                                                    <div class="operational-work-list__main">
                                                        <strong>{{ $item['title'] }}</strong>
                                                        <span>{{ $item['kind_label'] }} · {{ $item['category'] }}</span>
                                                    </div>
                                                    <div class="operational-work-list__meta">
                                                        @include('reports.components.operational.status-text', ['tone' => $item['status_tone'], 'label' => $item['status_label']])
                                                        <span>{{ $item['hours'] === '—' ? '—' : $item['hours'].' ชม.' }}</span>
                                                    </div>
                                                </li>
                                            @empty
                                                <li class="operational-work-list__empty">ยังไม่มีรายการงานของวันนี้</li>
                                            @endforelse
                                        </template>
                                    @else
                                        <a href="{{ route('reports.operational.daily', $personQuery) }}" class="operational-button operational-button--small">
                                            <i class="bi bi-list-task" aria-hidden="true"></i> ดูงาน
                                        </a>
                                    @endif
                                    <a href="{{ route('reports.operational', $personQuery) }}" class="operational-card__link">
                                        ดูรายบุคคล <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $isCurrentMonth ? 8 : 6 }}" class="operational-table__empty">ยังไม่มีพนักงานในขอบเขตนี้</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if($isCurrentMonth)
        {{--
            กล่องรายการงานของวันนี้ — modal กลางของระบบ (modal-stack) เป็นเจ้าของ backdrop
            การล็อกการเลื่อน โฟกัส และ Escape ไฟล์นี้กำหนดเฉพาะเนื้อหา
        --}}
        <div class="subtask-modal operational-work-modal" data-team-work-modal hidden role="dialog" aria-modal="true" aria-labelledby="operational-work-modal-title">
            <section class="subtask-modal__panel">
                <header class="subtask-modal__header">
                    <div>
                        <p class="subtask-modal__eyebrow" data-team-work-modal-department></p>
                        <h2 class="subtask-modal__title" id="operational-work-modal-title" data-team-work-modal-name></h2>
                    </div>
                    <button type="button" class="subtask-modal__close" data-team-work-close aria-label="ปิด">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="subtask-modal__body">
                    <p class="subtask-modal__count" data-team-work-modal-count></p>
                    <ul class="operational-work-list" data-team-work-modal-list></ul>
                </div>
            </section>
        </div>
    @endif
</div>
@endsection
