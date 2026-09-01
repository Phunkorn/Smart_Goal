@extends('layouts.app')

@section('title', 'Workspace งานของ '.$member->name)

@push('styles')
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap">
    @vite([
        'resources/css/pages/work-board-admin.css',
        'resources/css/pages/mytasks.css',
        'resources/js/pages/mytasks/index.js',
        'resources/js/pages/board/admin-assignment.js',
    ])
@endpush

@section('content')
@php
    $allTasks = $activeTasks->merge($completedTasks)->unique('job_id')->values();
    $statusLabels = [2 => 'กำลังทำ', 3 => 'รอตรวจสอบ', 4 => 'เสร็จแล้ว', 5 => 'พักงาน', 6 => 'ล่าช้า'];
    $priorityLabels = [3 => 'สำคัญด่วน', 4 => 'ด่วนไม่ค่อยสำคัญ', 2 => 'สำคัญไม่ด่วน', 5 => 'ไม่รีบ ไม่มีกำหนด', 1 => 'routine'];
    $workspaceContext = 'admin-member';
    $showCreateActions = false;
    $showQuickAdd = true;
    $taskLinkMode = false;
    // Controller เป็นผู้ตัดสินมุมมอง ค่า default ที่นี่ไว้กันหน้าพังหากถูก render จากที่อื่น
    $workspaceView = $workspaceView ?? 'table';

    // แถบมุมมองใช้ pattern เดียวกับ "งานของฉัน" — "ประชุม" ถูก render จาก server เท่านั้น
    // ปุ่มจึงต้อง navigate ไม่ใช่สลับฝั่ง client
    $workspaceViews = [
        ['view' => 'table', 'icon' => 'bi-table', 'label' => 'ตาราง'],
        ['view' => 'board', 'icon' => 'bi-layout-three-columns', 'label' => 'บอร์ด'],
        ['view' => 'calendar', 'icon' => 'bi-calendar3', 'label' => 'ปฏิทิน', 'controls' => 'mytasks-calendar'],
        [
            'view' => 'meeting',
            'icon' => 'bi-calendar-event-fill',
            'label' => 'ประชุม',
            'href' => route('admin.work-board.member', [$department, $member, 'view' => 'meeting']),
        ],
    ];
@endphp
<div class="work-board-page admin-work-board wb-dept-{{ $departmentTone }}">
    <nav class="wb-breadcrumb" aria-label="breadcrumb">
        <a href="{{ route('board.index') }}">บอร์ดผู้ดูแลระบบ</a><i class="bi bi-chevron-right"></i>
        <a href="{{ route('admin.work-board.department', $department) }}">{{ $department->department_name }}</a><i class="bi bi-chevron-right"></i>
        <strong>{{ $member->name }}</strong>
    </nav>

    <section class="wb-profile-card admin-member-profile">
        <div class="wb-profile-card__person admin-member-profile__identity">
            @include('work-board.partials.avatar', ['user' => $member, 'size' => 'xl'])
            <div><span class="wb-eyebrow">ADMIN MEMBER WORKSPACE</span><h1>{{ $member->name }}</h1><span>{{ $department->department_name }}</span><small><i class="bi bi-envelope"></i>{{ $member->email ?: '@'.$member->username }}</small></div>
        </div>
        <div class="admin-member-profile__actions">
            <div class="admin-member-profile__metrics">
                <div class="wb-profile-kpi admin-member-profile__metric"><i class="bi bi-folder2-open"></i><strong>{{ $totals['projects'] }}</strong><span>โปรเจกต์</span></div>
                <div class="wb-profile-kpi admin-member-profile__metric"><i class="bi bi-list-check"></i><strong>{{ $totals['tasks'] }}</strong><span>งานทั้งหมด</span></div>
            </div>
            {{-- เปิด flow เดียวกับผู้ใช้ แต่ preselect สมาชิกและเริ่มจากขั้นเพิ่มรายการงาน --}}
            <button type="button" class="admin-assignment-launch admin-assign-button" data-open-admin-assignment>
                <span aria-hidden="true"><i class="bi bi-person-plus-fill"></i></span>
                <span><strong>มอบหมายงาน</strong><small>เลือกโปรเจกต์แล้วเพิ่มรายการ</small></span>
                <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </button>
        </div>
    </section>

    <div class="notion-workspace my-tasks-page sg-task-theme admin-member-task-workspace" data-workspace
        data-context="admin-member"
        data-view-history="true"
        data-subject-user-id="{{ $member->id }}"
        data-details-template="{{ route('tasks.details.update', ['id' => '__ID__']) }}"
        data-status-template="{{ route('tasks.updateStatus', ['id' => '__ID__']) }}"
        data-priority-template="{{ route('mytasks.updatePriority', ['job_id' => '__ID__']) }}"
        data-schedule-template="{{ route('tasks.schedule.update', ['id' => '__ID__']) }}"
        data-due-template="{{ route('mytasks.updateDueDate', ['job_id' => '__ID__']) }}"
        data-quick-template="{{ route('admin.work-board.member.tasks.store', [$department, $member, '__LIST__']) }}"
        data-current-user-name="{{ auth()->user()->name }}"
        data-current-user-avatar="{{ auth()->user()->profile_image ? route('media.profile', auth()->user()) : '' }}">
        @include('tasks.partials.viewbar', ['views' => $workspaceViews, 'activeView' => $workspaceView])

        {{-- server ตัดสินมุมมองตั้งแต่ HTML แรก จึงไม่มีการกระพริบตอนเปิดหน้า --}}
        <section class="notion-database" data-view="{{ $workspaceView }}">
            {{-- <div class="notion-toolbar" data-board-toolbar hidden>
                <label class="notion-search"><i class="bi bi-search"></i><input type="search" data-search placeholder="ค้นหาชื่องานหรือโปรเจกต์"></label>
                <label class="notion-group is-locked">สมาชิก <select disabled><option>{{ $member->name }}</option></select></label>
                <label class="notion-filter"><i class="bi bi-funnel"></i><select data-filter><option value="">ทุกสถานะ</option><option value="2">กำลังทำ</option><option value="3">รอตรวจสอบ</option><option value="5">พักงาน</option><option value="late">ล่าช้า</option><option value="4">เสร็จแล้ว</option></select></label>
                <button type="button" data-sort><i class="bi bi-sort-down"></i>กำหนดส่ง</button>
            </div> --}}
            <div class="notion-table-scroll">
                <div class="project-board" data-project-board>
                    @include('tasks.partials.project-board-card', compact('allTasks', 'manageableTaskLists', 'projectCreatorMeta', 'showQuickAdd', 'taskLinkMode', 'workspaceContext'))
                    <div class="project-board-empty" data-board-empty hidden><i class="bi bi-kanban"></i><p>ไม่พบงานตามตัวกรอง</p></div>
                </div>
                <div class="mytasks-kanban-view" data-table-kanban>
                    @include('tasks.partials.table-kanban', ['allTasks' => $allTasks, 'taskLists' => $taskLists, 'manageableTaskLists' => $manageableTaskLists, 'projectCreatorMeta' => $projectCreatorMeta, 'showCreateActions' => $showCreateActions, 'showQuickAdd' => $showQuickAdd, 'taskLinkMode' => $taskLinkMode, 'workspaceContext' => $workspaceContext])
                </div>
                @include('tasks.partials.calendar')
                @include('tasks.partials.workspace-task-source', compact('allTasks', 'taskLists', 'manageableTaskLists', 'statusLabels', 'priorityLabels', 'showQuickAdd', 'workspaceContext'))
            </div>

            @if($workspaceView === 'meeting')
                {{--
                    ใช้ partial ชุดเดียวกับหน้า /meetings และมุมมอง "ประชุม" ของงานของฉัน
                    ห้ามคัดลอก HTML หรือ JavaScript ซ้ำ

                    scope ของรายการถูกบังคับที่ MeetingQueryService ฝั่ง server ด้วย $member
                    ส่วน meetingCanCreate=false เพราะการสร้างประชุมจะ redirect ไป meetings.show
                    ซึ่งพา Admin ออกจาก Member Workspace
                --}}
                <div class="mytasks-meeting-view" data-view-panel="meeting" role="tabpanel" aria-label="การประชุมของ {{ $member->name }}">
                    @include('meetings.components.meeting-list', array_merge($meetingData, [
                        'meetingFormAction' => route('admin.work-board.member', [$department, $member]),
                        'meetingBaseQuery' => ['view' => 'meeting'],
                        'meetingEmbedded' => true,
                        'meetingCanCreate' => false,
                    ]))
                </div>
            @endif
        </section>
        <div class="notion-toast" data-toast></div>
    </div>
    @include('tasks.partials.workspace-interactions', array_merge(
        compact('allTasks', 'availableCollaborators', 'showCreateActions', 'workspaceContext'),
        [
            'workspaceRootLabel' => $member->name,
            'workspaceRootUrl' => route('admin.work-board.member', [$department, $member]),
        ]
    ))

</div>

{{-- โมดัลชุดเดียวกับ Admin Board Overview เพียงแต่ผูก origin ไว้กับสมาชิกคนนี้
     วางนอก .work-board-page เพื่อไม่ให้ stacking context ของหน้าไปทับ overlay ของ Bootstrap --}}
@include('board.components.admin-assignment-modal', [
    'assignmentOrigin' => ['department_id' => $department->id, 'member_id' => $member->id],
    'defaultAssigneeId' => $member->id,
    'projectOptions' => $manageableTaskLists,
    'startWithTask' => true,
])
@endsection

@push('scripts')
@include('board.components.admin-assignment-flash')
@endpush
