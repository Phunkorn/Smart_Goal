@extends('layouts.app')

@section('title', 'รายละเอียดแผนก '.$department->department_name)

@push('styles')
    @vite('resources/css/pages/work-board.css')
@endpush

@section('content')
<div class="work-board-page wb-dept-{{ $departmentTone }}">
    <nav class="wb-breadcrumb"><a href="{{ route('work-board.index') }}">บอร์ดทุกแผนก</a><i class="bi bi-chevron-right"></i><strong>{{ $department->department_name }}</strong></nav>

    <header class="wb-page-head wb-page-head--department">
        <span class="wb-department-mark">{{ $departmentCode }}</span>
        <div><h1>{{ $department->department_name }}</h1><p>ภาพรวมงานและโปรเจกต์ที่สมาชิกในแผนกรับผิดชอบ</p></div>
    </header>

    <section class="wb-overview">
        <div class="wb-kpi"><i class="bi bi-people"></i><div><strong>{{ number_format($totals['members']) }}</strong><span>สมาชิก</span></div></div>
        <div class="wb-kpi"><i class="bi bi-folder2-open"></i><div><strong>{{ number_format($totals['projects']) }}</strong><span>โปรเจกต์</span></div></div>
        <div class="wb-kpi"><i class="bi bi-clipboard-check"></i><div><strong>{{ number_format($totals['tasks']) }}</strong><span>งาน</span></div></div>
        @include('work-board.partials.status-summary')
    </section>

    <form class="wb-toolbar" method="GET">
        <label class="wb-search"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="ค้นหาสมาชิก อีเมล หรือโปรเจกต์"></label>
        <select name="status" aria-label="กรองสถานะ">
            <option value="">ทุกสถานะ</option>
            @foreach($statusMeta as $key => $meta)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $meta['label'] }}</option>@endforeach
        </select>
        <select name="project_id" aria-label="กรองโปรเจกต์">
            <option value="">ทุกโปรเจกต์</option>
            @foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->name }}</option>@endforeach
        </select>
        <select name="sort" aria-label="เรียงลำดับ">
            <option value="name" @selected(request('sort', 'name') === 'name')>เรียง: ชื่อ A-Z</option>
            <option value="tasks_desc" @selected(request('sort') === 'tasks_desc')>เรียง: งานมากที่สุด</option>
            <option value="due_asc" @selected(request('sort') === 'due_asc')>เรียง: กำหนดส่งใกล้สุด</option>
        </select>
        <button type="submit" class="wb-filter-button">ใช้ตัวกรอง</button>
        @if(request()->query())<a class="wb-clear-link" href="{{ route('work-board.department', $department) }}">ล้าง</a>@endif
    </form>

    <section class="wb-panel wb-member-panel">
        <div class="wb-member-table__head"><span>ผู้รับผิดชอบ</span><span>โปรเจกต์ที่รับผิดชอบ</span><span>สถานะ</span><span>งาน</span><span>กำหนดส่งล่าสุด</span><span></span></div>
        @forelse($members as $person)
            <article class="wb-member-row">
                <div class="wb-member-identity">
                    @include('work-board.partials.avatar', ['user' => $person, 'size' => 'lg'])
                    <div><strong>{{ $person->name }}</strong><span>{{ $person->email ?: '@'.$person->username }}</span><small><i class="bi bi-folder"></i> {{ $person->board_projects->count() }} โปรเจกต์ · <i class="bi bi-list-check"></i> {{ $person->board_jobs->count() }} งาน</small></div>
                </div>
                <div class="wb-project-stack">
                    @forelse($person->board_projects->take(3) as $project)
                        <span><i class="wb-mini-dot wb-tone-{{ $project['status']['tone'] }}"></i>{{ $project['name'] }} <small>{{ $project['count'] }} งาน</small></span>
                    @empty<span class="wb-muted">ยังไม่มีโปรเจกต์ที่รับผิดชอบ</span>@endforelse
                    @if($person->board_projects->count() > 3)<small>+{{ $person->board_projects->count() - 3 }} โปรเจกต์</small>@endif
                </div>
                <div class="wb-status-stack">
                    @foreach($statusMeta as $key => $meta)
                        @if(($person->board_status_counts[$key] ?? 0) > 0)<span class="wb-status wb-tone-{{ $meta['tone'] }}"><i></i>{{ $meta['label'] }} {{ $person->board_status_counts[$key] }}</span>@endif
                    @endforeach
                </div>
                <strong class="wb-cell-number">{{ $person->board_jobs->count() }} <small>งาน</small></strong>
                <span class="wb-due">{{ $person->latest_due_at?->locale('th')->translatedFormat('j M Y') ?? '-' }}</span>
                <a class="wb-detail-link" href="{{ route('work-board.member', [$department, $person]) }}">ดูงานทั้งหมด <i class="bi bi-arrow-right"></i></a>
            </article>
        @empty
            <div class="wb-empty"><i class="bi bi-person-x"></i><h3>ไม่พบสมาชิก</h3><p>ลองเปลี่ยนคำค้นหาหรือตัวกรอง แล้วค้นหาอีกครั้ง</p></div>
        @endforelse
    </section>
</div>
@endsection
