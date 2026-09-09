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
        <div class="employee-picker__header-actions">
            @if(auth()->user()->role === 'viewer')<span class="employee-picker__readonly"><i class="bi bi-eye" aria-hidden="true"></i> ดูข้อมูลเท่านั้น</span>@endif
        </div>
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
        {{--
            ปุ่ม "ดูรายงานของฉัน" อยู่ท้ายแถวเดียวกับชิปแผนก ไม่ใช่ลอยอยู่มุมขวาบนของหน้า
            ทั้งสองอย่างคือ "จะดูรายงานของใคร" เหมือนกัน จึงควรอ่านต่อเนื่องกันในแถวเดียว
        --}}
        <div class="employee-picker__department-row">
            <div class="department-picker" role="list">
                {{--
                    "ทุกแผนก" มีความหมายเฉพาะกับผู้ที่ดูได้หลายแผนกจริง
                    หัวหน้าแผนกถูกบังคับให้เห็นแผนกตัวเองแผนกเดียวอยู่แล้ว (ReportController::forcedDepartmentId())
                    ปุ่มนี้จึงกลายเป็นตัวเลือกลวงที่ให้ผลเท่ากับแผนกตัวเอง และทำให้เข้าใจผิดว่ามีสิทธิ์ดูทั้งองค์กร
                --}}
                @if($departments->count() > 1)
                    <a role="listitem" href="{{ route('reports.employees.index', array_filter(['search' => $search])) }}" class="department-picker__item {{ $departmentId ? '' : 'is-active' }}"><span><i class="bi bi-grid" aria-hidden="true"></i>ทุกแผนก</span><strong>{{ $departments->sum('active_users_count') }} คน</strong></a>
                @endif
                @foreach($departments as $department)
                    <a role="listitem" href="{{ route('reports.employees.index', array_filter(['department' => $department->id, 'search' => $search])) }}" class="department-picker__item {{ $departmentId === $department->id ? 'is-active' : '' }}"><span><i class="bi bi-building" aria-hidden="true"></i>{{ $department->department_name }}</span><strong>{{ $department->active_users_count }} คน</strong></a>
                @endforeach
            </div>

            @if($isDepartmentHead)
                {{-- ไอคอนและโทนสีชุดเดียวกับเมนู "รายงานแผนก" ใน Sidebar ที่พาผู้ใช้มาหน้านี้ --}}
                <a href="{{ route('reports.my') }}" class="employee-picker__self-report"><i class="bi bi-clipboard-data" aria-hidden="true"></i> ดูรายงานของฉัน</a>
            @endif
        </div>
    </section>

    <section class="employee-picker__results" aria-labelledby="employee-results-title">
        <div class="employee-picker__section-head"><div><h2 id="employee-results-title">{{ $isDepartmentHead ? 'พนักงานในทีม' : ($departmentId ? 'พนักงานในแผนกที่เลือก' : 'พนักงานทั้งหมด') }}</h2><p>พบ {{ $employees->count() }} คน{{ $isDepartmentHead ? ' (ไม่รวมตัวคุณ)' : '' }}</p></div></div>
        <div class="employee-picker__grid">
            @forelse($employees as $employee)
                {{--
                    การ์ดเดียวกับไดเรกทอรีสมาชิกของแผนก (.wb-member-card)
                    หน้านี้ตอบคำถามเดียวคือ "จะดูรายงานของใคร" จึงมีแค่รูป ชื่อ และแผนก
                    ไม่มีบล็อกสรุปภาระงานหรือเวลาอัปเดตล่าสุดแบบไดเรกทอรี เพราะที่นี่ยังไม่ได้ถามถึงมัน
                --}}
                <article class="wb-member-card">
                    <div class="wb-member-card__portrait">
                        {{-- partial เดียวกับทุกหน้า ตัวย่อชื่อจึงโผล่แทนอัตโนมัติเมื่อไฟล์รูปหายไปจาก storage --}}
                        @include('work-board.partials.avatar', ['user' => $employee, 'size' => 'xl'])
                    </div>
                    <div class="wb-member-card__identity">
                        <h3>{{ $employee->name }}</h3>
                        <p>{{ $employee->department?->department_name ?? 'ไม่ระบุแผนก' }}</p>
                    </div>
                    <a href="{{ route('reports.employee', $employee) }}" class="wb-member-card__action" aria-label="ดูรายงานของ {{ $employee->name }}">
                        <span>ดูรายงาน</span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </article>
            @empty
                <div class="report-empty employee-picker__empty"><i class="bi bi-person-x" aria-hidden="true"></i><strong>ไม่พบพนักงาน</strong><span>ลองเปลี่ยนคำค้นหาหรือเลือกแผนกอื่น</span></div>
            @endforelse
        </div>
    </section>
</div>
@endsection
