@extends('layouts.app')

@section('title', 'รายละเอียดงานโปรเจกต์ประจำเดือน')

@push('styles')
    @vite('resources/css/pages/report-projects.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/reports/projects.js')
@endpush

@section('content')
{{--
    ดูรายละเอียดทั้งหมด — หลักฐานผลงานของเดือน

    ตารางชุดเดียวกับหน้าหลักทุกคอลัมน์ในลำดับเดียวกัน (task-head / task-cells) ต่างกันแค่แบ่งหน้าครบทุกงาน
    หนึ่งแถวต่องาน สูงบรรทัดเดียวเท่ากันทุกแถว: งานย่อยเป็นป้ายจำนวน ไฟล์เป็นไอคอนนับตามชนิด
    กดป้ายหรือไอคอนแล้วเปิดกล่องรายละเอียดของงานใบนั้น (evidence-templates + evidence-modal)

    แถวเดียวกับตารางหน้าหลักและ CSV (คน เดือน ตัวกรอง และลำดับเดียวกัน)
    ลิงก์ไฟล์มาจาก ProjectReportService::withEvidence() ซึ่งตรวจ WorkOrderPolicy ทีละงานแล้ว
--}}
@php
    $columnCount = 14;
@endphp
<div class="project-report project-report--details" aria-labelledby="project-report-title">
    @include('reports.components.projects.header', [
        'routeName' => 'reports.projects.details',
        'title' => 'รายละเอียดงานโปรเจกต์ประจำเดือน',
        'detailTitle' => 'ดูรายละเอียดทั้งหมด',
    ])

    @include('reports.components.projects.filters', ['routeName' => 'reports.projects.details'])

    <section class="project-report__card project-report__table-card" aria-labelledby="project-report-detail-title">
        <div class="project-report__table-head">
            <div>
                <h2 id="project-report-detail-title">หลักฐานผลงาน{{ $periodPhrase }}</h2>
                <p>จำนวน {{ number_format($pageRows->total()) }} รายการ · กดไอคอนไฟล์เพื่อดูหลักฐานของงานนั้นและเปิดไฟล์</p>
            </div>
            <div class="project-report__table-actions">
                @include('reports.components.projects.sort', ['routeName' => 'reports.projects.details'])
                <a href="{{ route('reports.projects', $query) }}" class="project-report__button"><i class="bi bi-arrow-left" aria-hidden="true"></i> กลับรายงาน</a>
            </div>
        </div>

        <div class="project-report__table-scroll">
            <table class="project-report__table project-report__table--details">
                <thead>
                    <tr>
                        {{-- ชุดเดียวกับตารางหน้าหลักทุกคอลัมน์ --}}
                        @include('reports.components.projects.task-head')
                    </tr>
                </thead>
                <tbody>
                    @forelse($pageRows as $row)
                        <tr data-project-detail-row="{{ $row['id'] }}">
                            <td class="is-index">{{ $pageRows->firstItem() + $loop->index }}</td>
                            @include('reports.components.projects.task-cells', ['row' => $row])
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $columnCount }}">
                                <div class="report-empty">
                                    <i class="bi bi-inbox" aria-hidden="true"></i>
                                    <strong>{{ $hasActiveFilters ? 'ไม่พบงานที่ตรงกับตัวกรอง' : 'ยังไม่มีงานโปรเจกต์ใน'.$periodPhrase }}</strong>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($pageRows->total() > 0)
            @include('reports.components.projects.pager', ['pageRows' => $pageRows])
        @endif
    </section>

    @include('reports.components.projects.evidence-templates', ['rows' => $pageRows])

    {{-- ช่องงานย่อยใช้เซลล์ชุดเดียวกับหน้าหลัก จึงเปิดกล่องรายชื่องานย่อยตัวเดียวกัน --}}
    @include('reports.components.subtask-modal')
    @include('reports.components.projects.evidence-modal')
</div>
@endsection
