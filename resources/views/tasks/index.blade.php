@extends('layouts.app')

@section('title', 'งานของฉัน')

@php
    $allProjectTasks = $activeTasks->merge($completedTasks);
    $statusLabels = [
        2 => 'กำลังดำเนินงาน',
        4 => 'งานเสร็จสิ้น',
        5 => 'พักงาน',
        'overdue' => 'งานล่าช้า',
    ];
    $priorityLabels = [
        1 => 'ไม่สำคัญ/ทั่วไป',
        2 => 'สำคัญ/ไม่ด่วน',
        3 => 'ด่วน/สำคัญมาก',
    ];
    $ungroupedProjectTasks = $allProjectTasks->filter(fn ($task) => blank($task->work_order_list_id))->values();
    $isProjectCompleted = fn ($tasks) => $tasks->isNotEmpty()
        && $tasks->every(fn ($task) => (int) $task->job_status === 4);
    $isInboxCompleted = $isProjectCompleted($ungroupedProjectTasks);
    $showInboxGroup = $taskLists->isEmpty() || ($ungroupedProjectTasks->isNotEmpty() && ! $isInboxCompleted);
    $activeProjectCount = $taskLists->filter(function ($list) use ($allProjectTasks, $isProjectCompleted) {
        $tasks = $allProjectTasks->where('work_order_list_id', $list->id);
        return $tasks->isNotEmpty() && ! $isProjectCompleted($tasks);
    })->count() + ($ungroupedProjectTasks->isNotEmpty() && ! $isInboxCompleted ? 1 : 0);
    $completedProjectCount = $taskLists->filter(function ($list) use ($allProjectTasks, $isProjectCompleted) {
        return $isProjectCompleted($allProjectTasks->where('work_order_list_id', $list->id));
    })->count() + ($isInboxCompleted ? 1 : 0);
    $totalTasksCount = $allProjectTasks->count();
    $doneTasksCount = $completedTasks->count();
    $overallProgress = $totalTasksCount > 0 ? (int) round(($doneTasksCount / $totalTasksCount) * 100) : 0;
    $nextDueTask = $activeTasks->pluck('job_due_at')->filter()->sort()->first();
    $workspaceMembers = $allProjectTasks
        ->flatMap(fn ($task) => collect([$task->user])->merge($task->collaborators))
        ->filter()
        ->unique('id')
        ->values();
    $avatarColors = ['#0073EA', '#E2445C', '#00C875', '#FDAB3D', '#7C4DFF', '#00A9A5'];
@endphp

@push('styles')
    @vite('resources/css/pages/tasks.css')
@endpush

@section('content')
<div class="tasks-page"
    data-current-user-name="{{ auth()->user()->name }}"
    data-current-user-department="{{ auth()->user()->department_id }}"
    data-store-url="{{ route('mytasks.store') }}"
    data-list-store-url="{{ route('mytasks.lists.store') }}"
    data-status-url-template="{{ route('mytasks.updateStatus', ['job_id' => '__ID__']) }}"
    data-priority-url-template="{{ route('mytasks.updatePriority', ['job_id' => '__ID__']) }}"
    data-collaborator-url-template="{{ route('tasks.collaborators.store', ['id' => '__ID__']) }}"
    data-attachment-url-template="{{ route('tasks.attachments.store', ['id' => '__ID__']) }}"
    data-progress-url-template="{{ route('tasks.progress.store', ['id' => '__ID__']) }}"
    data-complete-url-template="{{ route('mytasks.complete', ['job_id' => '__ID__']) }}"
    data-delete-url-template="{{ route('mytasks.destroy', ['job_id' => '__ID__']) }}"
    data-due-url-template="{{ route('mytasks.updateDueDate', ['job_id' => '__ID__']) }}">
    <section class="pulse-hero">
        <div class="pulse-hero-top">
            <div class="pulse-title-block">
                <div class="pulse-project-icon">SG</div>
                <div class="tasks-title">
                    <h1>งานของฉัน</h1>
                    <p>{{ auth()->user()->department?->department_name ?: 'Smart Goal workspace' }}</p>
                </div>
            </div>

            <div class="pulse-actions">
                <section class="tasks-toolbar" aria-label="ตัวกรองงาน">
                    <label class="tool-field">
                        <i class="bi bi-search"></i>
                        <input id="taskSearch" type="search" placeholder="ค้นหางาน...">
                    </label>
                    <label class="tool-field">
                        <i class="bi bi-kanban"></i>
                        <select id="statusFilter">
                            <option value="">สถานะทั้งหมด</option>
                            @foreach ($statusLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="tool-field">
                        <i class="bi bi-flag"></i>
                        <select id="priorityFilter">
                            <option value="">ความสำคัญทั้งหมด</option>
                            @foreach ($priorityLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="button" class="tool-btn" data-sort-due title="เรียงตามกำหนดส่ง">
                        <i class="bi bi-sort-down"></i>
                    </button>
                    <button type="button" class="tool-btn" data-show-all-groups title="แสดงทุกกลุ่ม">
                        <i class="bi bi-eye"></i>
                    </button>
                </section>

                <button type="button" class="create-project-card" data-open-new-task-modal>
                    <i class="bi bi-plus-lg"></i>
                    <span>เพิ่มโปรเจกต์</span>
                </button>
            </div>
        </div>

        <div class="pulse-meta">
            <div class="pulse-meta-item">
                <span class="pulse-meta-label">ความคืบหน้า</span>
                <div class="pulse-progress">
                    <div class="pulse-progress-track"><span style="width: {{ $overallProgress }}%"></span></div>
                    <span class="pulse-meta-value">{{ $overallProgress }}%</span>
                </div>
            </div>
            <div class="pulse-meta-item">
                <span class="pulse-meta-label">งาน</span>
                <span class="pulse-meta-value">ทั้งหมด {{ $totalTasksCount }} งาน, เสร็จแล้ว {{ $doneTasksCount }} งาน</span>
            </div>
            <div class="pulse-meta-item">
                <span class="pulse-meta-label">กำหนดส่ง</span>
                <span class="pulse-meta-value">{{ $nextDueTask ? $nextDueTask->locale('th')->isoFormat('D MMM YYYY') : 'ยังไม่มีกำหนดส่ง' }}</span>
            </div>
            <div class="pulse-meta-item">
                <span class="pulse-meta-label">สมาชิก</span>
                <div class="pulse-member-stack">
                    @forelse ($workspaceMembers->take(5) as $index => $member)
                        <span class="pulse-avatar" style="background:{{ $avatarColors[$index % count($avatarColors)] }}" title="{{ $member->name }}">
                            {{ Str::of($member->name)->substr(0, 2)->upper() }}
                        </span>
                    @empty
                        <span class="pulse-avatar pulse-avatar-default">{{ Str::of(auth()->user()->name)->substr(0, 2)->upper() }}</span>
                    @endforelse
                    @if ($workspaceMembers->count() > 5)
                        <span class="pulse-avatar-more">+{{ $workspaceMembers->count() - 5 }}</span>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <nav class="board-tabs" aria-label="บอร์ดงาน">
        <button type="button" class="board-tab is-active" data-board-tab="active"><i class="bi bi-kanban"></i> งานที่กำลังทำ <span class="board-tab-count">{{ $activeProjectCount }}</span></button>
        <button type="button" class="board-tab" data-board-tab="completed"><i class="bi bi-check2-circle"></i> งานที่เสร็จแล้ว <span class="board-tab-count">{{ $completedProjectCount }}</span></button>
    </nav>

    <section class="task-board" data-task-board="active">
        @if ($showInboxGroup)
            @include('tasks.partials.task-table-group', [
                'listId' => 'inbox',
                'listName' => 'งานของฉัน',
                'isVisible' => true,
                'listTasks' => $ungroupedProjectTasks,
                'isVirtual' => true,
                'isCompletedBoard' => false,
            ])
        @endif

        @foreach ($taskLists as $list)
            @php
                $listTasks = $allProjectTasks->where('work_order_list_id', $list->id)->values();
            @endphp
            @if ($listTasks->isNotEmpty() && ! $isProjectCompleted($listTasks))
                @include('tasks.partials.task-table-group', [
                    'listId' => $list->id,
                    'listName' => $list->name,
                    'isVisible' => $list->is_visible,
                    'listTasks' => $listTasks,
                    'isVirtual' => false,
                    'isCompletedBoard' => false,
                ])
            @endif
        @endforeach

    </section>

    <section class="task-board" data-task-board="completed" hidden>
        @if ($completedProjectCount > 0)
            @if ($ungroupedProjectTasks->isNotEmpty() && $isInboxCompleted)
                @include('tasks.partials.task-table-group', [
                    'listId' => 'completed-inbox',
                    'listName' => 'งานของฉัน',
                    'isVisible' => true,
                    'listTasks' => $ungroupedProjectTasks,
                    'isVirtual' => true,
                    'isCompletedBoard' => true,
                ])
            @endif

            @foreach ($taskLists as $list)
                @php($completedProjectTasks = $allProjectTasks->where('work_order_list_id', $list->id)->values())
                @if ($isProjectCompleted($completedProjectTasks))
                    @include('tasks.partials.task-table-group', [
                        'listId' => 'completed-' . $list->id,
                        'listName' => $list->name,
                        'isVisible' => true,
                        'listTasks' => $completedProjectTasks,
                        'isVirtual' => false,
                        'isCompletedBoard' => true,
                    ])
                @endif
            @endforeach
        @else
            <div class="page-empty">ยังไม่มีโปรเจกต์ที่เสร็จแล้ว</div>
        @endif
    </section>
</div>

<div class="simple-modal" data-new-task-modal hidden>
    <form class="simple-modal-card full-task-card ntf-card" id="newTaskForm" action="{{ route('mytasks.create') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="simple-modal-head ntf-head">
            <div>
                <span class="ntf-eyebrow"><i class="bi bi-kanban"></i> โปรเจกต์ใหม่</span>
                <h2>เพิ่มโปรเจกต์</h2>
                <p>ตั้งชื่อโปรเจกต์ แล้วเพิ่มรายการงานและงานย่อยในโปรเจกต์นี้</p>
            </div>
            <button type="button" class="simple-modal-close" data-close-new-task-modal aria-label="ปิด">&times;</button>
        </div>
        <div class="simple-modal-body ntf-body">

            <div class="ntf-section">
                <div class="ntf-section-head">
                    <span class="ntf-section-num">1</span>
                    <div>
                        <div class="ntf-section-title">ชื่อโปรเจกต์</div>
                    </div>
                </div>
                <div class="ntf-section-body">
                    <label class="simple-field">
                        <input type="text" name="project_name" maxlength="80" required placeholder="เช่น โปรเจกต์ออกแบบ Dashboard">
                    </label>
                </div>
            </div>

            <div class="ntf-divider"></div>

            <div class="ntf-section">
                <div class="ntf-section-head">
                    <span class="ntf-section-num">2</span>
                    <div>
                        <div class="ntf-section-title">รายการงานในโปรเจกต์</div>
                        <p class="ntf-section-desc">แตกโปรเจกต์เป็นงานย่อยที่ทำได้จริง เพิ่มได้หลายรายการ</p>
                    </div>
                </div>
                <div class="ntf-section-body">
                    <div class="project-item-list" data-project-items>
                        <div class="project-item-card" data-project-item>
                            <div class="project-item-head">
                                <span class="project-item-title">รายการงานที่ 1</span>
                                <button type="button" class="tiny-icon-btn danger" data-remove-project-item hidden title="ลบรายการงาน">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </div>
                            <label class="simple-field">
                                ชื่องาน
                                <input type="text" name="project_items[0][job_topic]" maxlength="255" required placeholder="เช่น ออกแบบหน้า Dashboard">
                            </label>
                            <div class="simple-field">
                                งานย่อย <span class="field-optional">(เพิ่มได้หลายรายการ)</span>
                                <div class="initial-subtask-list" data-initial-subtasks>
                                    <div class="initial-subtask-row" data-initial-subtask-row>
                                        <input type="text" name="project_items[0][subtasks][0][title]" maxlength="255" placeholder="เช่น วางโครงหน้า Dashboard">
                                        <button type="button" class="tiny-icon-btn danger" data-remove-initial-subtask hidden title="ลบงานย่อย">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>
                                <button type="button" class="inline-add-btn" data-add-initial-subtask>
                                    <i class="bi bi-plus-lg"></i> เพิ่มงานย่อย
                                </button>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="inline-add-btn" data-add-project-item>
                        <i class="bi bi-plus-lg"></i> เพิ่มรายการงาน
                    </button>
                </div>
            </div>

            <div class="ntf-divider"></div>

            <div class="ntf-section">
                <div class="ntf-section-head">
                    <span class="ntf-section-num">3</span>
                    <div>
                        <div class="ntf-section-title">ผู้รับผิดชอบ &amp; ผู้ร่วมงาน</div>
                    </div>
                </div>
                <div class="ntf-section-body">
                    <label class="simple-field">
                        ผู้รับผิดชอบ
                        <select name="user_id" data-newtask-assignee>
                            <option value="{{ auth()->id() }}" data-department-id="{{ auth()->user()->department_id }}">ตัวฉันเอง ({{ auth()->user()->name }})</option>
                            @foreach ($availableCollaborators as $employee)
                                <option value="{{ $employee->id }}" data-department-id="{{ $employee->department_id }}">
                                    {{ $employee->name }} — {{ optional($employee->department)->department_name ?: 'ไม่ระบุแผนก' }}
                                </option>
                            @endforeach
                        </select>
                        <small class="field-hint" data-newtask-assignee-hint hidden></small>
                    </label>
                    <div class="simple-field">
                        ผู้ร่วมงาน <span class="field-optional">(ไม่บังคับ)</span>
                        <input type="search" class="collaborator-search" data-newtask-collaborator-search placeholder="ค้นหาชื่อพนักงานหรือแผนก">
                        <div class="collaborator-list" data-newtask-collaborator-list>
                            @forelse ($availableCollaborators as $employee)
                                <label class="collaborator-option"
                                    data-newtask-collaborator-option
                                    data-search="{{ Str::lower($employee->name . ' ' . optional($employee->department)->department_name) }}">
                                    <input type="checkbox" name="collaborators[]" value="{{ $employee->id }}">
                                    <span>
                                        <strong>{{ $employee->name }}</strong>
                                        <div class="collaborator-meta">{{ optional($employee->department)->department_name ?: 'ไม่ระบุแผนก' }}</div>
                                    </span>
                                </label>
                            @empty
                                <div class="empty-row-message">ยังไม่มีพนักงานที่เชิญได้</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            <div class="ntf-divider"></div>

            <div class="ntf-section">
                <div class="ntf-section-head">
                    <span class="ntf-section-num">4</span>
                    <div>
                        <div class="ntf-section-title">กำหนดการ &amp; ความสำคัญ</div>
                    </div>
                </div>
                <div class="ntf-section-body ntf-schedule-grid">
                    <label class="simple-field">
                        <span><i class="bi bi-calendar-event"></i> วันที่เริ่มงาน</span>
                        <input type="date" name="job_start_at" data-newtask-start required>
                    </label>
                    <label class="simple-field">
                        <span><i class="bi bi-calendar-check"></i> วันที่สิ้นสุดงาน</span>
                        <input type="date" name="job_due_at" data-newtask-due required>
                    </label>
                    <label class="simple-field">
                        <span><i class="bi bi-flag"></i> ความสำคัญ</span>
                        <select name="job_priority">
                            <option value="1">ไม่สำคัญ/ทั่วไป</option>
                            <option value="2" selected>สำคัญ/ไม่ด่วน</option>
                            <option value="3">ด่วน/สำคัญมาก</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="ntf-divider"></div>

            <div class="ntf-section">
                <div class="ntf-section-head">
                    <span class="ntf-section-num">5</span>
                    <div>
                        <div class="ntf-section-title">ไฟล์อ้างอิงงาน <span class="field-optional">(ไม่บังคับ)</span></div>
                    </div>
                </div>
                <div class="ntf-section-body">
                    <label class="attachment-drop">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <span>แนบไฟล์โจทย์งาน ไฟล์ตัวอย่าง หรือเอกสารประกอบ</span>
                        <small>รองรับ jpg, png, doc, xls, ppt — ไฟล์ละไม่เกิน 10MB สูงสุด 5 ไฟล์</small>
                        <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx" data-newtask-attachments>
                    </label>
                </div>
            </div>
        </div>
        <div class="ntf-footer">
            <button type="button" class="secondary-btn" data-close-new-task-modal>ยกเลิก</button>
            <button type="submit" class="primary-btn"><i class="bi bi-check2-circle"></i> เพิ่มโปรเจกต์</button>
        </div>
    </form>
</div>

<div class="simple-modal" data-collaborator-modal hidden>
    <form class="simple-modal-card" id="collaboratorForm" method="POST">
        @csrf
        <div class="simple-modal-head">
            <div>
                <h2>เพิ่มผู้ร่วมงาน</h2>
                <p data-collaborator-task-title>เลือกพนักงานเพื่อเชิญเข้าร่วมงานนี้</p>
            </div>
            <button type="button" class="simple-modal-close" data-close-collaborator-modal aria-label="ปิด">&times;</button>
        </div>
        <div class="simple-modal-body">
            <input type="search" class="collaborator-search" data-collaborator-search placeholder="ค้นหาชื่อพนักงานหรือแผนก">
            <div class="collaborator-list">
                @forelse ($availableCollaborators as $employee)
                    <label class="collaborator-option"
                        data-collaborator-option
                        data-user-id="{{ $employee->id }}"
                        data-search="{{ Str::lower($employee->name . ' ' . optional($employee->department)->department_name) }}">
                        <input type="checkbox" name="collaborators[]" value="{{ $employee->id }}">
                        <span>
                            <strong>{{ $employee->name }}</strong>
                            <div class="collaborator-meta">{{ optional($employee->department)->department_name ?: 'ไม่ระบุแผนก' }}</div>
                        </span>
                    </label>
                @empty
                    <div class="empty-row-message">ยังไม่มีพนักงานที่เชิญได้</div>
                @endforelse
            </div>
            <div class="simple-actions">
                <button type="button" class="secondary-btn" data-close-collaborator-modal>ยกเลิก</button>
                <button type="submit" class="primary-btn">ส่งคำเชิญ</button>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const page = document.querySelector('.tasks-page');
    const modal = document.querySelector('[data-new-task-modal]');
    const modalForm = document.getElementById('newTaskForm');
    const collaboratorModal = document.querySelector('[data-collaborator-modal]');
    const collaboratorForm = document.getElementById('collaboratorForm');
    const toast = Swal.mixin({toast:true, position:'top-end', showConfirmButton:false, timer:1500, timerProgressBar:true});
    const statusClass = {2:'status-working', 4:'status-done', 5:'status-paused', overdue:'status-overdue'};
    const statusText = {2:'ดำเนินการ', 4:'เสร็จสิ้น', 5:'พักงาน'};
    const priorityText = {1:'ไม่สำคัญ/ทั่วไป', 2:'สำคัญ/ไม่ด่วน', 3:'ด่วน/สำคัญมาก'};
    const priorityClass = {1:'priority-low', 2:'priority-medium', 3:'priority-high'};

    const requestJson = async (url, options = {}) => {
        const response = await fetch(url, {
            headers: {
                'Accept':'application/json',
                'X-CSRF-TOKEN':token,
                ...(options.body instanceof FormData ? {} : {'Content-Type':'application/json'}),
            },
            ...options,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'บันทึกไม่สำเร็จ');
        return data;
    };

    const showError = (error) => Swal.fire({icon:'error', title:'ทำรายการไม่สำเร็จ', text:error.message});
    const urlFor = (template, id) => template.replace('__ID__', id);
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));

    const confirmProjectCompletion = async (row) => {
        const total = Number(row?.dataset.subtaskTotal || 0);
        const done = Number(row?.dataset.subtaskDone || 0);
        if (total === 0 || done < total) {
            await Swal.fire({icon:'warning', title:'ยังปิดโปรเจกต์ไม่ได้', text:'กรุณาติ๊กงานย่อยให้ครบทุกข้อก่อน'});
            return false;
        }
        const result = await Swal.fire({
            icon:'question',
            title:'คุณแน่ใจหรือไม่ว่างานเสร็จครบแล้ว?',
            text:'เมื่อยืนยัน โปรเจกต์นี้จะย้ายไปยังบอร์ด “งานที่เสร็จแล้ว”',
            showCancelButton:true,
            confirmButtonText:'ยืนยันปิดโปรเจกต์',
            cancelButtonText:'ตรวจสอบอีกครั้ง',
            confirmButtonColor:'#16a34a',
        });
        return result.isConfirmed;
    };

    const applyFilters = () => {
        const q = document.getElementById('taskSearch')?.value.toLowerCase().trim() || '';
        const status = document.getElementById('statusFilter')?.value || '';
        const priority = document.getElementById('priorityFilter')?.value || '';
        document.querySelectorAll('[data-task-row]').forEach((row) => {
            const match = (!q || row.dataset.search.includes(q))
                && (!status || row.dataset.status === status || (status === '2' && ['1', '3'].includes(row.dataset.status)))
                && (!priority || row.dataset.priority === priority);
            row.hidden = !match;
            const panel = document.querySelector(`[data-subtask-panel="${row.dataset.taskId}"]`);
            if (!match && panel) panel.hidden = true;
        });
    };

    const rowHtml = (task) => {
        const id = task.job_id;
        const topic = escapeHtml(task.job_topic);
        const currentUser = escapeHtml(page.dataset.currentUserName || 'User');
        const initials = currentUser.slice(0, 2) || 'U';
        return `
            <tr class="task-row" data-task-row data-task-id="${id}" data-search="${topic.toLowerCase()}" data-status="2" data-priority="2" data-due="">
                <td class="check-col" data-label=""><button type="button" class="task-check" data-task-complete data-url="${urlFor(page.dataset.completeUrlTemplate, id)}" data-completed="1" aria-label="ทำเครื่องหมายว่าเสร็จ"><i class="bi bi-check-lg"></i></button></td>
                <td class="name-col" data-label="งาน">
                    <button type="button" class="expand-task" data-expand-task="${id}" aria-label="ดูงานย่อย"><i class="bi bi-chevron-right"></i></button>
                    <div class="task-name-wrap"><div class="task-title-line"><span class="task-title-text">${topic}</span></div></div>
                </td>
                <td data-label="ความสำคัญ"><select class="label-select priority-label ${priorityClass[2]}" data-priority-select data-url="${urlFor(page.dataset.priorityUrlTemplate, id)}">${Object.entries(priorityText).map(([value, label]) => `<option value="${value}" ${value === '2' ? 'selected' : ''}>${label}</option>`).join('')}</select></td>
                <td data-label="ความคืบหน้า"><button type="button" class="progress-cell" data-expand-task="${id}"><span class="progress-track"><span class="progress-zero"></span></span><strong>0%</strong></button></td>
                <td data-label="กำหนดส่ง"><input type="date" class="due-input" data-due-input data-url="${urlFor(page.dataset.dueUrlTemplate, id)}"></td>
                <td data-label="สถานะ"><select class="label-select status-label status-working" data-status-select data-url="${urlFor(page.dataset.statusUrlTemplate, id)}">${Object.entries(statusText).map(([value, label]) => `<option value="${value}" ${value === '2' ? 'selected' : ''}>${label}</option>`).join('')}</select></td>
                <td class="row-actions" data-label="">
                    <button type="button" class="icon-row-btn danger" data-delete-task data-task-title="${topic}" data-url="${urlFor(page.dataset.deleteUrlTemplate, id)}" aria-label="ลบงาน"><i class="bi bi-trash3"></i></button>
                </td>
            </tr>
            <tr class="subtask-row" data-subtask-panel="${id}" hidden>
                <td></td>
                <td colspan="6">
                    <div class="subtask-panel">
                        <div class="task-panel-tabs"><span><i class="bi bi-list-check"></i> งานย่อย</span><span><i class="bi bi-chat-left-text"></i> ความคิดเห็น</span><span><i class="bi bi-paperclip"></i> ไฟล์อ้างอิง</span><span><i class="bi bi-clock-history"></i> ประวัติ</span></div>
                        <table class="subitem-table">
                            <thead><tr><th class="check-col"></th><th>งานย่อย</th><th>รายละเอียด</th><th>วันที่</th></tr></thead>
                            <tbody><tr><td colspan="4"><div class="subtask-empty">ยังไม่มีงานย่อย</div></td></tr></tbody>
                        </table>
                        <div class="panel-section">
                            <h3>ความคิดเห็น</h3>
                            <form class="update-inline-form" action="${urlFor(page.dataset.progressUrlTemplate, id)}" method="POST">
                                <input type="number" name="progress" min="0" max="99" value="0">
                                <textarea name="note" maxlength="2000" required placeholder="เขียนอัปเดตงาน..."></textarea>
                                <button type="submit"><i class="bi bi-send"></i> บันทึกอัปเดต</button>
                            </form>
                            <div class="subtask-empty">ยังไม่มีอัปเดตงาน</div>
                        </div>
                        <div class="panel-section">
                            <h3>ไฟล์อ้างอิง</h3>
                            <div class="subtask-empty">ยังไม่มีไฟล์อ้างอิงงาน</div>
                            <form class="attachment-inline-form" action="${urlFor(page.dataset.attachmentUrlTemplate, id)}" method="POST" enctype="multipart/form-data" data-existing-files="0">
                                <label class="attachment-drop"><i class="bi bi-cloud-arrow-up"></i><span>เพิ่มไฟล์อ้างอิงงาน</span><small>ใช้สำหรับไฟล์ตัวอย่าง โจทย์งาน หรือเอกสารประกอบ สูงสุด 5 ไฟล์ ไฟล์ละไม่เกิน 10MB</small><input type="file" name="completion_attachments[]" multiple accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx"></label>
                                <button type="submit"><i class="bi bi-upload"></i> เพิ่มไฟล์</button>
                            </form>
                        </div>
                        <div class="panel-section"><h3>ประวัติ</h3><div class="subtask-empty">ยังไม่มีประวัติการทำงาน</div></div>
                    </div>
                </td>
            </tr>`;
    };

    const makeGroup = (listId, name) => {
        const board = document.querySelector('[data-task-board]');
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <article class="task-group" data-list-lane="${listId}">
                <div class="group-head">
                    <div class="group-title">
                        <button type="button" class="group-toggle" data-collapse-group aria-label="พับกลุ่ม"><i class="bi bi-chevron-down"></i></button>
                        <h2 class="group-name">${escapeHtml(name)}</h2>
                        <span class="group-count">0</span>
                    </div>
                    <div class="group-summary">ยังไม่มีกำหนดส่ง</div>
                </div>
                <div class="group-body">
                    <div class="task-table-wrap">
                        <table class="task-table">
                            <colgroup>
                                <col class="col-check">
                                <col class="col-name">
                                <col class="col-priority">
                                <col class="col-progress">
                                <col class="col-due">
                                <col class="col-status">
                                <col class="col-actions">
                            </colgroup>
                            <thead><tr><th class="check-col"></th><th class="name-col">รายการงานย่อย</th><th>ความสำคัญ</th><th>ความคืบหน้า</th><th>กำหนดส่ง</th><th>สถานะ</th><th class="row-actions"></th></tr></thead>
                            <tbody data-group-body="${listId}">
                                <tr class="empty-row"><td colspan="7"><div class="empty-row-message">ยังไม่มีงานในรายการนี้</div></td></tr>
                                <tr class="add-row"><td></td><td colspan="6"><form class="add-task-inline" action="${page.dataset.storeUrl}" method="POST"><input type="hidden" name="work_order_list_id" value="${listId}"><input type="text" name="job_topic" maxlength="255" required placeholder="+ เพิ่มงานย่อยในโปรเจกต์นี้"><button type="submit">เพิ่มงานย่อย</button></form></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="completed-group"><details><summary><i class="bi bi-check-circle"></i> งานที่เสร็จแล้ว <span>0</span></summary><div class="task-table-wrap"><table class="task-table"><tbody><tr class="empty-row"><td colspan="7"><div class="empty-row-message">ยังไม่มีงานที่เสร็จแล้ว</div></td></tr></tbody></table></div></details></div>
                </div>
            </article>`;
        const group = wrapper.firstElementChild;
        board.appendChild(group);
        return group;
    };

    const adoptInboxGroup = (listId) => {
        const inbox = document.querySelector('[data-list-lane="inbox"]');
        if (!inbox) return null;

        inbox.dataset.listLane = listId;
        inbox.querySelector('[data-group-body="inbox"]')?.setAttribute('data-group-body', listId);
        inbox.querySelector('input[name="work_order_list_id"]')?.setAttribute('value', listId);
        document.querySelectorAll('[data-scroll-list="inbox"]').forEach((button) => button.dataset.scrollList = listId);
        return inbox;
    };

    const ensureListForTask = async (formData) => {
        let listId = formData.get('work_order_list_id') || '';
        if (listId) return listId;

        const listData = new FormData();
        listData.append('name', 'งานของฉัน');
        const created = await requestJson(page.dataset.listStoreUrl, {method:'POST', body:listData});
        listId = String(created.list_id);
        formData.set('work_order_list_id', listId);

        adoptInboxGroup(listId) || makeGroup(listId, 'งานของฉัน');
        document.querySelectorAll('input[name="work_order_list_id"][value=""]').forEach((input) => input.value = listId);
        return listId;
    };

    const appendTask = (listId, task) => {
        let group = document.querySelector(`[data-list-lane="${listId}"]`);
        if (!group) group = makeGroup(listId, 'งานของฉัน');
        const tbody = group.querySelector(`[data-group-body="${listId}"]`);
        tbody?.querySelector('.empty-row')?.remove();
        const addRow = tbody?.querySelector('.add-row');
        const holder = document.createElement('tbody');
        holder.innerHTML = rowHtml(task);
        [...holder.children].forEach((row) => tbody.insertBefore(row, addRow));
        const count = group.querySelector('.group-count');
        if (count) count.textContent = String(Number(count.textContent || 0) + 1);
        applyFilters();
    };

    const subtaskTemplate = (itemIndex, subtaskIndex) => `
        <div class="initial-subtask-row" data-initial-subtask-row>
            <input type="text" name="project_items[${itemIndex}][subtasks][${subtaskIndex}][title]" maxlength="255" placeholder="เช่น วางโครงหน้า Dashboard">
            <button type="button" class="tiny-icon-btn danger" data-remove-initial-subtask title="ลบงานย่อย">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>`;

    const projectItemTemplate = (itemIndex) => `
        <div class="project-item-card" data-project-item>
            <div class="project-item-head">
                <span class="project-item-title">รายการงานที่ ${itemIndex + 1}</span>
                <button type="button" class="tiny-icon-btn danger" data-remove-project-item title="ลบรายการงาน">
                    <i class="bi bi-trash3"></i>
                </button>
            </div>
            <label class="simple-field">
                ชื่องาน
                <input type="text" name="project_items[${itemIndex}][job_topic]" maxlength="255" required placeholder="เช่น ออกแบบหน้า Dashboard">
            </label>
            <div class="simple-field">
                งานย่อย <span class="field-optional">(เพิ่มได้หลายรายการ)</span>
                <div class="initial-subtask-list" data-initial-subtasks>
                    ${subtaskTemplate(itemIndex, 0)}
                </div>
                <button type="button" class="inline-add-btn" data-add-initial-subtask>
                    <i class="bi bi-plus-lg"></i> เพิ่มงานย่อย
                </button>
            </div>
        </div>`;

    const reindexProjectItems = () => {
        modalForm?.querySelectorAll('[data-project-item]').forEach((item, itemIndex) => {
            const itemTitle = item.querySelector('.project-item-title');
            if (itemTitle) itemTitle.textContent = `รายการงานที่ ${itemIndex + 1}`;
            const removeItem = item.querySelector('[data-remove-project-item]');
            if (removeItem) removeItem.hidden = itemIndex === 0 && modalForm.querySelectorAll('[data-project-item]').length === 1;
            item.querySelector('input[name*="[job_topic]"]')?.setAttribute('name', `project_items[${itemIndex}][job_topic]`);
            item.querySelectorAll('[data-initial-subtask-row]').forEach((row, subtaskIndex) => {
                row.querySelector('input')?.setAttribute('name', `project_items[${itemIndex}][subtasks][${subtaskIndex}][title]`);
                const removeSubtask = row.querySelector('[data-remove-initial-subtask]');
                if (removeSubtask) removeSubtask.hidden = subtaskIndex === 0 && item.querySelectorAll('[data-initial-subtask-row]').length === 1;
            });
        });
    };

    const resetProjectItems = () => {
        const list = modalForm?.querySelector('[data-project-items]');
        if (!list) return;
        list.innerHTML = projectItemTemplate(0);
        reindexProjectItems();
    };

    document.getElementById('taskSearch')?.addEventListener('input', applyFilters);
    document.getElementById('statusFilter')?.addEventListener('change', applyFilters);
    document.getElementById('priorityFilter')?.addEventListener('change', applyFilters);

    document.querySelector('[data-open-new-task-modal]')?.addEventListener('click', () => {
        modal.hidden = false;
        modalForm?.reset();
        resetProjectItems();
        modal.querySelectorAll('[data-newtask-collaborator-option]').forEach((option) => option.hidden = false);
        const hint = modal.querySelector('[data-newtask-assignee-hint]');
        if (hint) { hint.hidden = true; hint.classList.remove('is-warning'); }
        modal.querySelector('input[name="project_name"]')?.focus();
    });
    document.querySelectorAll('[data-close-new-task-modal]').forEach((button) => {
        button.addEventListener('click', () => modal.hidden = true);
    });
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) modal.hidden = true;
    });

    document.querySelectorAll('[data-close-collaborator-modal]').forEach((button) => {
        button.addEventListener('click', () => collaboratorModal.hidden = true);
    });
    collaboratorModal?.addEventListener('click', (event) => {
        if (event.target === collaboratorModal) collaboratorModal.hidden = true;
    });
    document.querySelector('[data-collaborator-search]')?.addEventListener('input', (event) => {
        const query = event.target.value.toLowerCase().trim();
        document.querySelectorAll('[data-collaborator-option]').forEach((option) => {
            option.hidden = query && !option.dataset.search.includes(query);
        });
    });

    document.querySelector('[data-newtask-collaborator-search]')?.addEventListener('input', (event) => {
        const query = event.target.value.toLowerCase().trim();
        document.querySelectorAll('[data-newtask-collaborator-option]').forEach((option) => {
            option.hidden = query && !option.dataset.search.includes(query);
        });
    });

    const newTaskAssigneeSelect = modalForm?.querySelector('[data-newtask-assignee]');
    const newTaskAssigneeHint = modalForm?.querySelector('[data-newtask-assignee-hint]');
    newTaskAssigneeSelect?.addEventListener('change', () => {
        const selected = newTaskAssigneeSelect.selectedOptions[0];
        const deptId = selected?.dataset.departmentId || '';
        const myDept = page.dataset.currentUserDepartment || '';
        const isSelf = String(selected?.value) === String('{{ auth()->id() }}');
        if (newTaskAssigneeHint) {
            if (!isSelf && deptId && myDept && deptId !== myDept) {
                newTaskAssigneeHint.hidden = false;
                newTaskAssigneeHint.classList.add('is-warning');
                newTaskAssigneeHint.textContent = 'ผู้รับผิดชอบอยู่คนละแผนก — งานนี้ต้องรอ Admin ตรวจสอบและอนุมัติก่อนจึงจะเริ่มงานได้';
            } else {
                newTaskAssigneeHint.hidden = true;
                newTaskAssigneeHint.classList.remove('is-warning');
            }
        }
    });

    const newTaskAttachmentsInput = modalForm?.querySelector('[data-newtask-attachments]');
    const allowedAttachmentExtensions = ['jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
    const allowedAttachmentText = 'jpg, jpeg, png, doc, docx, xls, xlsx, ppt, pptx';
    newTaskAttachmentsInput?.addEventListener('change', () => {
        const files = [...(newTaskAttachmentsInput.files || [])];
        if (files.length > 5) {
            Swal.fire({icon:'warning', title:'ไฟล์เกินจำนวน', text:'เพิ่มไฟล์อ้างอิงงานได้สูงสุด 5 ไฟล์ต่องาน'});
            newTaskAttachmentsInput.value = '';
            return;
        }
        for (const file of files) {
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (!allowedAttachmentExtensions.includes(ext)) {
                Swal.fire({icon:'warning', title:'ไม่รองรับไฟล์นี้', text:`ไฟล์ "${file.name}" ไม่ใช่ประเภทที่รองรับ (${allowedAttachmentText})`});
                newTaskAttachmentsInput.value = '';
                return;
            }
            if (file.size > 10 * 1024 * 1024) {
                Swal.fire({icon:'warning', title:'ไฟล์ใหญ่เกินไป', text:`ไฟล์ "${file.name}" ต้องไม่เกิน 10MB`});
                newTaskAttachmentsInput.value = '';
                return;
            }
        }
    });

    const openCollaboratorModal = (button) => {
        const existing = (button.dataset.existingUsers || '').split(',').filter(Boolean);
        collaboratorForm.action = urlFor(page.dataset.collaboratorUrlTemplate, button.dataset.taskId);
        collaboratorForm.dataset.taskId = button.dataset.taskId;
        collaboratorForm.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
            checkbox.checked = false;
            checkbox.disabled = existing.includes(checkbox.value);
            checkbox.closest('[data-collaborator-option]').hidden = checkbox.disabled;
        });
        collaboratorForm.querySelector('[data-collaborator-search]').value = '';
        collaboratorForm.querySelector('[data-collaborator-task-title]').textContent = `เลือกพนักงานเพื่อเชิญเข้าร่วม "${button.dataset.taskTitle}"`;
        collaboratorModal.hidden = false;
    };

    modalForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(modalForm);
        try {
            const data = await requestJson(modalForm.action, {method:'POST', body:formData});
            modalForm.reset();
            modal.hidden = true;
            toast.fire({icon:'success', title: data.message || 'เพิ่มโปรเจกต์แล้ว'});
            window.location.reload();
        } catch (error) { showError(error); }
    });

    collaboratorForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const selected = [...collaboratorForm.querySelectorAll('input[name="collaborators[]"]:checked')];
        if (selected.length === 0) {
            Swal.fire({icon:'info', title:'เลือกผู้ร่วมงานก่อน', text:'กรุณาเลือกพนักงานอย่างน้อย 1 คน'});
            return;
        }
        try {
            await requestJson(collaboratorForm.action, {method:'POST', body:new FormData(collaboratorForm)});
            toast.fire({icon:'success', title:'ส่งคำเชิญแล้ว'});
            collaboratorModal.hidden = true;
            window.location.reload();
        } catch (error) { showError(error); }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('.add-task-inline, .subtask-inline-form, .update-inline-form, .attachment-inline-form, .group-rename-form');
        if (!form) return;
        event.preventDefault();
        try {
            if (form.classList.contains('group-rename-form')) {
                const data = await requestJson(form.action, {method:'POST', body:new FormData(form)});
                const group = form.closest('.task-group');
                const title = group?.querySelector('.group-name');
                if (title) title.textContent = data.name || form.querySelector('input[name="name"]').value;
                form.hidden = true;
                group?.querySelector('.group-summary')?.removeAttribute('hidden');
                toast.fire({icon:'success', title:'เปลี่ยนชื่อโปรเจกต์แล้ว'});
                return;
            }

            if (form.classList.contains('attachment-inline-form')) {
                const input = form.querySelector('input[type="file"]');
                const files = [...(input?.files || [])];
                const existing = Number(form.dataset.existingFiles || 0);
                if (files.length === 0) {
                    Swal.fire({icon:'info', title:'ยังไม่ได้เลือกไฟล์', text:'กรุณาเลือกไฟล์ก่อนบันทึก'});
                    return;
                }
                if (existing + files.length > 5) {
                    Swal.fire({icon:'warning', title:'ไฟล์เกินจำนวน', text:'เพิ่มไฟล์อ้างอิงงานได้สูงสุด 5 ไฟล์ต่องาน'});
                    return;
                }
                for (const file of files) {
                    const ext = (file.name.split('.').pop() || '').toLowerCase();
                    if (!allowedAttachmentExtensions.includes(ext)) {
                        Swal.fire({icon:'warning', title:'ไม่รองรับไฟล์นี้', text:`ไฟล์ "${file.name}" ไม่ใช่ประเภทที่รองรับ (${allowedAttachmentText})`});
                        return;
                    }
                }
                if (files.some((file) => file.size > 10 * 1024 * 1024)) {
                    Swal.fire({icon:'warning', title:'ไฟล์ใหญ่เกินไป', text:'แต่ละไฟล์ต้องไม่เกิน 10MB'});
                    return;
                }
                await requestJson(form.action, {method:'POST', body:new FormData(form)});
                toast.fire({icon:'success', title:'เพิ่มไฟล์อ้างอิงแล้ว'});
                window.location.reload();
                return;
            }

            if (form.classList.contains('update-inline-form')) {
                await requestJson(form.action, {method:'POST', body:new FormData(form)});
                toast.fire({icon:'success', title:'บันทึกอัปเดตแล้ว'});
                window.location.reload();
                return;
            }

            if (form.classList.contains('add-task-inline')) {
                const formData = new FormData(form);
                await ensureListForTask(formData);
                await requestJson(form.action, {method:'POST', body:formData});
                form.reset();
                toast.fire({icon:'success', title:'เพิ่มงานแล้ว'});
                window.location.reload();
                return;
            }
            await requestJson(form.action, {method:'POST', body:new FormData(form)});
            toast.fire({icon:'success', title:'บันทึกแล้ว'});
            window.location.reload();
        } catch (error) { showError(error); }
    });

    document.addEventListener('click', async (event) => {
        const addProjectItem = event.target.closest('[data-add-project-item]');
        if (addProjectItem) {
            const list = modalForm?.querySelector('[data-project-items]');
            if (!list) return;
            const itemIndex = list.querySelectorAll('[data-project-item]').length;
            const holder = document.createElement('div');
            holder.innerHTML = projectItemTemplate(itemIndex);
            list.appendChild(holder.firstElementChild);
            reindexProjectItems();
            list.querySelector(`[name="project_items[${itemIndex}][job_topic]"]`)?.focus();
            return;
        }

        const removeProjectItem = event.target.closest('[data-remove-project-item]');
        if (removeProjectItem) {
            removeProjectItem.closest('[data-project-item]')?.remove();
            reindexProjectItems();
            return;
        }

        const addInitialSubtask = event.target.closest('[data-add-initial-subtask]');
        if (addInitialSubtask) {
            const item = addInitialSubtask.closest('[data-project-item]');
            const list = item?.querySelector('[data-initial-subtasks]');
            if (!item || !list) return;
            const itemIndex = [...modalForm.querySelectorAll('[data-project-item]')].indexOf(item);
            const subtaskIndex = list.querySelectorAll('[data-initial-subtask-row]').length;
            const holder = document.createElement('div');
            holder.innerHTML = subtaskTemplate(itemIndex, subtaskIndex);
            list.appendChild(holder.firstElementChild);
            reindexProjectItems();
            list.querySelector(`[name="project_items[${itemIndex}][subtasks][${subtaskIndex}][title]"]`)?.focus();
            return;
        }

        const removeInitialSubtask = event.target.closest('[data-remove-initial-subtask]');
        if (removeInitialSubtask) {
            removeInitialSubtask.closest('[data-initial-subtask-row]')?.remove();
            reindexProjectItems();
            return;
        }

        const boardTab = event.target.closest('[data-board-tab]');
        if (boardTab) {
            const boardName = boardTab.dataset.boardTab;
            document.querySelectorAll('[data-board-tab]').forEach((tab) => tab.classList.toggle('is-active', tab === boardTab));
            document.querySelectorAll('[data-task-board]').forEach((board) => {
                board.hidden = board.dataset.taskBoard !== boardName;
            });
            return;
        }

        const editList = event.target.closest('[data-edit-list]');
        if (editList) {
            const group = editList.closest('.task-group');
            const form = group?.querySelector(`[data-list-rename-form="${editList.dataset.listId}"]`);
            if (form) {
                form.hidden = false;
                group?.querySelector('.group-summary')?.setAttribute('hidden', 'hidden');
                form.querySelector('input[name="name"]')?.focus();
            }
            return;
        }

        const cancelListRename = event.target.closest('[data-cancel-list-rename]');
        if (cancelListRename) {
            const form = cancelListRename.closest('.group-rename-form');
            const group = form?.closest('.task-group');
            form?.setAttribute('hidden', 'hidden');
            group?.querySelector('.group-summary')?.removeAttribute('hidden');
            return;
        }

        const memberButton = event.target.closest('[data-open-member-modal]');
        if (memberButton) {
            const memberModal = document.querySelector(`[data-member-modal="${memberButton.dataset.openMemberModal}"]`);
            if (memberModal) memberModal.hidden = false;
            return;
        }

        const fileButton = event.target.closest('[data-open-file-modal]');
        if (fileButton) {
            const fileModal = document.querySelector(`[data-file-modal="${fileButton.dataset.openFileModal}"]`);
            if (fileModal) fileModal.hidden = false;
            return;
        }

        const closeInlineModal = event.target.closest('[data-close-inline-modal]');
        if (closeInlineModal) {
            closeInlineModal.closest('.simple-modal')?.setAttribute('hidden', 'hidden');
            return;
        }

        if (event.target.matches('.member-info-modal, .file-list-modal')) {
            event.target.hidden = true;
            return;
        }

        const activityTrigger = event.target.closest('[data-open-task-activity-modal]');
        if (activityTrigger) {
            const activityModal = document.querySelector(`[data-task-activity-modal="${activityTrigger.dataset.openTaskActivityModal}"]`);
            if (activityModal) activityModal.hidden = false;
            return;
        }

        const closeActivityModal = event.target.closest('[data-close-task-activity-modal]');
        if (closeActivityModal) {
            closeActivityModal.closest('[data-task-activity-modal]')?.setAttribute('hidden', 'hidden');
            return;
        }

        if (event.target.matches('[data-task-activity-modal]')) {
            event.target.hidden = true;
            return;
        }

        const activityTab = event.target.closest('[data-task-activity-tab]');
        if (activityTab) {
            const activityModal = activityTab.closest('[data-task-activity-modal]');
            activityModal?.querySelectorAll('[data-task-activity-tab]').forEach((tab) => tab.classList.toggle('is-active', tab === activityTab));
            activityModal?.querySelectorAll('[data-task-activity-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.taskActivityPanel !== activityTab.dataset.taskActivityTab;
            });
            return;
        }

        const collapse = event.target.closest('[data-collapse-group]');
        if (collapse) {
            const body = collapse.closest('.task-group')?.querySelector('.group-body');
            body.hidden = !body.hidden;
            collapse.querySelector('i')?.classList.toggle('bi-chevron-right', body.hidden);
            collapse.querySelector('i')?.classList.toggle('bi-chevron-down', !body.hidden);
            return;
        }

        const scrollButton = event.target.closest('[data-scroll-list]');
        if (scrollButton) {
            const lane = document.querySelector(`[data-list-lane="${scrollButton.dataset.scrollList}"]`);
            lane?.classList.remove('is-hidden');
            lane?.scrollIntoView({behavior:'smooth', block:'start'});
            return;
        }

        const expand = event.target.closest('[data-expand-task]');
        if (expand) {
            const panel = document.querySelector(`[data-subtask-panel="${expand.dataset.expandTask}"]`);
            if (!panel) return;
            panel.hidden = !panel.hidden;
            document.querySelectorAll(`[data-expand-task="${expand.dataset.expandTask}"] i`).forEach((icon) => {
                icon.classList.toggle('bi-chevron-down', !panel.hidden);
                icon.classList.toggle('bi-chevron-right', panel.hidden);
            });
            return;
        }

        const collaboratorButton = event.target.closest('[data-open-collaborator-modal]');
        if (collaboratorButton) {
            openCollaboratorModal(collaboratorButton);
            return;
        }

        const showAll = event.target.closest('[data-show-all-groups]');
        if (showAll) {
            document.querySelectorAll('.task-group').forEach((group) => group.classList.remove('is-hidden'));
            return;
        }

        const sortDue = event.target.closest('[data-sort-due]');
        if (sortDue) {
            document.querySelectorAll('[data-group-body]').forEach((tbody) => {
                const rows = [...tbody.querySelectorAll('[data-task-row]')].sort((a, b) => (a.dataset.due || '9999-12-31').localeCompare(b.dataset.due || '9999-12-31'));
                const addRow = tbody.querySelector('.add-row');
                rows.forEach((row) => {
                    const panel = document.querySelector(`[data-subtask-panel="${row.dataset.taskId}"]`);
                    tbody.insertBefore(row, addRow);
                    if (panel) tbody.insertBefore(panel, addRow);
                });
            });
            toast.fire({icon:'success', title:'เรียงตามกำหนดส่งแล้ว'});
            return;
        }

        const complete = event.target.closest('[data-task-complete]');
        if (complete) {
            const willComplete = complete.dataset.completed === '1';
            if (willComplete && !await confirmProjectCompletion(complete.closest('[data-task-row]'))) return;
            try {
                await requestJson(complete.dataset.url, {method:'PATCH', body:JSON.stringify({completed:willComplete})});
                toast.fire({icon:'success', title:'อัปเดตงานแล้ว'});
                window.location.reload();
            } catch (error) { showError(error); }
            return;
        }

        const deleteTask = event.target.closest('[data-delete-task]');
        if (deleteTask) {
            const isDeleteRequest = deleteTask.dataset.deleteRequest === '1';
            const result = await Swal.fire({
                icon:'warning',
                title:'ลบงานนี้?',
                text:`งาน "${deleteTask.dataset.taskTitle}" จะถูกย้ายไปถังขยะ`,
                showCancelButton:true,
                confirmButtonText:'ลบงาน',
                cancelButtonText:'ยกเลิก',
                confirmButtonColor:'#ef4444',
            });
            if (!result.isConfirmed) return;
            try {
                const data = await requestJson(deleteTask.dataset.url, {method:'DELETE'});
                if (data.delete_requested) {
                    toast.fire({icon:'success', title:data.message || 'Delete request sent'});
                    return;
                }
                const row = deleteTask.closest('[data-task-row]');
                document.querySelector(`[data-subtask-panel="${row.dataset.taskId}"]`)?.remove();
                row.remove();
                toast.fire({icon:'success', title:'ลบงานแล้ว'});
            } catch (error) { showError(error); }
            return;
        }

        const deleteList = event.target.closest('[data-delete-list]');
        if (deleteList) {
            const result = await Swal.fire({
                icon:'warning',
                title:'ลบรายการนี้?',
                text:`รายการ "${deleteList.dataset.listName}" และงานทั้งหมดในรายการนี้จะถูกลบ`,
                showCancelButton:true,
                confirmButtonText:'ลบรายการ',
                cancelButtonText:'ยกเลิก',
                confirmButtonColor:'#ef4444',
            });
            if (!result.isConfirmed) return;
            try {
                await requestJson(deleteList.dataset.url, {method:'DELETE'});
                toast.fire({icon:'success', title:'ลบรายการแล้ว'});
                window.location.reload();
            } catch (error) { showError(error); }
            return;
        }

        const subtaskToggle = event.target.closest('[data-subtask-toggle]');
        if (subtaskToggle) {
            try {
                await requestJson(subtaskToggle.dataset.url, {method:'PATCH', body:JSON.stringify({completed:subtaskToggle.dataset.completed === '1'})});
                toast.fire({icon:'success', title:'อัปเดตงานย่อยแล้ว'});
                window.location.reload();
            } catch (error) { showError(error); }
        }
    });

    document.addEventListener('change', async (event) => {
        const statusSelect = event.target.closest('[data-status-select]');
        if (statusSelect) {
            const oldValue = statusSelect.dataset.currentValue || statusSelect.value;
            if (statusSelect.value === '4' && !await confirmProjectCompletion(statusSelect.closest('[data-task-row]'))) {
                statusSelect.value = oldValue;
                return;
            }
            try {
                await requestJson(statusSelect.dataset.url, {method:'POST', body:JSON.stringify({job_status:statusSelect.value})});
                statusSelect.className = `label-select status-label ${statusClass[statusSelect.value] || ''}`;
                statusSelect.closest('[data-task-row]').dataset.status = statusSelect.value;
                statusSelect.dataset.currentValue = statusSelect.value;
                toast.fire({icon:'success', title:'ปรับสถานะแล้ว'});
                if (statusSelect.value === '4') window.location.reload();
            } catch (error) {
                statusSelect.value = oldValue;
                showError(error);
            }
            return;
        }

        const prioritySelect = event.target.closest('[data-priority-select]');
        if (prioritySelect) {
            const oldValue = prioritySelect.dataset.oldValue || prioritySelect.value;
            try {
                await requestJson(prioritySelect.dataset.url, {method:'POST', body:JSON.stringify({job_priority:prioritySelect.value})});
                prioritySelect.className = `label-select priority-label ${priorityClass[prioritySelect.value] || ''}`;
                prioritySelect.closest('[data-task-row]').dataset.priority = prioritySelect.value;
                prioritySelect.dataset.oldValue = prioritySelect.value;
                toast.fire({icon:'success', title:'ปรับความสำคัญแล้ว'});
            } catch (error) {
                prioritySelect.value = oldValue;
                showError(error);
            }
            return;
        }

        const dueInput = event.target.closest('[data-due-input]');
        if (dueInput) {
            try {
                await requestJson(dueInput.dataset.url, {method:'POST', body:JSON.stringify({job_due_at:dueInput.value})});
                dueInput.closest('[data-task-row]').dataset.due = dueInput.value;
                toast.fire({icon:'success', title:'เปลี่ยนกำหนดส่งแล้ว'});
            } catch (error) { showError(error); }
        }
    });

    document.querySelectorAll('[data-status-select]').forEach((select) => select.dataset.oldValue = select.value);
    document.querySelectorAll('[data-priority-select]').forEach((select) => select.dataset.oldValue = select.value);
})();
</script>
@endpush

