@extends('layouts.app')

@section('title', 'เลือกรายงานพนักงาน')

@push('styles')
    @vite('resources/css/pages/report-employees.css')
@endpush

@section('content')
<div class="employee-picker" aria-labelledby="employee-picker-title">
    <nav class="report-breadcrumb" aria-label="Breadcrumb"><a href="{{ route('reports.index') }}">รายงาน</a><i class="bi bi-chevron-right" aria-hidden="true"></i><span>รายงานรายบุคคล</span></nav>
    <header class="employee-picker__header">
        <div><span class="employee-picker__eyebrow">Individual report</span><h1 id="employee-picker-title">เลือกพนักงาน</h1><p>เลือกแผนกและพนักงานที่ต้องการดูรายงาน</p></div>
        @if(auth()->user()->role === 'viewer')<span class="employee-picker__readonly"><i class="bi bi-eye" aria-hidden="true"></i> ดูข้อมูลเท่านั้น</span>@endif
    </header>

    <form class="employee-picker__filters" method="GET" action="{{ route('reports.employees.index') }}" role="search">
        <label for="employeeSearch">ค้นหาพนักงาน</label>
        <div class="employee-picker__search"><i class="bi bi-search" aria-hidden="true"></i><input id="employeeSearch" name="search" value="{{ $search }}" placeholder="ค้นหาจากชื่อ..." autocomplete="off"></div>
        @if($departmentId)<input type="hidden" name="department" value="{{ $departmentId }}">@endif
        <button type="submit" class="btn btn-primary">ค้นหา</button>
        @if($search !== '' || $departmentId)<a href="{{ route('reports.employees.index') }}" class="btn btn-outline-secondary">ล้างตัวกรอง</a>@endif
    </form>

    <section class="employee-picker__departments" aria-labelledby="department-picker-title">
        <div class="employee-picker__section-head"><div><h2 id="department-picker-title">เลือกแผนก</h2><p>จำนวนพนักงานที่ใช้งานอยู่ในแต่ละแผนก</p></div></div>
        <div class="department-picker" role="list">
            <a role="listitem" href="{{ route('reports.employees.index', array_filter(['search' => $search])) }}" class="department-picker__item {{ $departmentId ? '' : 'is-active' }}"><span><i class="bi bi-grid" aria-hidden="true"></i>ทุกแผนก</span><strong>{{ $departments->sum('active_users_count') }} คน</strong></a>
            @foreach($departments as $department)
                <a role="listitem" href="{{ route('reports.employees.index', array_filter(['department' => $department->id, 'search' => $search])) }}" class="department-picker__item {{ $departmentId === $department->id ? 'is-active' : '' }}"><span><i class="bi bi-building" aria-hidden="true"></i>{{ $department->department_name }}</span><strong>{{ $department->active_users_count }} คน</strong></a>
            @endforeach
        </div>
    </section>

    <section class="employee-picker__results" aria-labelledby="employee-results-title">
        <div class="employee-picker__section-head"><div><h2 id="employee-results-title">{{ $departmentId ? 'พนักงานในแผนกที่เลือก' : 'พนักงานทั้งหมด' }}</h2><p>พบ {{ $employees->count() }} คน</p></div></div>
        <div class="employee-picker__grid">
            @forelse($employees as $employee)
                <article class="employee-card">
                    <div class="employee-card__avatar">
                        @if($employee->profile_image)<img src="{{ route('media.profile', $employee) }}" alt="รูปโปรไฟล์ของ {{ $employee->name }}">@else<span aria-hidden="true">{{ \App\Support\WorkBoardDesign::initials($employee->name) }}</span>@endif
                    </div>
                    <div class="employee-card__identity"><h3>{{ $employee->name }}</h3><p><i class="bi bi-building" aria-hidden="true"></i>{{ $employee->department?->department_name ?? 'ไม่ระบุแผนก' }}</p></div>
                    <a href="{{ route('reports.employee', $employee) }}" class="employee-card__action">ดูรายงาน <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </article>
            @empty
                <div class="report-empty employee-picker__empty"><i class="bi bi-person-x" aria-hidden="true"></i><strong>ไม่พบพนักงาน</strong><span>ลองเปลี่ยนคำค้นหาหรือเลือกแผนกอื่น</span></div>
            @endforelse
        </div>
    </section>
</div>
@endsection
