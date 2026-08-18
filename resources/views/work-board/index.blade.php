@extends('layouts.app')

@section('title', 'บอร์ดทุกแผนก')

@push('styles')
    @vite('resources/css/pages/work-board.css')
@endpush

@section('content')
<div class="work-board-page">
    <nav class="wb-breadcrumb" aria-label="breadcrumb"><span>บอร์ดงาน</span><i class="bi bi-chevron-right"></i><strong>บอร์ดทุกแผนก</strong></nav>

    <header class="wb-page-head">
        <div>
            <span class="wb-eyebrow">ORGANIZATION WORKSPACE</span>
            <h1>บอร์ดทุกแผนก</h1>
            <p>ภาพรวมโปรเจกต์และงานของทุกแผนกในองค์กร</p>
        </div>
    </header>

    {{-- <section class="wb-overview" aria-label="ภาพรวมองค์กร">
        <div class="wb-kpi"><i class="bi bi-diagram-3"></i><div><strong>{{ number_format($totals['departments']) }}</strong><span>แผนก</span></div></div>
        <div class="wb-kpi"><i class="bi bi-folder2-open"></i><div><strong>{{ number_format($totals['projects']) }}</strong><span>โปรเจกต์</span></div></div>
        <div class="wb-kpi"><i class="bi bi-clipboard-check"></i><div><strong>{{ number_format($totals['tasks']) }}</strong><span>งาน</span></div></div>
        @include('work-board.partials.status-summary')
    </section> --}}

    <section class="wb-panel">
        <div class="wb-panel__head">
            <div><h2>แผนกทั้งหมด</h2><p>{{ number_format($departments->count()) }} แผนกในระบบ</p></div>
        </div>

        @forelse($departments as $department)
            <article class="wb-department-row wb-dept-{{ $department->board_tone }}">
                <div class="wb-department-row__identity">
                    <span class="wb-department-mark">{{ $department->board_code }}</span>
                    <div><h3>{{ $department->department_name }}</h3><span>ข้อมูลล่าสุดจากระบบ Smart Goal</span></div>
                </div>
                <div class="wb-department-stat"><i class="bi bi-people"></i><strong>{{ number_format($department->member_count) }}</strong><span>สมาชิก</span></div>
                <div class="wb-department-stat"><i class="bi bi-folder2"></i><strong>{{ number_format($department->project_count) }}</strong><span>โปรเจกต์</span></div>
                <div class="wb-department-stat"><i class="bi bi-list-check"></i><strong>{{ number_format($department->task_count) }}</strong><span>งาน</span></div>
                <a class="wb-detail-link" href="{{ route('work-board.department', $department) }}">ดูรายละเอียด <i class="bi bi-arrow-right"></i></a>
            </article>
        @empty
            <div class="wb-empty"><i class="bi bi-building"></i><h3>ยังไม่มีข้อมูลแผนก</h3><p>เมื่อเพิ่มแผนกแล้ว รายการจะแสดงที่นี่โดยอัตโนมัติ</p></div>
        @endforelse
    </section>
</div>
@endsection
