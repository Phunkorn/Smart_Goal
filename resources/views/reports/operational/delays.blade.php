@extends('layouts.app')

@section('title', 'เหตุผลที่ล่าช้าหรือเกินกำหนด — รายงานปฏิบัติงาน')

@push('styles')
    @vite('resources/css/pages/report-operational.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/operational.js')
@endpush

@section('content')
{{--
    เหตุผลทั้งหมดของเดือน — สรุปตามข้อความ แล้วตามด้วยทุกเหตุการณ์ที่ย้อนกลับไปหางานและวันที่ได้
    ข้อมูลมาจาก late_start_reason / late_completion_reason / skip_reason และงานประจำที่เลยเวลา
--}}
<div class="report-page report-operational report-operational--detail" aria-labelledby="operational-report-title">
    @include('reports.components.operational.header', [
        'scope' => $scope,
        'routeName' => 'reports.operational.delays',
        'title' => 'เหตุผลที่ล่าช้าหรือเกินกำหนด',
        'detailTitle' => 'เหตุผลที่ล่าช้าหรือเกินกำหนด',
        'csvReport' => 'delays',
    ])

    <div class="operational-grid operational-grid--detail">
        <section class="report-panel operational-card" aria-labelledby="operational-reason-groups-title">
            <div class="operational-card__heading">
                <div>
                    <h2 id="operational-reason-groups-title"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> สรุปตามสาเหตุ</h2>
                    <p>{{ number_format($groups->count()) }} สาเหตุ จาก {{ number_format($events->count()) }} เหตุการณ์</p>
                </div>
            </div>
            @include('reports.components.operational.delay-reasons-table', ['groups' => $groups])
        </section>

        <section class="report-panel operational-card" aria-labelledby="operational-delay-events-title">
            <div class="operational-card__heading">
                <div>
                    <h2 id="operational-delay-events-title"><i class="bi bi-list-check" aria-hidden="true"></i> ทุกเหตุการณ์ใน{{ $scope->monthLabel() }}</h2>
                    <p>ทุกแถวบอกงานและวันที่ที่เกี่ยวข้อง ตรงกับไฟล์ CSV ของรายงานนี้</p>
                </div>
                <a href="{{ route('reports.operational', $scope->query()) }}" class="operational-card__link">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> กลับไปภาพรวม
                </a>
            </div>

            <div class="operational-table-scroll">
                <table class="operational-table operational-table--events" data-operational-delay-table>
                    <caption class="visually-hidden">เหตุการณ์ล่าช้าหรือเกินกำหนดทั้งหมดของ{{ $scope->monthLabel() }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">วันที่</th>
                            <th scope="col">รายการงาน</th>
                            <th scope="col">ประเภทงาน</th>
                            <th scope="col">หมวดงาน</th>
                            <th scope="col">ประเภทเหตุผล</th>
                            <th scope="col">เหตุผล</th>
                            <th scope="col">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($events as $event)
                            <tr data-operational-delay-row>
                                <td data-label="วันที่" class="is-date">{{ $event['date_label'] }}</td>
                                <th scope="row" data-label="รายการงาน">{{ $event['title'] }}</th>
                                <td data-label="ประเภทงาน">{{ $event['kind_label'] }}</td>
                                <td data-label="หมวดงาน">{{ $event['category'] }}</td>
                                <td data-label="ประเภทเหตุผล">{{ $event['type_label'] }}</td>
                                <td data-label="เหตุผล" class="is-note">{{ $event['reason'] }}</td>
                                <td data-label="สถานะ">
                                    @include('reports.components.operational.status-text', ['tone' => $event['status_tone'], 'label' => $event['status_label']])
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="operational-table__empty">ไม่มีงานที่ล่าช้าหรือเกินกำหนดในเดือนนี้</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
@endsection
