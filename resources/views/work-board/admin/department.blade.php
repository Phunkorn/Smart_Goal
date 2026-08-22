@extends('layouts.app')

@section('title', 'รายละเอียดแผนก '.$department->department_name)

@push('styles')
    @vite('resources/css/pages/work-board-admin.css')
@endpush

@section('content')
<div class="work-board-page admin-work-board wb-dept-{{ $departmentTone }}">
    <nav class="wb-breadcrumb" aria-label="breadcrumb">
        <a href="{{ route('board.index') }}">บอร์ดผู้ดูแลระบบ</a>
        <i class="bi bi-chevron-right"></i>
        <strong>{{ $department->department_name }}</strong>
    </nav>

    <header class="wb-page-head wb-page-head--department">
        <span class="wb-department-mark">{{ $departmentCode }}</span>
        <div>
            <span class="wb-eyebrow">ADMIN DEPARTMENT WORKSPACE</span>
            <h1>{{ $department->department_name }}</h1>
            <p>เลือกสมาชิกเพื่อดูและจัดการโปรเจกต์กับงานที่บุคคลนั้นรับผิดชอบ</p>
        </div>
    </header>

    <section class="wb-overview" aria-label="สรุปแผนก">
        <div class="wb-kpi"><i class="bi bi-people"></i><div><strong>{{ number_format($totals['members']) }}</strong><span>สมาชิก</span></div></div>
        <div class="wb-kpi"><i class="bi bi-folder2-open"></i><div><strong>{{ number_format($totals['projects']) }}</strong><span>โปรเจกต์</span></div></div>
        <div class="wb-kpi"><i class="bi bi-clipboard-check"></i><div><strong>{{ number_format($totals['tasks']) }}</strong><span>งาน</span></div></div>
        @include('work-board.partials.status-summary')
    </section>

    <form class="wb-toolbar" method="GET">
        <label class="wb-search"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="ค้นหาสมาชิก อีเมล หรืองาน"></label>
        <select name="status" aria-label="กรองสถานะ">
            <option value="">ทุกสถานะ</option>
            @foreach($statusMeta as $key => $meta)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $meta['label'] }}</option>@endforeach
        </select>
        <select name="project_id" aria-label="กรองโปรเจกต์">
            <option value="">ทุกโปรเจกต์</option>
            @foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->name }}</option>@endforeach
        </select>
        <select name="sort" aria-label="เรียงลำดับ">
            <option value="name" @selected(request('sort', 'name') === 'name')>ชื่อ A-Z</option>
            <option value="tasks_desc" @selected(request('sort') === 'tasks_desc')>งานมากที่สุด</option>
            <option value="due_asc" @selected(request('sort') === 'due_asc')>กำหนดส่งใกล้ที่สุด</option>
        </select>
        <button type="submit" class="wb-filter-button">ใช้ตัวกรอง</button>
    </form>

    <section class="wb-panel admin-member-selector" aria-labelledby="adminMemberSelectorTitle">
        <div class="wb-panel__head"><div><h2 id="adminMemberSelectorTitle">สมาชิกในแผนก</h2><p>เลือกสมาชิกเพื่อเปิด workspace งาน</p></div></div>
        <div class="admin-member-selector__grid">
            @forelse($members as $person)
                <a class="admin-member-selector__item" href="{{ route('admin.work-board.member', [$department, $person]) }}">
                    @include('work-board.partials.avatar', ['user' => $person, 'size' => 'lg'])
                    <strong>{{ $person->name }}</strong>
                    <span>{{ $person->email }}</span>
                    <small><i class="bi bi-folder2"></i>{{ $person->board_project_count }} โปรเจกต์ <i class="bi bi-list-check"></i>{{ $person->board_jobs->count() }} งาน</small>
                </a>
            @empty
                <div class="wb-empty"><i class="bi bi-person-x"></i><h3>ไม่พบสมาชิก</h3><p>ลองเปลี่ยนคำค้นหาหรือตัวกรอง</p></div>
            @endforelse
        </div>
    </section>
</div>
@endsection
