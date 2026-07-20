@extends('layouts.app')

@section('title', 'งานของฉัน')

@php
    $activeCount = $activeTasks->count();
    $starredCount = $starredTasks->count();
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
    $ungroupedActiveTasks = $activeTasks->filter(fn ($task) => blank($task->work_order_list_id))->values();
    $ungroupedCompletedTasks = $completedTasks->filter(fn ($task) => blank($task->work_order_list_id))->values();
    $showInboxGroup = $taskLists->isEmpty() || $ungroupedActiveTasks->isNotEmpty() || $ungroupedCompletedTasks->isNotEmpty();
@endphp

@push('styles')
<style>
    .tasks-page { display:grid; gap:18px; }
    .tasks-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-end; flex-wrap:wrap; }
    .tasks-title h1 { margin:0; font-size:34px; font-weight:900; letter-spacing:0; }
    .tasks-title p { margin:5px 0 0; color:var(--text-muted); }
    .primary-btn { min-height:42px; border:0; border-radius:12px; padding:0 16px; background:var(--accent); color:#fff; font-weight:850; display:inline-flex; align-items:center; gap:8px; box-shadow:var(--shadow-sm); }
    .tasks-toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:#fff; border:1px solid var(--border); border-radius:16px; padding:12px; box-shadow:var(--shadow-sm); }
    .tool-field { min-height:40px; border:1px solid var(--border); border-radius:12px; background:#fff; display:inline-flex; align-items:center; gap:8px; padding:0 11px; color:var(--text-muted); }
    .tool-field input, .tool-field select { border:0; outline:0; background:transparent; font:inherit; color:var(--text); min-width:150px; }
    .tool-btn { min-height:40px; border:1px solid var(--border); border-radius:12px; background:#fff; color:var(--text); padding:0 12px; font-weight:800; display:inline-flex; align-items:center; gap:8px; }

    .task-board { display:grid; gap:18px; }
    .task-group { background:#fff; border:1px solid var(--border); border-radius:10px; box-shadow:var(--shadow-sm); overflow:hidden; }
    .task-group.is-hidden { display:none; }
    .group-head { min-height:58px; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:0 16px; border-left:5px solid var(--accent); border-bottom:1px solid var(--border); }
    .group-title { display:flex; align-items:center; gap:10px; min-width:0; }
    .group-toggle { width:30px; height:30px; border:0; border-radius:50%; background:transparent; color:var(--text-muted); display:grid; place-items:center; }
    .group-name { margin:0; color:#059669; font-size:18px; font-weight:900; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .group-count { border-radius:999px; background:var(--accent-dim); color:var(--accent-strong); padding:3px 9px; font-weight:850; font-size:12px; }
    .group-summary { color:var(--text-muted); font-size:13px; font-weight:750; }
    .task-table-wrap { overflow-x:auto; }
    .task-table { width:100%; min-width:1120px; border-collapse:separate; border-spacing:0; table-layout:fixed; }
    .task-table th, .task-table td { border-bottom:1px solid #d9e2f0; border-right:1px solid #d9e2f0; padding:0 10px; height:38px; vertical-align:middle; background:#fff; }
    .task-table th { position:sticky; top:0; z-index:1; background:#fbfdff; color:var(--text-muted); font-size:12px; font-weight:750; text-align:left; }
    .task-table th:last-child, .task-table td:last-child { border-right:0; }
    .check-col { width:44px; text-align:center; }
    .name-col { width:350px; display:flex; align-items:center; gap:8px; }
    .row-actions { width:96px; white-space:nowrap; }
    .task-row:hover td { background:#f8fbff; }
    .task-row.is-completed .task-title-text { color:var(--text-muted); text-decoration:line-through; }
    .task-check { width:22px; height:22px; border:2px solid #cbd5e1; border-radius:6px; background:#fff; color:transparent; display:inline-grid; place-items:center; }
    .task-check.small { width:18px; height:18px; border-radius:50%; font-size:10px; }
    .task-check.is-on { background:#22c55e; border-color:#22c55e; color:#fff; }
    .expand-task { width:26px; height:26px; border:0; border-radius:50%; background:transparent; color:var(--text-muted); display:grid; place-items:center; }
    .task-name-wrap { min-width:0; }
    .task-title-line { display:flex; gap:8px; align-items:center; min-width:0; }
    .task-title-text { font-weight:850; color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .task-detail-line { color:var(--text-muted); font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:2px; }
    .approval-badge { flex:0 0 auto; border-radius:999px; background:#fff7ed; color:#ea580c; padding:2px 8px; font-size:11px; font-weight:850; }
    .label-select, .due-input { width:100%; min-height:30px; border:0; border-radius:2px; padding:0 9px; font:inherit; font-weight:850; text-align:center; }
    .status-not-started { background:#f1f5f9; color:#475569; }
    .status-working { background:#ffedd5; color:#c2410c; }
    .status-review { background:#ede9fe; color:#6d28d9; }
    .status-done { background:#dcfce7; color:#15803d; }
    .status-paused { background:#fee2e2; color:#991b1b; }
    .status-overdue { background:#fecaca; color:#b91c1c; }
    .due-input.overdue { background:#fee2e2; color:#dc2626; border-color:#fecaca; }
    .due-input.soon { background:#fef3c7; color:#92400e; border-color:#fde68a; }
    .priority-pill, .priority-label { border-radius:999px; padding:5px 10px; font-weight:850; font-size:12px; white-space:nowrap; }
    .priority-low { background:#f1f5f9; color:#475569; }
    .priority-medium { background:#fef3c7; color:#b45309; }
    .priority-high { background:#fee2e2; color:#dc2626; }
    .progress-cell { border:0; background:transparent; width:100%; display:flex; align-items:center; gap:8px; color:var(--text); }
    .progress-track { height:9px; flex:1; border-radius:999px; background:#e5e7eb; overflow:hidden; }
    .progress-track span { display:block; height:100%; border-radius:999px; background:linear-gradient(90deg, var(--accent), #10b981); }
    .avatar-stack { display:flex; align-items:center; }
    .avatar-dot, .avatar-more { width:28px; height:28px; border-radius:50%; border:2px solid #fff; margin-left:-6px; background:var(--accent); color:#fff; display:grid; place-items:center; font-size:11px; font-weight:900; }
    .avatar-dot:first-child { margin-left:0; }
    .avatar-dot.muted { background:#94a3b8; }
    .avatar-more { background:#e2e8f0; color:#475569; }
    .avatar-add { width:28px; height:28px; border-radius:50%; border:1px solid var(--border); margin-left:4px; background:#fff; color:var(--accent); display:grid; place-items:center; }
    .avatar-add:hover { background:var(--accent-dim); }
    .file-pill { border-radius:999px; background:#f1f5f9; color:#475569; padding:4px 8px; font-weight:800; font-size:12px; }
    .icon-row-btn { width:32px; height:32px; border:0; border-radius:50%; background:#fff; color:#94a3b8; }
    .icon-row-btn.is-starred { color:#f59e0b; }
    .icon-row-btn.danger { color:#ef4444; }
    .icon-row-btn:hover { background:var(--surface-2); }
    .add-row td { background:#fbfcff; }
    .add-task-inline { display:flex; gap:8px; align-items:center; }
    .add-task-inline input { min-height:36px; border:1px solid var(--border); border-radius:10px; padding:0 10px; font:inherit; }
    .add-task-inline input[name="job_topic"] { min-width:260px; flex:1; }
    .add-task-inline button { min-height:36px; border:0; border-radius:10px; background:var(--accent); color:#fff; font-weight:850; padding:0 12px; }
    .completed-group details { border-top:1px solid var(--border); }
    .completed-group summary { cursor:pointer; padding:13px 16px; color:#16a34a; font-weight:900; list-style:none; display:flex; gap:8px; align-items:center; }
    .completed-group summary::-webkit-details-marker { display:none; }
    .subtask-row td { background:#fff; height:auto; padding:0 14px 10px; }
    .subtask-panel { display:grid; gap:8px; max-width:760px; margin-left:34px; border-left:2px solid #10b981; padding-left:8px; }
    .subitem-table { width:100%; border-collapse:collapse; background:#fff; }
    .subitem-table th, .subitem-table td { height:30px; border:1px solid #d9e2f0; padding:0 8px; font-size:13px; }
    .subitem-table th { color:var(--text-muted); font-weight:750; background:#fbfdff; }
    .subitem-table tr.is-completed .subtask-title { text-decoration:line-through; color:var(--text-muted); }
    .subtask-title { font-weight:850; }
    .subtask-details, .subtask-empty { color:var(--text-muted); font-size:13px; }
    .subtask-inline-form { display:grid; grid-template-columns:1fr 1fr auto; gap:8px; }
    .subtask-inline-form input { min-height:36px; border:1px solid var(--border); border-radius:10px; padding:0 10px; font:inherit; }
    .subtask-inline-form button { border:0; border-radius:10px; background:var(--accent-dim); color:var(--accent-strong); font-weight:850; padding:0 12px; }
    .task-panel-tabs { display:flex; gap:8px; flex-wrap:wrap; color:var(--text-muted); font-size:12px; font-weight:850; }
    .task-panel-tabs span { display:inline-flex; gap:5px; align-items:center; border:1px solid var(--border); border-radius:999px; padding:5px 9px; background:#fff; }
    .panel-section { border:1px solid var(--border); border-radius:12px; padding:12px; background:#fff; display:grid; gap:10px; }
    .panel-section h3 { margin:0; font-size:15px; font-weight:900; }
    .update-inline-form { display:grid; grid-template-columns:90px 1fr auto; gap:8px; align-items:start; }
    .update-inline-form input, .update-inline-form textarea { border:1px solid var(--border); border-radius:10px; padding:9px 10px; font:inherit; }
    .update-inline-form textarea { min-height:74px; resize:vertical; }
    .update-inline-form button, .attachment-inline-form button { min-height:38px; border:0; border-radius:10px; background:var(--accent); color:#fff; font-weight:850; padding:0 12px; }
    .update-list, .activity-list, .attachment-list { display:grid; gap:8px; }
    .update-item, .activity-item, .attachment-item { border:1px solid var(--border); border-radius:10px; padding:9px 10px; background:#fbfdff; color:var(--text); text-decoration:none; }
    .update-item span, .activity-item span { display:block; color:var(--text-muted); font-size:12px; margin-top:2px; }
    .update-item p { margin:7px 0 0; color:var(--text); }
    .attachment-inline-form { display:grid; grid-template-columns:1fr auto; gap:10px; align-items:end; }
    .attachment-drop { border:1px dashed var(--accent); border-radius:12px; background:var(--accent-dim); padding:12px; display:grid; gap:4px; color:var(--accent-strong); font-weight:850; cursor:pointer; }
    .attachment-drop small { color:var(--text-muted); font-weight:650; }
    .attachment-drop input { margin-top:5px; }
    .empty-row-message, .page-empty { padding:34px; color:var(--text-muted); text-align:center; }
    .new-group-form { display:flex; gap:8px; align-items:center; background:#fff; border:1px dashed var(--border); border-radius:16px; padding:14px; width:min(460px, 100%); }
    .new-group-form input { flex:1; min-height:40px; border:1px solid var(--border); border-radius:12px; padding:0 12px; font:inherit; }
    .new-group-form button { min-height:40px; border:0; border-radius:12px; background:var(--accent); color:#fff; font-weight:850; padding:0 14px; }
    .simple-modal[hidden] { display:none; }
    .simple-modal { position:fixed; inset:0; z-index:80; display:grid; place-items:center; padding:18px; background:rgba(15,23,42,.45); }
    .simple-modal-card { width:min(440px, 100%); background:#fff; border-radius:18px; border:1px solid var(--border); box-shadow:0 24px 60px rgba(15,23,42,.22); overflow:hidden; }
    .simple-modal-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding:18px 20px 12px; border-bottom:1px solid var(--border); }
    .simple-modal-head h2 { margin:0; font-size:22px; font-weight:900; }
    .simple-modal-head p { margin:4px 0 0; color:var(--text-muted); font-size:14px; }
    .simple-modal-close { border:0; background:transparent; color:var(--text-muted); font-size:24px; line-height:1; }
    .simple-modal-body { padding:18px 20px 20px; display:grid; gap:14px; }
    .simple-field { display:grid; gap:7px; font-weight:850; }
    .simple-field input, .simple-field select { min-height:42px; border:1px solid var(--border); border-radius:12px; padding:0 12px; font:inherit; background:#fff; }
    .simple-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:4px; }
    .secondary-btn { min-height:40px; border:0; border-radius:12px; padding:0 14px; background:var(--surface-2); color:var(--text); font-weight:850; }
    .collaborator-search { width:100%; min-height:40px; border:1px solid var(--border); border-radius:12px; padding:0 12px; font:inherit; }
    .collaborator-list { display:grid; gap:8px; max-height:320px; overflow:auto; padding-right:4px; }
    .collaborator-option { display:flex; align-items:center; gap:10px; border:1px solid var(--border); border-radius:12px; padding:10px; cursor:pointer; }
    .collaborator-option:hover { background:var(--surface-2); }
    .collaborator-option input { width:18px; height:18px; accent-color:var(--accent); }
    .collaborator-meta { color:var(--text-muted); font-size:12px; }

    @media (max-width:900px) {
        .tasks-title h1 { font-size:28px; }
        .task-table, .task-table tbody, .task-table tr, .task-table td { display:block; min-width:0; width:100%; }
        .task-table thead { display:none; }
        .task-table tr.task-row { border-bottom:1px solid var(--border); padding:12px; }
        .task-table td { border:0; height:auto; padding:7px 0; }
        .task-table td::before { content:attr(data-label); display:block; color:var(--text-muted); font-size:12px; font-weight:850; margin-bottom:4px; }
        .check-col::before, .row-actions::before { display:none !important; }
        .name-col { display:flex !important; width:100%; }
        .add-task-inline, .subtask-inline-form, .update-inline-form, .attachment-inline-form { display:grid; grid-template-columns:1fr; }
    }
</style>
@endpush

@section('content')
<div class="tasks-page"
    data-current-user-name="{{ auth()->user()->name }}"
    data-store-url="{{ route('mytasks.store') }}"
    data-list-store-url="{{ route('mytasks.lists.store') }}"
    data-status-url-template="{{ route('mytasks.updateStatus', ['job_id' => '__ID__']) }}"
    data-priority-url-template="{{ route('mytasks.updatePriority', ['job_id' => '__ID__']) }}"
    data-collaborator-url-template="{{ route('tasks.collaborators.store', ['id' => '__ID__']) }}"
    data-attachment-url-template="{{ route('tasks.attachments.store', ['id' => '__ID__']) }}"
    data-progress-url-template="{{ route('tasks.progress.store', ['id' => '__ID__']) }}"
    data-complete-url-template="{{ route('mytasks.complete', ['job_id' => '__ID__']) }}"
    data-star-url-template="{{ route('mytasks.star', ['job_id' => '__ID__']) }}"
    data-delete-url-template="{{ route('mytasks.destroy', ['job_id' => '__ID__']) }}"
    data-due-url-template="{{ route('mytasks.updateDueDate', ['job_id' => '__ID__']) }}">
    <section class="tasks-head">
        <div class="tasks-title">
            <h1>งานของฉัน</h1>
            <p>จัดการงานเป็นตาราง แยกตามรายการงาน เปลี่ยนสถานะและกำหนดส่งได้ในหน้าเดียว</p>
        </div>
        <button type="button" class="primary-btn" data-open-new-task-modal>
            <i class="bi bi-plus-lg"></i> สร้างงาน
        </button>
    </section>

    <section class="tasks-toolbar">
        <label class="tool-field">
            <i class="bi bi-search"></i>
            <input id="taskSearch" type="search" placeholder="Search task, details, subtask...">
        </label>
        <label class="tool-field">
            <i class="bi bi-kanban"></i>
            <select id="statusFilter">
                <option value="">Status ทั้งหมด</option>
                @foreach ($statusLabels as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="tool-field">
            <i class="bi bi-flag"></i>
            <select id="priorityFilter">
                <option value="">Priority ทั้งหมด</option>
                @foreach ($priorityLabels as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button type="button" class="tool-btn" data-sort-due>
            <i class="bi bi-sort-down"></i> Sort due date
        </button>
        <button type="button" class="tool-btn" data-show-all-groups>
            <i class="bi bi-eye"></i> Show all groups
        </button>
    </section>

    <section class="task-board" data-task-board>
        @if ($showInboxGroup)
            @include('tasks.partials.task-table-group', [
                'listId' => 'inbox',
                'listName' => 'งานของฉัน',
                'isVisible' => true,
                'listTasks' => $ungroupedActiveTasks,
                'listCompletedTasks' => $ungroupedCompletedTasks,
                'isVirtual' => true,
            ])
        @endif

        @foreach ($taskLists as $list)
            @php
                $listTasks = $activeTasks->where('work_order_list_id', $list->id)->values();
                $listCompletedTasks = $completedTasks->where('work_order_list_id', $list->id)->values();
            @endphp
            @include('tasks.partials.task-table-group', [
                'listId' => $list->id,
                'listName' => $list->name,
                'isVisible' => $list->is_visible,
                'listTasks' => $listTasks,
                'listCompletedTasks' => $listCompletedTasks,
                'isVirtual' => false,
            ])
        @endforeach

        <form class="new-group-form" action="{{ route('mytasks.lists.store') }}" method="POST">
            @csrf
            <i class="bi bi-plus-lg"></i>
            <input type="text" name="name" maxlength="80" required placeholder="Add new group">
            <button type="submit">สร้างกลุ่ม</button>
        </form>
    </section>
</div>

<div class="simple-modal" data-new-task-modal hidden>
    <form class="simple-modal-card" id="newTaskForm" action="{{ route('mytasks.store') }}" method="POST">
        @csrf
        <div class="simple-modal-head">
            <div>
                <h2>สร้างงาน</h2>
                <p>ใส่แค่ชื่องานก่อน รายละเอียดอื่นแก้ต่อในตารางได้</p>
            </div>
            <button type="button" class="simple-modal-close" data-close-new-task-modal aria-label="ปิด">&times;</button>
        </div>
        <div class="simple-modal-body">
            <label class="simple-field">
                ชื่อโปรเจกต์
                <input type="text" name="job_topic" maxlength="255" required placeholder="พิมพ์ชื่อโปรเจกต์...">
            </label>
            <label class="simple-field">
                รายการ/กลุ่ม
                <select name="work_order_list_id">
                    @forelse ($taskLists as $list)
                        <option value="{{ $list->id }}">{{ $list->name }}</option>
                    @empty
                        <option value="">งานของฉัน (สร้างอัตโนมัติ)</option>
                    @endforelse
                </select>
            </label>
            <div class="simple-actions">
                <button type="button" class="secondary-btn" data-close-new-task-modal>ยกเลิก</button>
                <button type="submit" class="primary-btn">สร้างงาน</button>
            </div>
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
    const statusText = {2:'กำลังดำเนินงาน', 4:'งานเสร็จสิ้น', 5:'พักงาน'};
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

    const applyFilters = () => {
        const q = document.getElementById('taskSearch')?.value.toLowerCase().trim() || '';
        const status = document.getElementById('statusFilter')?.value || '';
        const priority = document.getElementById('priorityFilter')?.value || '';
        const starredOnly = false;

        document.querySelectorAll('[data-task-row]').forEach((row) => {
            const match = (!q || row.dataset.search.includes(q))
                && (!status || row.dataset.status === status || (status === '2' && ['1', '3'].includes(row.dataset.status)))
                && (!priority || row.dataset.priority === priority)
                && (!starredOnly || row.dataset.starred === '1');
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
            <tr class="task-row" data-task-row data-task-id="${id}" data-search="${topic.toLowerCase()}" data-status="2" data-priority="2" data-due="" data-starred="0">
                <td class="check-col" data-label=""><button type="button" class="task-check" data-task-complete data-url="${urlFor(page.dataset.completeUrlTemplate, id)}" data-completed="1" aria-label="ทำเครื่องหมายว่าเสร็จ"><i class="bi bi-check-lg"></i></button></td>
                <td class="name-col" data-label="งาน">
                    <button type="button" class="expand-task" data-expand-task="${id}" aria-label="ดูงานย่อย"><i class="bi bi-chevron-right"></i></button>
                    <div class="task-name-wrap"><div class="task-title-line"><span class="task-title-text">${topic}</span></div></div>
                </td>
                <td data-label="ความสำคัญ"><select class="label-select priority-label ${priorityClass[2]}" data-priority-select data-url="${urlFor(page.dataset.priorityUrlTemplate, id)}">${Object.entries(priorityText).map(([value, label]) => `<option value="${value}" ${value === '2' ? 'selected' : ''}>${label}</option>`).join('')}</select></td>
                <td data-label="กำหนดส่ง"><input type="date" class="due-input" data-due-input data-url="${urlFor(page.dataset.dueUrlTemplate, id)}"></td>
                <td data-label="Subitem"><button type="button" class="progress-cell" data-expand-task="${id}"><span class="progress-track"><span style="width:0%"></span></span><strong>0/0</strong></button></td>
                <td data-label="ผู้ร่วมงาน"><div class="avatar-stack"><span class="avatar-dot" title="${currentUser}">${initials}</span><button type="button" class="avatar-add" data-open-collaborator-modal data-task-id="${id}" data-task-title="${topic}" data-existing-users="" aria-label="เพิ่มผู้ร่วมงาน"><i class="bi bi-plus-lg"></i></button></div></td>
                <td data-label="ไฟล์"><span class="file-pill"><i class="bi bi-paperclip"></i> 0</span></td>
                <td data-label="สถานะ"><select class="label-select status-label status-working" data-status-select data-url="${urlFor(page.dataset.statusUrlTemplate, id)}">${Object.entries(statusText).map(([value, label]) => `<option value="${value}" ${value === '2' ? 'selected' : ''}>${label}</option>`).join('')}</select></td>
                <td class="row-actions" data-label="">
                    <button type="button" class="icon-row-btn" data-star-task data-url="${urlFor(page.dataset.starUrlTemplate, id)}" data-starred="1" aria-label="ติดดาว"><i class="bi bi-star"></i></button>
                    <button type="button" class="icon-row-btn danger" data-delete-task data-task-title="${topic}" data-url="${urlFor(page.dataset.deleteUrlTemplate, id)}" aria-label="ลบงาน"><i class="bi bi-trash3"></i></button>
                </td>
            </tr>
            <tr class="subtask-row" data-subtask-panel="${id}" hidden>
                <td></td>
                <td colspan="8">
                    <div class="subtask-panel">
                        <div class="task-panel-tabs"><span><i class="bi bi-list-check"></i> งานย่อย</span><span><i class="bi bi-chat-left-text"></i> อัปเดต</span><span><i class="bi bi-paperclip"></i> ไฟล์</span><span><i class="bi bi-clock-history"></i> Activity Log</span></div>
                        <table class="subitem-table">
                            <thead><tr><th class="check-col"></th><th>Subitem</th><th>รายละเอียด</th><th>Date</th></tr></thead>
                            <tbody><tr><td colspan="4"><div class="subtask-empty">ยังไม่มีงานย่อย</div></td></tr></tbody>
                        </table>
                        <div class="panel-section">
                            <h3>อัปเดตงาน</h3>
                            <form class="update-inline-form" action="${urlFor(page.dataset.progressUrlTemplate, id)}" method="POST">
                                <input type="number" name="progress" min="0" max="99" value="0">
                                <textarea name="note" maxlength="2000" required placeholder="เขียนอัปเดตงาน..."></textarea>
                                <button type="submit"><i class="bi bi-send"></i> บันทึกอัปเดต</button>
                            </form>
                            <div class="subtask-empty">ยังไม่มีอัปเดตงาน</div>
                        </div>
                        <div class="panel-section">
                            <h3>ไฟล์แนบ</h3>
                            <div class="subtask-empty">ยังไม่มีไฟล์แนบ</div>
                            <form class="attachment-inline-form" action="${urlFor(page.dataset.attachmentUrlTemplate, id)}" method="POST" enctype="multipart/form-data" data-existing-files="0">
                                <label class="attachment-drop"><i class="bi bi-cloud-arrow-up"></i><span>เลือกหรือลากไฟล์มาวาง</span><small>แนบได้สูงสุด 5 ไฟล์ ไฟล์ละไม่เกิน 5MB</small><input type="file" name="completion_attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.xls,.xlsx,.csv,.zip"></label>
                                <button type="submit"><i class="bi bi-upload"></i> บันทึกไฟล์</button>
                            </form>
                        </div>
                        <div class="panel-section"><h3>Activity Log</h3><div class="subtask-empty">ยังไม่มีประวัติการทำงาน</div></div>
                    </div>
                </td>
            </tr>`;
    };

    const makeGroup = (listId, name) => {
        const board = document.querySelector('[data-task-board]');
        const form = board.querySelector('.new-group-form');
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
                            <thead><tr><th class="check-col"><input type="checkbox" disabled></th><th class="name-col">Project</th><th>ความสำคัญ</th><th>กำหนดส่ง</th><th>Subitem</th><th>ผู้ร่วมงาน</th><th>Files</th><th>สถานะ</th><th class="row-actions"></th></tr></thead>
                            <tbody data-group-body="${listId}">
                                <tr class="empty-row"><td colspan="9"><div class="empty-row-message">ยังไม่มีงานในรายการนี้</div></td></tr>
                                <tr class="add-row"><td></td><td colspan="8"><form class="add-task-inline" action="${page.dataset.storeUrl}" method="POST"><input type="hidden" name="work_order_list_id" value="${listId}"><input type="text" name="job_topic" maxlength="255" required placeholder="+ Add project"><button type="submit">เพิ่ม</button></form></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="completed-group"><details><summary><i class="bi bi-check-circle"></i> Completed <span>0</span></summary><div class="task-table-wrap"><table class="task-table"><tbody><tr class="empty-row"><td colspan="9"><div class="empty-row-message">ยังไม่มีงานที่เสร็จแล้ว</div></td></tr></tbody></table></div></details></div>
                </div>
            </article>`;
        const group = wrapper.firstElementChild;
        board.insertBefore(group, form);
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
        modalForm.querySelector('select[name="work_order_list_id"]').innerHTML = `<option value="${listId}">งานของฉัน</option>`;
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

    document.getElementById('taskSearch')?.addEventListener('input', applyFilters);
    document.getElementById('statusFilter')?.addEventListener('change', applyFilters);
    document.getElementById('priorityFilter')?.addEventListener('change', applyFilters);

    document.querySelector('[data-open-new-task-modal]')?.addEventListener('click', () => {
        modal.hidden = false;
        modal.querySelector('input[name="job_topic"]')?.focus();
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
            const listId = await ensureListForTask(formData);
            const data = await requestJson(modalForm.action, {method:'POST', body:formData});
            appendTask(listId, {job_id:data.job_id, job_topic:formData.get('job_topic')});
            modalForm.reset();
            modal.hidden = true;
            toast.fire({icon:'success', title:'สร้างงานแล้ว'});
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
        const form = event.target.closest('.add-task-inline, .new-group-form, .subtask-inline-form, .update-inline-form, .attachment-inline-form');
        if (!form) return;
        event.preventDefault();
        try {
            if (form.classList.contains('attachment-inline-form')) {
                const input = form.querySelector('input[type="file"]');
                const files = [...(input?.files || [])];
                const existing = Number(form.dataset.existingFiles || 0);
                if (files.length === 0) {
                    Swal.fire({icon:'info', title:'ยังไม่ได้เลือกไฟล์', text:'กรุณาเลือกไฟล์ก่อนบันทึก'});
                    return;
                }
                if (existing + files.length > 5) {
                    Swal.fire({icon:'warning', title:'ไฟล์เกินจำนวน', text:'แนบไฟล์ได้สูงสุด 5 ไฟล์ต่องาน'});
                    return;
                }
                if (files.some((file) => file.size > 5 * 1024 * 1024)) {
                    Swal.fire({icon:'warning', title:'ไฟล์ใหญ่เกินไป', text:'แต่ละไฟล์ต้องไม่เกิน 5MB'});
                    return;
                }
                await requestJson(form.action, {method:'POST', body:new FormData(form)});
                toast.fire({icon:'success', title:'แนบไฟล์สำเร็จ'});
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
                const listId = await ensureListForTask(formData);
                const data = await requestJson(form.action, {method:'POST', body:formData});
                appendTask(listId, {job_id:data.job_id, job_topic:formData.get('job_topic')});
                form.reset();
                toast.fire({icon:'success', title:'เพิ่มงานแล้ว'});
                return;
            }
            await requestJson(form.action, {method:'POST', body:new FormData(form)});
            toast.fire({icon:'success', title:'บันทึกแล้ว'});
            window.location.reload();
        } catch (error) { showError(error); }
    });

    document.addEventListener('click', async (event) => {
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
            try {
                await requestJson(complete.dataset.url, {method:'PATCH', body:JSON.stringify({completed:complete.dataset.completed === '1'})});
                toast.fire({icon:'success', title:'อัปเดตงานแล้ว'});
                window.location.reload();
            } catch (error) { showError(error); }
            return;
        }

        const star = event.target.closest('[data-star-task]');
        if (star) {
            try {
                const next = star.dataset.starred === '1';
                await requestJson(star.dataset.url, {method:'PATCH', body:JSON.stringify({is_starred:next})});
                star.dataset.starred = next ? '0' : '1';
                star.classList.toggle('is-starred', next);
                star.querySelector('i')?.classList.toggle('bi-star-fill', next);
                star.querySelector('i')?.classList.toggle('bi-star', !next);
                star.closest('[data-task-row]').dataset.starred = next ? '1' : '0';
                toast.fire({icon:'success', title: next ? 'ติดดาวแล้ว' : 'ยกเลิกติดดาวแล้ว'});
                applyFilters();
            } catch (error) { showError(error); }
            return;
        }

        const deleteTask = event.target.closest('[data-delete-task]');
        if (deleteTask) {
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
                await requestJson(deleteTask.dataset.url, {method:'DELETE'});
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
            const oldValue = statusSelect.dataset.oldValue || statusSelect.value;
            try {
                await requestJson(statusSelect.dataset.url, {method:'POST', body:JSON.stringify({job_status:statusSelect.value})});
                statusSelect.className = `label-select status-label ${statusClass[statusSelect.value] || ''}`;
                statusSelect.closest('[data-task-row]').dataset.status = statusSelect.value;
                statusSelect.dataset.oldValue = statusSelect.value;
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
