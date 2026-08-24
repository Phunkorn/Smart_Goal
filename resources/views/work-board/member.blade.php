@extends('layouts.app')

@section('title', 'งานของ '.$member->name)

@push('styles')
    @vite('resources/css/pages/work-board.css')
@endpush

@section('content')
<div class="work-board-page wb-dept-{{ $departmentTone }}">
    <nav class="wb-breadcrumb"><a href="{{ route('work-board.index') }}">บอร์ดทุกแผนก</a><i class="bi bi-chevron-right"></i><a href="{{ route('work-board.department', $department) }}">{{ $department->department_name }}</a><i class="bi bi-chevron-right"></i><strong>งานของ {{ $member->name }}</strong></nav>

    <a class="wb-back-link" href="{{ route('work-board.department', $department) }}"><i class="bi bi-arrow-left"></i> กลับไปแผนก {{ $department->department_name }}</a>

    <section class="wb-profile-card">
        <div class="wb-profile-card__person">
            @include('work-board.partials.avatar', ['user' => $member, 'size' => 'xl'])
            <div><h1>{{ $member->name }}</h1><span>{{ $department->department_name }}</span><small><i class="bi bi-envelope"></i> {{ $member->email ?: '@'.$member->username }}</small></div>
        </div>
        <div class="wb-profile-kpi"><i class="bi bi-folder2-open"></i><strong>{{ $totals['projects'] }}</strong><span>โปรเจกต์</span></div>
        <div class="wb-profile-kpi"><i class="bi bi-list-check"></i><strong>{{ $totals['tasks'] }}</strong><span>งานทั้งหมด</span></div>
        @include('work-board.partials.status-summary')
    </section>

    <form class="wb-toolbar" method="GET">
        <label class="wb-search"><i class="bi bi-search"></i><input name="search" value="{{ request('search') }}" placeholder="ค้นหาชื่องานหรือรายละเอียด"></label>
        <select name="status"><option value="">ทุกสถานะ</option>@foreach($statusMeta as $key => $meta)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $meta['label'] }}</option>@endforeach</select>
        <select name="project_id"><option value="">ทุกโปรเจกต์</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->name }}</option>@endforeach</select>
        <select name="due"><option value="">กำหนดส่ง: ทั้งหมด</option><option value="7days" @selected(request('due') === '7days')>ภายใน 7 วัน</option><option value="overdue" @selected(request('due') === 'overdue')>เลยกำหนด</option></select>
        <select name="sort"><option value="due_asc" @selected(request('sort', 'due_asc') === 'due_asc')>เรียง: กำหนดส่งใกล้สุด</option><option value="name_asc" @selected(request('sort') === 'name_asc')>เรียง: ชื่องาน</option><option value="status_asc" @selected(request('sort') === 'status_asc')>เรียง: สถานะ</option></select>
        <button type="submit" class="wb-filter-button">ใช้ตัวกรอง</button>
    </form>

    @forelse($projectGroups as $group)
        <section class="wb-project-card">
            <header><div><span class="wb-project-icon"><i class="bi bi-folder2-open"></i></span><h2>{{ $group['project']?->name ?? 'งานทั่วไป' }}</h2><span class="wb-count-chip">{{ $group['jobs']->count() }} งาน</span></div></header>
            <div class="wb-task-table__head"><span>งาน</span><span>รายละเอียด</span><span>สถานะ</span><span>ความสำคัญ</span><span>กำหนดส่ง</span><span>ผู้ร่วมงาน</span><span>ไฟล์แนบ</span><span>การดำเนินการ</span></div>
            @foreach($group['jobs'] as $job)
                @php($status = \App\Support\WorkBoardDesign::status($job))
                @php($priority = \App\Support\WorkBoardDesign::taskPriority((int) $job->job_priority))
                <article class="wb-task-row">
                    <div class="wb-task-name"><i class="wb-mini-dot wb-tone-{{ $status['tone'] }}"></i><strong>{{ $job->job_topic }}</strong></div>
                    <p>{{ $job->job_details ?: 'ไม่มีรายละเอียดงาน' }}</p>
                    <span class="wb-status wb-tone-{{ $status['tone'] }}"><i></i>{{ $status['label'] }}</span>
                    <span class="wb-priority wb-tone-{{ $priority['tone'] }}"><i class="bi bi-flag-fill"></i>{{ $priority['label'] }}</span>
                    <time class="{{ $status['key'] === 'late' ? 'is-late' : '' }}">{{ $job->job_due_at?->locale('th')->translatedFormat('j M Y') ?? '-' }}</time>
                    <div class="wb-avatar-stack">
                        @forelse($job->collaborators->take(3) as $collaborator)@include('work-board.partials.avatar', ['user' => $collaborator, 'size' => 'sm'])@empty<span class="wb-muted">-</span>@endforelse
                        @if($job->collaborators->count() > 3)<span class="wb-avatar wb-avatar--sm">+{{ $job->collaborators->count() - 3 }}</span>@endif
                    </div>
                    <span class="wb-file-count"><i class="bi bi-paperclip"></i>{{ $job->images_count ?: '-' }}</span>
                    <div class="wb-task-action">@can('view', $job)<a href="{{ route('tasks.show', $job->job_id) }}" title="เปิดรายละเอียดงาน"><i class="bi bi-box-arrow-up-right"></i></a>@else<span>-</span>@endcan</div>
                </article>
            @endforeach
        </section>
    @empty
        <div class="wb-panel wb-empty"><i class="bi bi-inbox"></i><h3>ไม่พบงาน</h3><p>สมาชิกคนนี้ยังไม่มีงาน หรืองานไม่ตรงกับตัวกรองที่เลือก</p></div>
    @endforelse
</div>
@endsection
