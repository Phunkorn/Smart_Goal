@extends('layouts.app')
@section('title', 'งานของฉัน')

<?php
    $allTasks = $activeTasks->merge($completedTasks)->unique('job_id')->values();
    $statusLabels = [2 => 'กำลังทำ', 3 => 'รอตรวจสอบ', 4 => 'เสร็จแล้ว', 5 => 'พักงาน', 6 => 'ล่าช้า'];
    $priorityLabels = [3 => 'สำคัญด่วน', 4 => 'ด่วนไม่ค่อยสำคัญ', 2 => 'สำคัญไม่ด่วน', 5 => 'ไม่รีบ ไม่มีกำหนด', 1 => 'routine'];
    $doneCount = $allTasks->where('job_status', 4)->count();
    $lateCount = $allTasks->filter(fn ($task) => (int) $task->job_status !== 4 && $task->job_due_at?->isPast())->count();
    $overall = $allTasks->count() ? (int) round($doneCount / $allTasks->count() * 100) : 0;
    $workspaceContext = 'user';
    // Controller เป็นผู้ตัดสินมุมมองตั้งต้น ค่า default ที่นี่ไว้กันหน้าพังหากถูก render จากที่อื่น
    $workspaceView = $workspaceView ?? 'table';
    $showCreateActions = true;
    $showQuickAdd = true;
    $taskLinkMode = false;
?>

@push('styles')
    @vite(['resources/css/pages/mytasks.css', 'resources/js/pages/mytasks/index.js'])
@endpush

@section('content')
<div class="notion-workspace my-tasks-page sg-task-theme" data-workspace
    data-context="user"
    data-view-history="true"
    data-task-scope="{{ $taskScope }}"
    data-details-template="{{ route('tasks.details.update', ['id' => '__ID__']) }}"
    data-status-template="{{ route('tasks.updateStatus', ['id' => '__ID__']) }}"
    data-priority-template="{{ route('mytasks.updatePriority', ['job_id' => '__ID__']) }}"
    data-schedule-template="{{ route('tasks.schedule.update', ['id' => '__ID__']) }}"
    data-due-template="{{ route('mytasks.updateDueDate', ['job_id' => '__ID__']) }}"
    data-quick-url="{{ route('mytasks.store') }}"
    data-create-url="{{ route('mytasks.create') }}"
    data-current-user-name="{{ auth()->user()->name }}"
    data-current-user-avatar="{{ auth()->user()->profile_image ? route('media.profile', auth()->user()) : '' }}">
    <section class="notion-heading">
        <div class="notion-heading-copy"><span class="heading-mark"><i class="bi bi-check2-square"></i></span><div><span class="notion-kicker">WORK MANAGEMENT</span><h1>งานของฉัน</h1><p>จัดลำดับงาน ติดตามความคืบหน้า และทำงานร่วมกับทีมในพื้นที่เดียว</p></div></div>
    </section>

    <div class="mytasks-view-controls">
        @php
            $workspaceViews = [
                ['view' => 'table', 'icon' => 'bi-table', 'label' => 'ตาราง'],
                ['view' => 'board', 'icon' => 'bi-layout-three-columns', 'label' => 'บอร์ด'],
                ['view' => 'calendar', 'icon' => 'bi-calendar3', 'label' => 'ปฏิทิน', 'controls' => 'mytasks-calendar'],
            ];

            // เฉพาะพนักงานเท่านั้นที่เมนู "การประชุม" ถูกย้ายมาเป็น view ที่ 4 ที่นี่
            // panel ประชุมถูก render จาก server เท่านั้น ปุ่มจึงต้อง navigate ไม่ใช่สลับฝั่ง client
            if (auth()->user()->role === 'user') {
                $workspaceViews[] = [
                    'view' => 'meeting',
                    'icon' => 'bi-calendar-event-fill',
                    'label' => 'ประชุม',
                    'href' => route('mytasks.index', ['view' => 'meeting']),
                ];
            }
        @endphp

        @include('tasks.partials.viewbar', ['views' => $workspaceViews, 'activeView' => $workspaceView])

        {{--
            ตัวกรองใช้ได้ทุกมุมมองรวมทั้งปฏิทิน จึงไม่ซ่อนตามมุมมองอีกต่อไป
            ของเดิมซ่อนตัวเองตอนสลับไปปฏิทินแล้วปฏิทินก็ไม่กรองด้วย ผู้ใช้จึงไม่รู้ว่าตัวกรองยังอยู่ไหม
        --}}
        @include('tasks.partials.scope-menu')

        {{-- ปุ่มเดียวที่เปิด modal สร้างโปรเจกต์ ต้องอยู่นอก <nav role="tablist"> เพื่อไม่ให้ปน role="tab" --}}
        @if($showCreateActions)
            <button type="button" class="mytasks-kanban__button mytasks-kanban__button--project mytasks-view-controls__create" data-open-user-task-create>
                <i class="bi bi-plus-lg" aria-hidden="true"></i> สร้างงาน
            </button>
        @endif
    </div>

    {{-- server เป็นผู้ตัดสินมุมมองตั้งแต่ HTML แรก จึงไม่มีการกระพริบจากตารางไปปฏิทิน --}}
    <section class="notion-database" data-view="{{ $workspaceView }}">
        {{-- <div class="notion-toolbar" data-board-toolbar {{ $workspaceView !== 'board' ? 'hidden' : '' }}>
            <label class="notion-search"><i class="bi bi-search"></i><input type="search" data-search placeholder="ค้นหาชื่องาน โปรเจกต์ หรือผู้รับผิดชอบ..."></label>
            <label class="notion-group">จัดกลุ่มตาม <select data-group><option value="project">โปรเจกต์</option><option value="status">สถานะ</option><option value="assignee">ผู้รับผิดชอบ</option><option value="priority">ความสำคัญ</option></select></label>
            <label class="notion-filter"><i class="bi bi-funnel"></i><select data-filter><option value="">ทุกสถานะ</option><option value="2">กำลังทำ</option><option value="3">รอตรวจสอบ</option><option value="5">พักงาน</option><option value="late">ล่าช้า</option><option value="4">เสร็จแล้ว</option></select></label>
            <button type="button" data-sort><i class="bi bi-sort-down"></i> กำหนดส่ง</button>
        </div> --}}

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
                @include('tasks.partials.table-kanban', ['allTasks' => $workspaceTasks, 'taskLists' => $taskLists, 'manageableTaskLists' => $manageableTaskLists, 'showCreateActions' => $showCreateActions, 'showQuickAdd' => $showQuickAdd, 'taskLinkMode' => $taskLinkMode, 'workspaceContext' => $workspaceContext])
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

        @if($workspaceView === 'meeting')
            {{-- ใช้ partial ชุดเดียวกับหน้า /meetings ห้ามคัดลอก HTML หรือ JavaScript ซ้ำ --}}
            <div class="mytasks-meeting-view" data-view-panel="meeting" role="tabpanel" aria-label="มุมมองการประชุม">
                @include('meetings.components.meeting-list', array_merge($meetingData, [
                    'meetingFormAction' => route('mytasks.index'),
                    'meetingBaseQuery' => ['view' => 'meeting'],
                    'meetingEmbedded' => true,
                ]))
            </div>
        @endif

    </section>
</div>

@include('tasks.partials.workspace-interactions', [
    'allTasks' => $calendarTasks,
    'availableCollaborators' => $availableCollaborators,
    'showCreateActions' => $showCreateActions,
    'workspaceContext' => $workspaceContext,
    'workspaceRootLabel' => 'งานของฉัน',
    'workspaceRootUrl' => route('mytasks.index'),
])
@include('tasks.components.project-task-request-modal')
@include('tasks.components.user-task-create-modal', ['projectOptions' => $manageableTaskLists])
<div class="notion-toast" data-toast></div>
@endsection
