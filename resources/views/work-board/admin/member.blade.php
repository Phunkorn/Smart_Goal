@extends('layouts.app')

@section('title', 'Workspace งานของ '.$member->name)

@push('styles')
    @vite(['resources/css/pages/work-board-admin.css', 'resources/css/pages/mytasks.css', 'resources/js/pages/mytasks/index.js'])
@endpush

@section('content')
@php
    $allTasks = $activeTasks->merge($completedTasks)->unique('job_id')->values();
    $statusLabels = [1 => 'ยังไม่เริ่ม', 2 => 'กำลังทำ', 3 => 'รอตรวจสอบ', 4 => 'เสร็จแล้ว', 5 => 'พักงาน', 6 => 'ล่าช้า'];
    $priorityLabels = [3 => 'สำคัญด่วน', 4 => 'ด่วนไม่ค่อยสำคัญ', 2 => 'สำคัญไม่ด่วน', 5 => 'ไม่รีบ ไม่มีกำหนด', 1 => 'routine'];
    $workspaceContext = 'admin-member';
    $showCreateActions = false;
    $showQuickAdd = true;
    $taskLinkMode = false;
@endphp
<div class="work-board-page admin-work-board wb-dept-{{ $departmentTone }}">
    <nav class="wb-breadcrumb" aria-label="breadcrumb">
        <a href="{{ route('board.index') }}">บอร์ดผู้ดูแลระบบ</a><i class="bi bi-chevron-right"></i>
        <a href="{{ route('admin.work-board.department', $department) }}">{{ $department->department_name }}</a><i class="bi bi-chevron-right"></i>
        <strong>{{ $member->name }}</strong>
    </nav>

    <section class="wb-profile-card admin-member-profile">
        <div class="wb-profile-card__person">
            @include('work-board.partials.avatar', ['user' => $member, 'size' => 'xl'])
            <div><span class="wb-eyebrow">ADMIN MEMBER WORKSPACE</span><h1>{{ $member->name }}</h1><span>{{ $department->department_name }}</span><small><i class="bi bi-envelope"></i>{{ $member->email ?: '@'.$member->username }}</small></div>
        </div>
        <div class="wb-profile-kpi"><i class="bi bi-folder2-open"></i><strong>{{ $totals['projects'] }}</strong><span>โปรเจกต์</span></div>
        <div class="wb-profile-kpi"><i class="bi bi-list-check"></i><strong>{{ $totals['tasks'] }}</strong><span>งานทั้งหมด</span></div>
        <a class="btn btn-primary admin-assign-button" href="{{ route('board.index', ['open_assignment' => 1, 'assign_to' => $member->id]) }}">
            <i class="bi bi-person-plus-fill"></i>มอบหมายงาน
        </a>
    </section>

    <div class="notion-workspace my-tasks-page admin-member-task-workspace" data-workspace
        data-context="admin-member"
        data-subject-user-id="{{ $member->id }}"
        data-details-template="{{ route('tasks.details.update', ['id' => '__ID__']) }}"
        data-status-template="{{ route('tasks.updateStatus', ['id' => '__ID__']) }}"
        data-priority-template="{{ route('mytasks.updatePriority', ['job_id' => '__ID__']) }}"
        data-schedule-template="{{ route('tasks.schedule.update', ['id' => '__ID__']) }}"
        data-due-template="{{ route('mytasks.updateDueDate', ['job_id' => '__ID__']) }}"
        data-progress-template="{{ route('tasks.progress.store', ['id' => '__ID__']) }}"
        data-quick-template="{{ route('admin.work-board.member.tasks.store', [$department, $member, '__LIST__']) }}"
        data-current-user-name="{{ auth()->user()->name }}"
        data-current-user-avatar="{{ auth()->user()->profile_image ? route('media.profile', auth()->user()) : '' }}">
        {{-- Admin Member Workspace ไม่มี panel "ประชุม" จึงต้องได้แค่ 3 มุมมองเสมอ --}}
        @include('tasks.partials.viewbar', ['activeView' => 'table'])

        {{-- Admin Member Workspace ไม่มี view state ฝั่ง server จึงเริ่มที่ตารางเสมอ --}}
        <section class="notion-database" data-view="table">
            <div class="notion-toolbar" data-board-toolbar hidden>
                <label class="notion-search"><i class="bi bi-search"></i><input type="search" data-search placeholder="ค้นหาชื่องานหรือโปรเจกต์"></label>
                <label class="notion-group is-locked">สมาชิก <select disabled><option>{{ $member->name }}</option></select></label>
                <label class="notion-filter"><i class="bi bi-funnel"></i><select data-filter><option value="">ทุกสถานะ</option><option value="1">ยังไม่เริ่ม</option><option value="2">กำลังทำ</option><option value="3">รอตรวจสอบ</option><option value="5">พักงาน</option><option value="late">ล่าช้า</option><option value="4">เสร็จแล้ว</option></select></label>
                <button type="button" data-sort><i class="bi bi-sort-down"></i>กำหนดส่ง</button>
            </div>
            <div class="notion-table-scroll">
                <div class="project-board" data-project-board>
                    @include('tasks.partials.project-board-card', compact('allTasks', 'manageableTaskLists', 'projectCreatorMeta', 'showQuickAdd', 'taskLinkMode', 'workspaceContext'))
                    <div class="project-board-empty" data-board-empty hidden><i class="bi bi-kanban"></i><p>ไม่พบงานตามตัวกรอง</p></div>
                </div>
                <div class="mytasks-kanban-view" data-table-kanban>
                    @include('tasks.partials.table-kanban', ['allTasks' => $todayTasks, 'taskLists' => $taskLists, 'manageableTaskLists' => $manageableTaskLists, 'projectCreatorMeta' => $projectCreatorMeta, 'showCreateActions' => $showCreateActions, 'showQuickAdd' => $showQuickAdd, 'taskLinkMode' => $taskLinkMode, 'workspaceContext' => $workspaceContext])
                </div>
                @include('tasks.partials.calendar')
                @include('tasks.partials.workspace-task-source', compact('allTasks', 'taskLists', 'manageableTaskLists', 'statusLabels', 'priorityLabels', 'showQuickAdd', 'workspaceContext'))
            </div>
        </section>
        <div class="notion-toast" data-toast></div>
    </div>
    @include('tasks.partials.workspace-interactions', compact('allTasks', 'availableCollaborators', 'showCreateActions', 'workspaceContext'))
</div>
@endsection
