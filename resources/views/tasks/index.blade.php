@extends('layouts.app')
@section('title', 'งานของฉัน')

<?php
    $allTasks = $activeTasks->merge($completedTasks)->unique('job_id')->values();
    $statusLabels = [1 => 'ยังไม่เริ่ม', 2 => 'กำลังทำ', 3 => 'รอตรวจสอบ', 4 => 'เสร็จแล้ว', 5 => 'พักงาน', 6 => 'ล่าช้า'];
    $priorityLabels = [3 => 'สำคัญด่วน', 4 => 'ด่วนไม่ค่อยสำคัญ', 2 => 'สำคัญไม่ด่วน', 5 => 'ไม่รีบ ไม่มีกำหนด', 1 => 'routine'];
    $doneCount = $allTasks->where('job_status', 4)->count();
    $lateCount = $allTasks->filter(fn ($task) => (int) $task->job_status !== 4 && $task->job_due_at?->isPast())->count();
    $overall = $allTasks->count() ? (int) round($doneCount / $allTasks->count() * 100) : 0;
    $workspaceContext = 'user';
    $showCreateActions = true;
    $showQuickAdd = true;
    $taskLinkMode = false;
?>

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap">
    @vite(['resources/css/pages/mytasks.css', 'resources/js/pages/mytasks/index.js'])
@endpush

@section('content')
<div class="notion-workspace my-tasks-page" data-workspace
    data-context="user"
    data-task-scope="{{ $taskScope }}"
    data-details-template="{{ route('tasks.details.update', ['id' => '__ID__']) }}"
    data-status-template="{{ route('tasks.updateStatus', ['id' => '__ID__']) }}"
    data-priority-template="{{ route('mytasks.updatePriority', ['job_id' => '__ID__']) }}"
    data-due-template="{{ route('mytasks.updateDueDate', ['job_id' => '__ID__']) }}"
    data-progress-template="{{ route('tasks.progress.store', ['id' => '__ID__']) }}"
    data-quick-url="{{ route('mytasks.store') }}"
    data-create-url="{{ route('mytasks.create') }}"
    data-current-user-name="{{ auth()->user()->name }}"
    data-current-user-avatar="{{ auth()->user()->profile_image ? route('media.show', ['path' => auth()->user()->profile_image]) : '' }}">
    <section class="notion-heading">
        <div class="notion-heading-copy"><span class="heading-mark"><i class="bi bi-check2-square"></i></span><div><span class="notion-kicker">WORK MANAGEMENT</span><h1>งานของฉัน</h1><p>จัดลำดับงาน ติดตามความคืบหน้า และทำงานร่วมกับทีมในพื้นที่เดียว</p></div></div>
    </section>

    <div class="mytasks-view-controls">
        <nav class="notion-viewbar" role="tablist" aria-label="รูปแบบการแสดงงาน">
            <button class="active" type="button" data-view="table" role="tab" aria-selected="true"><i class="bi bi-table"></i> ตาราง</button>
            <button type="button" data-view="board" role="tab" aria-selected="false"><i class="bi bi-layout-three-columns"></i> บอร์ด</button>
            <button type="button" data-view="calendar" role="tab" aria-selected="false" aria-controls="mytasks-calendar"><i class="bi bi-calendar3"></i> ปฏิทิน</button>
        </nav>

        @if(auth()->user()->role === 'user')
            <label class="mytasks-scope-control" data-task-scope-control>
                <i class="bi bi-funnel" aria-hidden="true"></i>
                <span class="visually-hidden">เลือกขอบเขตงาน</span>
                <select data-task-scope aria-label="เลือกขอบเขตงาน">
                    <option value="all" @selected($taskScope === 'all')>งานทั้งหมด</option>
                    <option value="responsible" @selected($taskScope === 'responsible')>งานที่ฉันรับผิดชอบ</option>
                    <option value="created" @selected($taskScope === 'created')>งานที่ฉันสร้าง</option>
                    <option value="assigned_by_me" @selected($taskScope === 'assigned_by_me')>งานที่ฉันมอบหมาย</option>
                    <option value="collaborating" @selected($taskScope === 'collaborating')>งานที่ฉันร่วมงาน</option>
                </select>
            </label>
        @endif
    </div>

    <section class="notion-database">
        <div class="notion-toolbar" data-board-toolbar hidden>
            <label class="notion-search"><i class="bi bi-search"></i><input type="search" data-search placeholder="ค้นหาชื่องาน โปรเจกต์ หรือผู้รับผิดชอบ..."></label>
            <label class="notion-group">จัดกลุ่มตาม <select data-group><option value="project">โปรเจกต์</option><option value="status">สถานะ</option><option value="assignee">ผู้รับผิดชอบ</option><option value="priority">ความสำคัญ</option></select></label>
            <label class="notion-filter"><i class="bi bi-funnel"></i><select data-filter><option value="">ทุกสถานะ</option><option value="1">ยังไม่เริ่ม</option><option value="2">กำลังทำ</option><option value="3">รอตรวจสอบ</option><option value="5">พักงาน</option><option value="late">ล่าช้า</option><option value="4">เสร็จแล้ว</option></select></label>
            <button type="button" data-sort><i class="bi bi-sort-down"></i> กำหนดส่ง</button>
        </div>

        <div class="notion-table-scroll">
            <div class="project-board" data-project-board>
                @include('tasks.partials.project-board-card', [
                    'allTasks' => $allTasks,
                    'taskLists' => $workspaceTaskLists,
                    'manageableTaskLists' => $manageableTaskLists,
                    'showQuickAdd' => $showQuickAdd,
                    'taskLinkMode' => $taskLinkMode,
                    'workspaceContext' => $workspaceContext,
                ])
                <div class="project-board-empty" data-board-empty hidden><i class="bi bi-kanban"></i><p>ไม่พบงานในบอร์ดตามตัวกรองที่เลือก</p></div>
            </div>

            <div class="mytasks-kanban-view" data-table-kanban>
                @include('tasks.partials.table-kanban', ['allTasks' => $todayTasks, 'taskLists' => $taskLists, 'manageableTaskLists' => $manageableTaskLists, 'showCreateActions' => $showCreateActions, 'showQuickAdd' => $showQuickAdd, 'taskLinkMode' => $taskLinkMode, 'workspaceContext' => $workspaceContext])
            </div>

            @include('tasks.partials.calendar')

            @include('tasks.partials.workspace-task-source', [
                'allTasks' => $calendarTasks,
                'taskLists' => $taskLists,
                'manageableTaskLists' => $manageableTaskLists,
                'statusLabels' => $statusLabels,
                'priorityLabels' => $priorityLabels,
                'showQuickAdd' => $showQuickAdd,
                'workspaceContext' => $workspaceContext,
            ])
        </div>

    </section>
</div>

@include('tasks.partials.workspace-interactions', [
    'allTasks' => $calendarTasks,
    'availableCollaborators' => $availableCollaborators,
    'showCreateActions' => $showCreateActions,
    'workspaceContext' => $workspaceContext,
])
<div class="notion-toast" data-toast></div>
@endsection
