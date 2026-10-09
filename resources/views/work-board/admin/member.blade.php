@extends('layouts.app')

@section('title', 'Workspace งานของ '.$member->name)

@push('styles')
    @vite([
        'resources/css/pages/work-board-admin.css',
        'resources/css/pages/mytasks.css',
        'resources/js/pages/mytasks/index.js',
    ])
    {{--
        ปฏิทินประชุมและนัดหมายใช้ไฟล์ชุดเดียวกับหน้า "งานของฉัน" และโหลดเฉพาะเมื่อ
        เปิดมุมมองนั้นจริง เหตุผลเดียวกับ tasks/index.blade.php
        meetings.css มาด้วยเพราะปฏิทินใช้ปุ่มและเปลือก modal ชุดเดียวกับหน้าประชุม
    --}}
    @if (($workspaceView ?? null) === 'meeting')
        @vite([
            'resources/css/pages/meetings.css',
            'resources/css/pages/meeting-calendar.css',
            'resources/js/pages/meetings/index.js',
            'resources/js/pages/meeting-calendar/index.js',
        ])
    @endif
@endpush

@section('content')
@php
    $isReadOnlyWorkspace = $isReadOnlyWorkspace ?? false;
    /*
     * "เข้ามาทางไหน" คนละเรื่องกับ "เขียนได้ไหม"
     *
     * ตอนนี้หน้านี้อ่านอย่างเดียวทั้งสองทาง แต่ชุด route ยังต่างกัน เพราะ work-board.*
     * อยู่หลัง middleware role:user ซึ่ง admin เข้าไม่ได้ ถ้าเลือก route จาก
     * $isReadOnlyWorkspace เหมือนเดิม breadcrumb ของ admin จะชี้ไปหน้าที่ตัวเองเปิดไม่ได้
     */
    $viaDepartmentHead = $viaDepartmentHead ?? $isReadOnlyWorkspace;
    $allTasks = $activeTasks->merge($completedTasks)->unique('job_id')->values();
    $statusLabels = [2 => 'กำลังทำ', 3 => 'รอตรวจสอบ', 4 => 'เสร็จแล้ว', 5 => 'พักงาน', 6 => 'ล่าช้า'];
    $priorityLabels = [3 => 'สำคัญด่วน', 4 => 'ด่วนไม่ค่อยสำคัญ', 2 => 'สำคัญไม่ด่วน', 5 => 'ไม่รีบ ไม่มีกำหนด', 1 => 'routine'];
    $workspaceContext = $viaDepartmentHead ? 'department-head-member' : 'admin-member';
    // ป้าย "งานข้ามแผนก" ตัดสินจากมุมของสมาชิกเจ้าของ Workspace ไม่ใช่ของผู้ที่เปิดดู
    $workspaceSubject = $member;
    $showCreateActions = false;
    $showQuickAdd = ! $isReadOnlyWorkspace;
    $taskLinkMode = false;
    // Controller เป็นผู้ตัดสินมุมมอง ค่า default ที่นี่ไว้กันหน้าพังหากถูก render จากที่อื่น
    $workspaceView = $workspaceView ?? 'table';

    // แถบมุมมองใช้ pattern เดียวกับ "งานของฉัน" — panel ประชุมและนัดหมายถูก render
    // จาก server เท่านั้น ปุ่มจึงต้อง navigate ไม่ใช่สลับฝั่ง client
    //
    // ป้ายต้องตรงกับหน้า "งานของฉัน" ทุกคำ: "ปฏิทินงาน" คือปฏิทินของโปรเจกต์
    // และ "ประชุมและนัดหมาย" คือปฏิทินประชุมและนัดหมายของแผนก
    $memberWorkspaceRoute = $viaDepartmentHead ? 'work-board.member' : 'admin.work-board.member';
    $departmentWorkspaceRoute = $viaDepartmentHead ? 'work-board.department' : 'admin.work-board.department';
    $memberWorkspaceParameters = $viaDepartmentHead
        ? [$department, $member, 'workspace' => 1]
        : [$department, $member];
    $workspaceViews = [
        ['view' => 'table', 'icon' => 'bi-table', 'label' => 'ตาราง'],
        ['view' => 'board', 'icon' => 'bi-layout-three-columns', 'label' => 'บอร์ด'],
        ['view' => 'calendar', 'icon' => 'bi-calendar3', 'label' => 'ปฏิทินงาน', 'controls' => 'mytasks-calendar'],
        [
            'view' => 'meeting',
            'icon' => 'bi-calendar-event-fill',
            'label' => 'ประชุมและนัดหมาย',
            'href' => route($memberWorkspaceRoute, [...$memberWorkspaceParameters, 'view' => 'meeting']),
        ],
    ];
@endphp
<div class="work-board-page admin-work-board wb-dept-{{ $departmentTone }}">
    <nav class="wb-breadcrumb" aria-label="breadcrumb">
        <a href="{{ $isReadOnlyWorkspace ? route('work-board.index') : route('board.index') }}">{{ $isReadOnlyWorkspace ? 'ภาพรวมแผนก' : 'บอร์ดผู้ดูแลระบบ' }}</a><i class="bi bi-chevron-right"></i>
        <a href="{{ route($departmentWorkspaceRoute, $department) }}">{{ $department->department_name }}</a><i class="bi bi-chevron-right"></i>
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
            {{--
                ไม่มีปุ่มมอบหมายงานที่นี่

                Workspace ของสมาชิกเป็นอ่านอย่างเดียวทั้งสำหรับ admin และหัวหน้าแผนก
                การมอบหมายงานให้สมาชิกเป็นหน้าที่ของหัวหน้าแผนกผ่านบอร์ดของแผนก
                ส่วน admin ใช้โมดัลมอบหมายงานที่หน้าบอร์ดรวมสำหรับงานตั้งระบบ
            --}}
        </div>
    </section>

    <div class="notion-workspace my-tasks-page sg-task-theme admin-member-task-workspace" data-workspace
        data-context="admin-member"
        data-view-history="true"
        data-subject-user-id="{{ $member->id }}"
        data-task-scope="{{ $taskScope }}"
        data-details-template="{{ route('tasks.details.update', ['id' => '__ID__']) }}"
        data-status-template="{{ route('tasks.updateStatus', ['id' => '__ID__']) }}"
        data-priority-template="{{ route('mytasks.updatePriority', ['job_id' => '__ID__']) }}"
        data-schedule-template="{{ route('tasks.schedule.update', ['id' => '__ID__']) }}"
        data-due-template="{{ route('mytasks.updateDueDate', ['job_id' => '__ID__']) }}"
        data-current-user-name="{{ auth()->user()->name }}"
        data-current-user-avatar="{{ auth()->user()->profile_image ? route('media.profile', auth()->user()) : '' }}">
        <div class="mytasks-view-controls">
            @include('tasks.partials.viewbar', ['views' => $workspaceViews, 'activeView' => $workspaceView])

            {{--
                ตัวกรองขอบเขตงานตัวเดียวกับหน้า "งานของฉัน" — ห้ามคัดลอก markup ใหม่
                ถ้อยคำถูกเขียนในมุมของสมาชิกที่กำลังถูกดูโดย TaskScopeOptions::forSubject()
            --}}
            @include('tasks.partials.scope-menu')

            {{-- ตัวกรองสถานะตัวเดียวกับหน้า "งานของฉัน" รวมตัวเลือก "งานข้ามแผนก" --}}
            @include('tasks.partials.status-filter')
        </div>

        {{-- server ตัดสินมุมมองตั้งแต่ HTML แรก จึงไม่มีการกระพริบตอนเปิดหน้า --}}
        <section class="notion-database" data-view="{{ $workspaceView }}">
            <div class="notion-table-scroll">
                <div class="project-board" data-project-board>
                    @include('tasks.partials.project-board-card', [
                        'allTasks' => $allTasks,
                        'taskLists' => $workspaceTaskLists,
                        'manageableTaskLists' => $manageableTaskLists,
                        'projectCreatorMeta' => $projectCreatorMeta,
                        'showQuickAdd' => $showQuickAdd,
                        'taskLinkMode' => $taskLinkMode,
                        'workspaceContext' => $workspaceContext,
                    ])
                    <div class="project-board-empty" data-board-empty hidden><i class="bi bi-kanban"></i><p>ไม่พบงานตามตัวกรอง</p></div>
                </div>
                <div class="mytasks-kanban-view" data-table-kanban>
                    @include('tasks.partials.table-kanban', ['allTasks' => $workspaceTasks, 'taskLists' => $taskLists, 'manageableTaskLists' => $manageableTaskLists, 'projectCreatorMeta' => $projectCreatorMeta, 'showCreateActions' => $showCreateActions, 'showQuickAdd' => $showQuickAdd, 'taskLinkMode' => $taskLinkMode, 'workspaceContext' => $workspaceContext])
                </div>
                @include('tasks.partials.calendar')
                @include('tasks.partials.workspace-task-source', compact('allTasks', 'childTasks', 'taskLists', 'manageableTaskLists', 'statusLabels', 'priorityLabels', 'showQuickAdd', 'workspaceContext'))
            </div>

            @if($workspaceView === 'meeting')
                {{--
                    ปฏิทินประชุมและนัดหมายใบเดียวกับหน้า "งานของฉัน" — Blade, CSS และ JS
                    ชุดเดียวกันทั้งหมด ห้ามคัดลอก HTML หรือ JavaScript ซ้ำ

                    ขอบเขตคือแผนกของ Workspace นี้ ไม่ใช่รายการประชุมของสมาชิกคนนั้น และ
                    ไม่ใช่แผนกของผู้ที่ล็อกอิน — ถูกบังคับที่ server ด้วย
                    MeetingCalendarScope::forDepartment() ใน WorkBoardController::adminMember()

                    calendarCanCreate = false เพราะหน้านี้อ่านอย่างเดียวทั้ง Admin และ
                    หัวหน้าแผนก และการสร้างจะ redirect ไป meetings.show ซึ่งพาผู้ใช้
                    ออกจาก Member Workspace (สิทธิ์จริงยังอยู่ที่ MeetingPolicy ตามเดิม)
                --}}
                <div class="mytasks-meeting-view" data-view-panel="meeting" role="tabpanel"
                    aria-label="ปฏิทินประชุมและนัดหมาย แผนก{{ $department->department_name }}">
                    @include('meetings.components.meeting-appointment-calendar', array_merge($meetingCalendarData, [
                        'calendarCanCreate' => false,
                    ]))
                </div>
            @endif
        </section>
        <div class="notion-toast" data-toast></div>
    </div>
    @include('tasks.partials.workspace-interactions', array_merge(
        compact('availableCollaborators', 'showCreateActions', 'workspaceContext'),
        [
            // โมดัลของงานย่อยอ่านสิทธิ์และข้อมูลจาก JSON ก้อนเดียวกับงานแม่
            'allTasks' => $allTasks->merge($childTasks),
            'workspaceRootLabel' => $member->name,
            'workspaceRootUrl' => route($memberWorkspaceRoute, $memberWorkspaceParameters),
            'forceReadOnly' => $isReadOnlyWorkspace,
        ]
    ))

</div>

@endsection
