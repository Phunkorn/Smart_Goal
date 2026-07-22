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
    .group-head { min-height:58px; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:9px 16px; border-left:5px solid var(--accent); border-bottom:1px solid var(--border); }
    .group-head.project-priority-high { border-left-color:#ef4444; background:#fff5f5; }
    .group-head.project-priority-medium { border-left-color:#f59e0b; background:#fffbeb; }
    .group-head.project-priority-low { border-left-color:#64748b; background:#f8fafc; }
    .group-title { display:flex; align-items:center; gap:10px; min-width:0; }
    .group-toggle { width:30px; height:30px; border:0; border-radius:50%; background:transparent; color:var(--text-muted); display:grid; place-items:center; }
    .group-name { margin:0; color:#059669; font-size:18px; font-weight:900; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .group-count { border-radius:999px; background:var(--accent-dim); color:var(--accent-strong); padding:3px 9px; font-weight:850; font-size:12px; }
    .group-summary { display:flex; flex-wrap:wrap; justify-content:flex-end; align-items:center; gap:6px 10px; color:var(--text-muted); font-size:13px; font-weight:750; text-align:right; }
    .group-meta-days, .group-meta-owner, .group-meta-admin { border-radius:999px; padding:3px 8px; font-size:12px; white-space:nowrap; }
    .group-meta-days { background:#fff; color:#b45309; border:1px solid #fde68a; }
    .group-meta-owner { background:#eff6ff; color:#1d4ed8; }
    .group-meta-admin { background:#f3e8ff; color:#7e22ce; }
    .task-table-wrap { overflow-x:auto; }
    .task-table { width:min(1286px, 100%); min-width:1040px; border-collapse:separate; border-spacing:0; table-layout:fixed; }
    .task-table th, .task-table td { border-bottom:1px solid #d9e2f0; border-right:1px solid #d9e2f0; padding:0 8px; height:38px; vertical-align:middle; background:#fff; }
    .task-table th { position:sticky; top:0; z-index:1; background:#fbfdff; color:var(--text-muted); font-size:12px; font-weight:750; text-align:left; }
    .task-table th:last-child, .task-table td:last-child { border-right:0; }
    .check-col { width:44px; text-align:center; }
    .name-col { width:250px; display:flex; align-items:center; gap:8px; }
    .task-table th:nth-child(3), .task-table td:nth-child(3) { width:180px; }
    .task-table th:nth-child(4), .task-table td:nth-child(4) { width:160px; }
    .task-table th:nth-child(5), .task-table td:nth-child(5) { width:160px; }
    .task-table th:nth-child(6), .task-table td:nth-child(6) { width:150px; }
    .task-table th:nth-child(7), .task-table td:nth-child(7) { width:100px; }
    .task-table th:nth-child(8), .task-table td:nth-child(8) { width:170px; }
    .row-actions { width:72px; white-space:nowrap; }
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
    .subtask-panel { display:grid; gap:10px; max-width:680px; margin-left:40px; border-left:2px solid #10b981; padding:2px 0 2px 20px; }
    .subtask-tree { display:grid; gap:0; }
    .subtask-tree-label { padding:0 0 7px; color:var(--text-muted); font-size:12px; font-weight:850; }
    .subtask-tree-item { position:relative; display:flex; align-items:center; gap:10px; min-height:36px; padding:0 10px; border:1px solid #d9e2f0; border-bottom:0; background:#fff; }
    .subtask-tree-label + .subtask-tree-item { border-radius:10px 10px 0 0; }
    .subtask-tree-item:last-child { border-bottom:1px solid #d9e2f0; border-radius:0 0 10px 10px; }
    .subtask-tree-item::before { content:""; position:absolute; left:-22px; top:50%; width:20px; border-top:2px solid #10b981; }
    .subtask-tree-item.is-completed .subtask-title { text-decoration:line-through; color:var(--text-muted); }
    .subtask-title { font-weight:850; }
    .subtask-details, .subtask-empty { color:var(--text-muted); font-size:13px; }
    .subtask-inline-form { display:grid; grid-template-columns:1fr auto; gap:8px; }
    .subtask-inline-form input { min-height:36px; border:1px solid var(--border); border-radius:10px; padding:0 10px; font:inherit; }
    .subtask-inline-form button { border:0; border-radius:10px; background:var(--accent-dim); color:var(--accent-strong); font-weight:850; padding:0 12px; }
    .panel-section { border:1px solid var(--border); border-radius:12px; padding:12px; background:#fff; display:grid; gap:10px; }
    .panel-section h3 { margin:0; font-size:15px; font-weight:900; }
    .update-inline-form { display:grid; grid-template-columns:1fr auto; gap:8px; align-items:start; }
    .update-inline-form textarea { border:1px solid var(--border); border-radius:10px; padding:9px 10px; font:inherit; }
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
    .create-project-card { display:inline-flex; align-items:center; justify-content:center; gap:7px; width:auto; min-height:42px; padding:0 14px; background:#fff; border:1px dashed var(--accent); border-radius:12px; color:var(--accent-strong); font:inherit; font-size:.92rem; font-weight:850; cursor:pointer; transition:.18s ease; }
    .create-project-card:hover { background:var(--accent-dim); border-style:solid; transform:translateY(-1px); }
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
    .full-task-card { width:min(560px, 100%); max-height:92vh; display:flex; flex-direction:column; }
    .full-task-card .simple-modal-body { overflow-y:auto; }
    .task-activity-trigger { position:relative; }
    .task-activity-badge { position:absolute; top:-6px; right:-7px; min-width:17px; height:17px; padding:0 4px; border:2px solid #fff; border-radius:999px; background:#ef4444; color:#fff; font-size:10px; font-weight:900; line-height:13px; text-align:center; }
    .task-activity-modal { z-index:90; }
    .task-activity-tabs { display:flex; gap:4px; padding:10px 14px 0; border-bottom:1px solid var(--border); overflow-x:auto; }
    .task-activity-tabs button { flex:0 0 auto; border:0; border-bottom:2px solid transparent; background:transparent; color:var(--text-muted); padding:9px 8px; font:inherit; font-size:13px; font-weight:850; }
    .task-activity-tabs button.is-active { color:var(--accent-strong); border-bottom-color:var(--accent); }
    .task-activity-tabs span { display:inline-grid; place-items:center; min-width:18px; height:18px; border-radius:999px; background:var(--surface-2); font-size:11px; }
    .task-activity-body { min-height:260px; }
    .task-activity-body section { display:grid; gap:12px; }
    .board-tabs { display:flex; gap:8px; border-bottom:1px solid var(--border); }
    .board-tab { border:0; border-bottom:3px solid transparent; background:transparent; padding:10px 14px; color:var(--text-muted); font:inherit; font-weight:850; }
    .board-tab.is-active { color:var(--accent-strong); border-bottom-color:var(--accent); }
    .board-tab-count { display:inline-grid; place-items:center; min-width:20px; height:20px; margin-left:4px; border-radius:999px; background:var(--accent-dim); font-size:12px; }
    .simple-field textarea { min-height:70px; border:1px solid var(--border); border-radius:12px; padding:10px 12px; font:inherit; resize:vertical; background:#fff; }
    .simple-field-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .field-optional { font-weight:650; color:var(--text-muted); font-size:12px; }
    .field-hint { color:var(--text-muted); font-weight:650; font-size:12px; }
    .field-hint.is-warning { color:#b45309; }
    .project-item-list { display:grid; gap:12px; }
    .project-item-card { display:grid; gap:10px; padding:12px; border:1px solid var(--border); border-radius:14px; background:#f8fafc; }
    .project-item-head { display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .project-item-title { font-weight:900; color:var(--text); }
    .tiny-icon-btn { width:34px; height:34px; border:1px solid var(--border); border-radius:10px; background:#fff; color:var(--text-muted); display:inline-grid; place-items:center; }
    .tiny-icon-btn.danger { color:#dc2626; }
    .initial-subtask-list { display:grid; gap:8px; }
    .initial-subtask-row { display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:8px; align-items:start; }
    .initial-subtask-row input { min-height:38px; border:1px solid var(--border); border-radius:10px; padding:0 10px; font:inherit; background:#fff; }
    .inline-add-btn { min-height:36px; border:1px dashed var(--accent); border-radius:10px; background:#eff6ff; color:var(--accent-strong); font-weight:850; display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:0 12px; justify-self:start; }
    @media (max-width:520px) { .simple-field-row { grid-template-columns:1fr; } }
    @media (max-width:720px) { .initial-subtask-row { grid-template-columns:1fr auto; } }

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

    .tasks-page {
        --pulse-bg:#F7F9FC;
        --pulse-card:#FFFFFF;
        --pulse-primary:#0073EA;
        --pulse-primary-soft:#E8F1FE;
        --pulse-green:#00C875;
        --pulse-red:#E2445C;
        --pulse-red-soft:#FCE9EC;
        --pulse-amber:#FDAB3D;
        --pulse-text:#172B4D;
        --pulse-muted:#5E6C84;
        --pulse-light:#98A2B3;
        --pulse-border:#EDEFF5;
        display:grid;
        gap:20px;
        color:var(--pulse-text);
    }

    .pulse-hero,
    .task-group {
        background:var(--pulse-card);
        border:1px solid var(--pulse-border);
        border-radius:18px;
        box-shadow:0 4px 12px rgba(23,43,77,.06), 0 2px 4px rgba(23,43,77,.04);
        overflow:hidden;
    }

    .pulse-hero {
        padding:26px 32px;
    }

    .pulse-hero-top {
        display:flex;
        justify-content:space-between;
        gap:18px;
        align-items:flex-start;
        padding-bottom:22px;
        border-bottom:1px solid var(--pulse-border);
    }

    .pulse-title-block {
        display:flex;
        align-items:center;
        gap:15px;
        min-width:0;
    }

    .pulse-project-icon {
        width:52px;
        height:52px;
        border-radius:14px;
        display:grid;
        place-items:center;
        color:#fff;
        font-weight:900;
        letter-spacing:.02em;
        background:linear-gradient(135deg, #0073EA 0%, #00C2E0 100%);
        box-shadow:0 6px 16px rgba(0,115,234,.28);
        flex:0 0 auto;
    }

    .tasks-title h1 {
        margin:0;
        font-size:24px;
        font-weight:900;
        letter-spacing:0;
        color:var(--pulse-text);
    }

    .tasks-title p {
        margin:4px 0 0;
        color:var(--pulse-muted);
        font-size:13.5px;
        font-weight:650;
    }

    .pulse-actions {
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:10px;
        flex-wrap:wrap;
    }

    .tasks-toolbar {
        display:flex;
        gap:10px;
        align-items:center;
        flex-wrap:wrap;
        background:transparent;
        border:0;
        border-radius:0;
        padding:0;
        box-shadow:none;
    }

    .tool-field,
    .tool-btn,
    .create-project-card {
        min-height:40px;
        border-radius:10px;
        border:1px solid var(--pulse-border);
        background:#fff;
        color:var(--pulse-text);
        font-weight:800;
        box-shadow:none;
    }

    .tool-field {
        padding:0 12px;
        background:#F7F9FC;
    }

    .tool-field:focus-within {
        background:#fff;
        border-color:var(--pulse-primary);
        box-shadow:0 0 0 3px var(--pulse-primary-soft);
    }

    .tool-field input,
    .tool-field select {
        font-size:13px;
        color:var(--pulse-text);
    }

    .tool-btn {
        padding:0 13px;
    }

    .tool-btn:hover,
    .create-project-card:hover {
        background:#F5F7FB;
        transform:translateY(-1px);
        box-shadow:0 1px 2px rgba(23,43,77,.05);
    }

    .create-project-card {
        border:0;
        background:linear-gradient(135deg, #0073EA, #0091FF);
        color:#fff;
        padding:0 17px;
        box-shadow:0 4px 12px rgba(0,115,234,.28);
    }

    .create-project-card:hover {
        color:#fff;
        background:linear-gradient(135deg, #0067D3, #0089F2);
        box-shadow:0 6px 18px rgba(0,115,234,.34);
    }

    .pulse-meta {
        display:grid;
        grid-template-columns:repeat(4, minmax(140px, 1fr));
        gap:24px;
        padding-top:18px;
    }

    .pulse-meta-item {
        display:grid;
        gap:7px;
        min-width:0;
    }

    .pulse-meta-label,
    .table-head th,
    .expand-col h4 {
        color:var(--pulse-light);
        font-size:11px;
        font-weight:900;
        text-transform:uppercase;
        letter-spacing:.08em;
    }

    .pulse-meta-value {
        color:var(--pulse-text);
        font-size:15px;
        font-weight:900;
    }

    .pulse-progress {
        display:flex;
        align-items:center;
        gap:10px;
    }

    .pulse-progress-track,
    .progress-track {
        height:9px;
        border-radius:999px;
        background:#EEF0F5;
        overflow:hidden;
    }

    .pulse-progress-track {
        width:120px;
        flex:0 0 120px;
    }

    .pulse-progress-track span,
    .progress-track span {
        display:block;
        height:100%;
        border-radius:999px;
        background:linear-gradient(90deg, #0073EA 0%, #12C2E9 55%, #00C875 100%);
    }

    .board-tabs {
        display:flex;
        gap:8px;
        border-bottom:0;
    }

    .board-tab {
        border:1px solid var(--pulse-border);
        border-radius:12px;
        background:#fff;
        padding:10px 14px;
        color:var(--pulse-muted);
        font-weight:900;
    }

    .board-tab.is-active {
        color:var(--pulse-primary);
        border-color:#CFE0FF;
        background:var(--pulse-primary-soft);
    }

    .board-tab-count {
        background:#fff;
        color:inherit;
    }

    .task-board {
        display:grid;
        gap:18px;
    }

    .group-head {
        min-height:auto;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:18px;
        padding:18px 22px;
        border-left:0;
        border-bottom:1px solid var(--pulse-border);
        background:#FAFBFD;
    }

    .group-head.project-priority-high,
    .group-head.project-priority-medium,
    .group-head.project-priority-low {
        background:#FAFBFD;
        border-left:0;
    }

    .group-title {
        display:flex;
        align-items:center;
        gap:11px;
    }

    .group-action-btn {
        width:30px;
        height:30px;
        border:1px solid var(--pulse-border);
        border-radius:9px;
        background:#fff;
        color:var(--pulse-muted);
        display:grid;
        place-items:center;
    }

    .group-action-btn:hover {
        background:var(--pulse-primary-soft);
        color:var(--pulse-primary);
    }

    .group-action-btn.danger:hover {
        background:var(--pulse-red-soft);
        color:var(--pulse-red);
    }

    .group-rename-form {
        display:flex;
        align-items:center;
        gap:6px;
        min-width:min(360px, 100%);
    }

    .group-rename-form[hidden] {
        display:none;
    }

    .group-rename-form input {
        min-height:34px;
        border:1px solid var(--pulse-border);
        border-radius:9px;
        padding:0 10px;
        font:inherit;
        font-size:13px;
        font-weight:800;
    }

    .group-rename-form button {
        width:34px;
        height:34px;
        border:0;
        border-radius:9px;
        background:var(--pulse-primary);
        color:#fff;
    }

    .group-rename-form button[type="button"] {
        background:#EEF0F5;
        color:var(--pulse-muted);
    }

    .group-toggle {
        width:32px;
        height:32px;
        border-radius:10px;
        background:#fff;
        border:1px solid var(--pulse-border);
        color:var(--pulse-primary);
    }

    .group-name {
        color:var(--pulse-text);
        font-size:17px;
        font-weight:900;
        letter-spacing:0;
    }

    .group-count,
    .group-meta-days,
    .group-meta-owner,
    .group-meta-admin {
        border-radius:999px;
        padding:5px 9px;
        border:0;
        font-size:12px;
        font-weight:900;
    }

    .group-count {
        background:var(--pulse-primary-soft);
        color:var(--pulse-primary);
    }

    .group-summary {
        color:var(--pulse-muted);
        font-size:12.5px;
        font-weight:800;
    }

    .task-table-wrap {
        overflow-x:auto;
    }

    .task-table {
        width:100%;
        min-width:1040px;
        border-collapse:separate;
        border-spacing:0;
        table-layout:fixed;
    }

    .task-table th,
    .task-table td {
        border:0;
        border-bottom:1px solid #F0F2F7;
        background:#fff;
        padding:13px 10px;
        height:auto;
        vertical-align:middle;
    }

    .task-table th {
        position:static;
        background:#FAFBFD;
        color:var(--pulse-light);
        font-size:11px;
        font-weight:900;
        text-transform:uppercase;
        letter-spacing:.08em;
    }

    .task-row {
        transition:background .16s ease, box-shadow .16s ease;
    }

    .task-row:hover td {
        background:#F7FAFF;
    }

    .check-col {
        width:46px;
        text-align:center;
    }

    .name-col {
        width:280px;
    }

    .task-table th:nth-child(3),
    .task-table td:nth-child(3) {
        width:130px;
    }

    .task-table th:nth-child(4),
    .task-table td:nth-child(4) {
        width:150px;
    }

    .task-table th:nth-child(5),
    .task-table td:nth-child(5) {
        width:138px;
    }

    .task-table th:nth-child(6),
    .task-table td:nth-child(6) {
        width:145px;
    }

    .task-table th:nth-child(7),
    .task-table td:nth-child(7) {
        width:82px;
    }

    .task-table th:nth-child(8),
    .task-table td:nth-child(8) {
        width:130px;
    }

    .row-actions {
        width:88px;
        white-space:nowrap;
        text-align:right;
    }

    .task-check {
        width:20px;
        height:20px;
        border-radius:6px;
        border:2px solid #D9DEE8;
        background:#fff;
        color:transparent;
        display:inline-grid;
        place-items:center;
        transition:.15s ease;
    }

    .task-check.is-on {
        background:linear-gradient(135deg, #0073EA, #00C2E0);
        border-color:transparent;
        color:#fff;
    }

    .expand-task {
        width:28px;
        height:28px;
        border-radius:9px;
        border:0;
        background:transparent;
        color:var(--pulse-light);
        display:grid;
        place-items:center;
        flex:0 0 auto;
    }

    .expand-task:hover {
        background:var(--pulse-primary-soft);
        color:var(--pulse-primary);
    }

    .task-title-text {
        font-size:13.5px;
        font-weight:900;
        color:var(--pulse-text);
        white-space:normal;
        line-height:1.25;
    }

    .task-detail-line {
        color:var(--pulse-light);
        font-size:12px;
        font-weight:650;
        margin-top:2px;
    }

    .priority-label,
    .status-label,
    .due-input {
        width:auto;
        min-height:30px;
        border:0;
        border-radius:999px;
        padding:0 11px;
        text-align:center;
        font-size:12px;
        font-weight:900;
        appearance:none;
    }

    .priority-low { background:#E4F7ED; color:#0F9D58; }
    .priority-medium { background:#FFF6DA; color:#B9911B; }
    .priority-high { background:#FFEEDD; color:#C86A16; }
    .status-working { background:var(--pulse-primary-soft); color:var(--pulse-primary); }
    .status-done { background:#E1F7EC; color:#00A35C; }
    .status-paused { background:#EEF0F5; color:#676C7A; }
    .status-overdue,
    .due-input.overdue { background:var(--pulse-red-soft); color:var(--pulse-red); }
    .due-input.soon { background:#FFF6DA; color:#B9911B; }

    .progress-cell {
        border:0;
        background:transparent;
        width:100%;
        display:grid;
        grid-template-columns:1fr auto;
        align-items:center;
        gap:8px;
        color:var(--pulse-muted);
        font-size:12px;
        font-weight:900;
        text-align:left;
    }

    .avatar-stack,
    .pulse-member-stack {
        display:flex;
        align-items:center;
    }

    .avatar-dot,
    .avatar-more,
    .pulse-avatar,
    .pulse-avatar-more {
        width:29px;
        height:29px;
        border-radius:50%;
        border:2px solid #fff;
        margin-left:-8px;
        display:grid;
        place-items:center;
        color:#fff;
        font-size:10px;
        font-weight:900;
        box-shadow:0 2px 6px rgba(23,43,77,.14);
    }

    .avatar-dot:first-child,
    .pulse-avatar:first-child {
        margin-left:0;
    }

    .avatar-dot.muted {
        background:#64748B;
    }

    .avatar-more,
    .pulse-avatar-more {
        background:#EDEFF5;
        color:var(--pulse-muted);
    }

    .avatar-add {
        width:29px;
        height:29px;
        margin-left:4px;
        border-radius:50%;
        border:1px solid var(--pulse-border);
        background:#fff;
        color:var(--pulse-primary);
        display:grid;
        place-items:center;
    }

    .file-pill {
        display:inline-flex;
        align-items:center;
        gap:5px;
        border-radius:999px;
        background:#F2F5FA;
        color:var(--pulse-muted);
        padding:5px 9px;
        font-size:12px;
        font-weight:900;
    }

    .icon-row-btn {
        width:32px;
        height:32px;
        border:0;
        border-radius:9px;
        background:#fff;
        color:var(--pulse-muted);
    }

    .icon-row-btn:hover {
        background:var(--pulse-primary-soft);
        color:var(--pulse-primary);
    }

    .icon-row-btn.danger:hover {
        background:var(--pulse-red-soft);
        color:var(--pulse-red);
    }

    .member-avatar-btn,
    .file-pill-button {
        cursor:pointer;
        border:0;
        font:inherit;
    }

    .member-avatar-btn:hover,
    .file-pill-button:hover {
        transform:translateY(-1px);
        box-shadow:0 4px 10px rgba(23,43,77,.16);
    }

    .member-info-modal,
    .file-list-modal {
        z-index:92;
    }

    .member-info-card {
        width:min(360px, 100%);
        padding:22px;
        position:relative;
    }

    .member-modal-close {
        position:absolute;
        top:10px;
        right:12px;
    }

    .member-profile {
        display:flex;
        align-items:center;
        gap:16px;
        min-width:0;
    }

    .member-profile-avatar {
        width:72px;
        height:72px;
        border-radius:20px;
        display:grid;
        place-items:center;
        color:#fff;
        font-size:20px;
        font-weight:900;
        overflow:hidden;
        flex:0 0 auto;
        box-shadow:0 8px 20px rgba(23,43,77,.18);
    }

    .member-profile-avatar img {
        width:100%;
        height:100%;
        object-fit:cover;
    }

    .member-profile h2 {
        margin:0 0 6px;
        font-size:20px;
        font-weight:900;
        color:var(--pulse-text);
    }

    .member-profile p {
        margin:3px 0;
        color:var(--pulse-muted);
        font-size:13px;
        font-weight:750;
    }

    .attachment-icon-drop {
        border:1px dashed var(--pulse-primary);
        border-radius:12px;
        background:var(--pulse-primary-soft);
        color:var(--pulse-primary);
        padding:12px;
        display:grid;
        gap:5px;
        font-weight:900;
        cursor:pointer;
    }

    .attachment-icon-drop input,
    .file-icon-button input {
        margin-top:6px;
    }

    .attachment-icon-drop small,
    .compact-file-form small {
        color:var(--pulse-muted);
        font-size:12px;
        font-weight:700;
        line-height:1.35;
    }

    .compact-comment-form {
        grid-template-columns:1fr 34px;
        align-items:stretch;
        gap:6px;
    }

    .compact-comment-form textarea {
        min-height:42px;
        max-height:72px;
        border-radius:9px;
        padding:8px 10px;
        font-size:12px;
    }

    .compact-file-form {
        display:grid;
        grid-template-columns:34px 1fr 34px;
        align-items:center;
        gap:6px;
    }

    .file-icon-button {
        width:34px;
        height:34px;
        border-radius:9px;
        display:grid;
        place-items:center;
        background:var(--pulse-primary-soft);
        color:var(--pulse-primary);
        cursor:pointer;
        overflow:hidden;
        position:relative;
    }

    .file-icon-button input {
        position:absolute;
        inset:0;
        opacity:0;
        cursor:pointer;
    }

    .compact-file-form small {
        display:block;
        max-width:100%;
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }

    .subtask-panel .update-list,
    .subtask-panel .activity-list,
    .subtask-panel .attachment-list {
        gap:6px;
    }

    .subtask-panel .update-item,
    .subtask-panel .activity-item,
    .subtask-panel .attachment-item {
        line-height:1.35;
    }

    .subtask-panel .update-item span,
    .subtask-panel .activity-item span {
        margin-top:1px;
        font-size:11px;
    }

    .subtask-row td {
        background:#fff;
        padding:0 20px 16px;
    }

    .subtask-panel {
        max-width:none;
        margin-left:26px;
        border:1px solid var(--pulse-border);
        border-radius:14px;
        padding:12px 14px;
        background:#FAFBFD;
        display:grid;
        grid-template-columns:minmax(210px, 1fr) minmax(230px, 1.05fr) minmax(210px, .95fr) minmax(230px, 1.05fr);
        gap:14px;
    }

    .subtask-tree,
    .panel-section {
        border:0;
        border-radius:0;
        padding:0;
        background:transparent;
        display:grid;
        align-content:start;
        gap:7px;
    }

    .subtask-tree-label,
    .panel-section h3 {
        margin:0 0 2px;
        color:var(--pulse-light);
        font-size:10px;
        font-weight:900;
        text-transform:uppercase;
        letter-spacing:.08em;
    }

    .subtask-tree-item,
    .update-item,
    .activity-item,
    .attachment-item {
        border:0;
        border-radius:0;
        padding:0;
        background:transparent;
        color:var(--pulse-muted);
        font-size:11.5px;
        font-weight:700;
    }

    .subtask-tree-item::before {
        display:none;
    }

    .subtask-empty {
        color:var(--pulse-muted);
        font-size:11.5px;
        font-weight:650;
    }

    .subtask-panel .task-check.small {
        width:16px;
        height:16px;
        border-radius:5px;
        font-size:9px;
    }

    .subtask-panel .subtask-tree-item {
        min-height:24px;
        gap:7px;
    }

    .subtask-panel .subtask-inline-form {
        display:grid;
        grid-template-columns:1fr 34px;
        gap:6px;
    }

    .subtask-panel .subtask-inline-form input {
        min-height:32px;
        border:1px solid var(--pulse-border);
        border-radius:9px;
        padding:0 10px;
        font:inherit;
        font-size:12px;
    }

    .subtask-panel .subtask-inline-form button,
    .compact-comment-form button,
    .compact-file-form button {
        width:34px;
        min-width:34px;
        height:34px;
        min-height:34px;
        padding:0;
        display:grid;
        place-items:center;
        border-radius:9px;
    }

    .subtask-panel .subtask-inline-form button {
        font-size:0;
    }

    .subtask-panel .subtask-inline-form button i {
        font-size:14px;
    }

    .add-row td {
        background:#FAFBFD;
    }

    .add-task-inline input {
        min-height:38px;
        border:1px solid var(--pulse-border);
        border-radius:10px;
    }

    .add-task-inline button,
    .subtask-inline-form button,
    .update-inline-form button,
    .attachment-inline-form button,
    .primary-btn {
        border:0;
        border-radius:10px;
        background:var(--pulse-primary);
        color:#fff;
        font-weight:900;
    }

    .page-empty,
    .empty-row-message {
        color:var(--pulse-muted);
        padding:30px;
        text-align:center;
    }

    @media (max-width:1100px) {
        .pulse-hero-top {
            flex-direction:column;
        }
        .pulse-actions {
            width:100%;
            justify-content:flex-start;
        }
        .pulse-meta {
            grid-template-columns:repeat(2, minmax(150px, 1fr));
        }
        .subtask-panel {
            grid-template-columns:1fr 1fr;
        }
    }

    @media (max-width:900px) {
        .pulse-hero {
            padding:20px;
        }
        .pulse-meta {
            grid-template-columns:1fr;
        }
        .task-table,
        .task-table tbody,
        .task-table tr,
        .task-table td {
            display:block;
            min-width:0;
            width:100%;
        }
        .task-table thead {
            display:none;
        }
        .task-table tr.task-row {
            padding:12px 14px;
            border-bottom:1px solid var(--pulse-border);
        }
        .task-table td {
            border:0;
            padding:7px 0;
        }
        .task-table td::before {
            content:attr(data-label);
            display:block;
            color:var(--pulse-light);
            font-size:11px;
            font-weight:900;
            text-transform:uppercase;
            letter-spacing:.06em;
            margin-bottom:4px;
        }
        .check-col::before,
        .row-actions::before {
            display:none !important;
        }
        .name-col {
            display:flex !important;
            width:100%;
        }
        .subtask-panel {
            margin-left:0;
            grid-template-columns:1fr;
        }
    }

    .subtask-row td {
        padding:0 12px 10px;
    }

    .subtask-panel {
        margin-left:18px;
        padding:9px 12px;
        gap:10px;
        grid-template-columns:minmax(240px, 1.1fr) minmax(180px, .82fr) minmax(170px, .72fr) minmax(220px, 1fr);
    }

    .subtask-panel .subtask-tree,
    .subtask-panel .panel-section {
        gap:6px;
    }

    .subtask-panel .subtask-inline-form {
        grid-template-columns:minmax(150px, 1fr) 28px;
        gap:6px;
    }

    .subtask-panel .subtask-inline-form input {
        min-height:28px;
        border-radius:8px;
        padding:0 8px;
        font-size:11.5px;
    }

    .subtask-panel .subtask-inline-form button,
    .compact-comment-form button,
    .compact-file-form button {
        width:28px;
        min-width:28px;
        height:28px;
        min-height:28px;
        border-radius:8px;
        padding:0;
        font-size:12px;
    }

    .compact-comment-form {
        grid-template-columns:minmax(150px, 1fr) 28px;
        align-items:start;
        gap:6px;
    }

    .compact-comment-form textarea {
        width:100%;
        min-height:30px;
        height:30px;
        max-height:44px;
        border-radius:8px;
        padding:6px 8px;
        font-size:11.5px;
        line-height:1.25;
        resize:vertical;
    }

    .subtask-panel .panel-section h3,
    .subtask-panel .subtask-tree-label {
        margin-bottom:3px;
    }

    .compact-file-form {
        grid-template-columns:28px minmax(110px, 1fr) 28px;
        gap:6px;
    }

    .file-icon-button {
        width:28px;
        height:28px;
        border-radius:8px;
        font-size:12px;
    }

    .compact-file-form small {
        font-size:10.5px;
        line-height:1.25;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }

    .subtask-panel .task-check.small {
        width:14px;
        height:14px;
        border-radius:4px;
    }

    @media (max-width:1200px) {
        .subtask-panel {
            grid-template-columns:1fr 1fr;
        }
    }

    .task-table {
        table-layout:fixed;
        width:100%;
        min-width:1180px;
    }

    .task-table col.col-check { width:44px; }
    .task-table col.col-name { width:42%; }
    .task-table col.col-priority { width:11%; }
    .task-table col.col-progress { width:14%; }
    .task-table col.col-due { width:14%; }
    .task-table col.col-status { width:11%; }
    .task-table col.col-actions { width:74px; }

    .task-table th,
    .task-table td {
        box-sizing:border-box;
    }

    .task-table th.name-col {
        display:table-cell !important;
        width:auto;
    }

    .task-table td.name-col {
        display:flex;
        width:auto;
    }

    .task-table th:nth-child(n),
    .task-table td:nth-child(n),
    .check-col,
    .name-col,
    .row-actions {
        width:auto;
    }

    .row-actions {
        text-align:left;
        white-space:nowrap;
    }

    .row-actions > .icon-row-btn {
        vertical-align:middle;
    }

    .simple-modal,
    .simple-modal-card,
    .task-activity-modal,
    .task-activity-modal * {
        text-align:left;
    }

    .row-actions .simple-modal,
    .row-actions .simple-modal-card {
        white-space:normal;
    }

    .task-activity-tabs {
        justify-content:flex-start;
    }

    .task-activity-body,
    .task-activity-body section,
    .update-list,
    .attachment-list,
    .activity-list {
        justify-items:stretch;
        align-items:stretch;
    }

    .task-activity-modal .update-item,
    .task-activity-modal .activity-item,
    .task-activity-modal .attachment-item {
        text-align:left;
    }

    .task-activity-modal .full-task-card {
        width:min(620px, calc(100vw - 28px));
    }

    .task-activity-modal .simple-modal-body,
    .task-activity-modal [data-task-activity-panel],
    .task-activity-modal .update-inline-form,
    .task-activity-modal .attachment-inline-form,
    .task-activity-modal .attachment-drop {
        width:100%;
        max-width:100%;
        white-space:normal;
        justify-self:stretch;
    }

    .task-activity-modal .update-inline-form {
        grid-template-columns:minmax(0, 1fr) auto;
    }

    .task-activity-modal .attachment-inline-form {
        grid-template-columns:minmax(0, 1fr) auto;
        align-items:center;
    }

    .task-activity-modal .attachment-drop {
        padding:10px 12px;
    }

    .task-activity-modal .attachment-drop input {
        max-width:100%;
    }

    .task-activity-modal .update-item,
    .task-activity-modal .activity-item,
    .task-activity-modal .attachment-item {
        display:block;
        width:100%;
        white-space:normal;
    }

    .task-table .progress-cell,
    .task-table .members-cell,
    .task-table .status-cell {
        overflow:visible;
    }

    .task-table .name-col {
        min-width:0;
    }

    .task-table .task-name-wrap,
    .task-table .task-title-line,
    .task-table .task-title-text {
        min-width:0;
        max-width:100%;
    }

    .task-table .task-title-text {
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }

    .group-team {
        display:flex;
        align-items:center;
        gap:8px;
        min-width:0;
    }

    .group-team-label {
        color:var(--pulse-text);
        font-size:12px;
        font-weight:900;
        white-space:nowrap;
    }

    .group-member-stack .avatar-dot.is-leader {
        outline:2px solid #F59E0B;
        outline-offset:1px;
    }

    .task-check,
    .task-check.small {
        padding:0;
        line-height:1;
        place-items:center;
        align-items:center;
        justify-items:center;
    }

    .task-check i,
    .task-check.small i {
        display:block;
        line-height:1;
        font-size:13px;
        transform:none;
    }

    .task-check.small i {
        font-size:10px;
    }
</style>
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
                        <span class="pulse-avatar" style="background:#94A3B8;">{{ Str::of(auth()->user()->name)->substr(0, 2)->upper() }}</span>
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
    <form class="simple-modal-card full-task-card" id="newTaskForm" action="{{ route('mytasks.create') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="simple-modal-head">
            <div>
                <h2>เพิ่มโปรเจกต์</h2>
                <p>ตั้งชื่อโปรเจกต์ แล้วเพิ่มรายการงานและงานย่อยในโปรเจกต์นี้</p>
            </div>
            <button type="button" class="simple-modal-close" data-close-new-task-modal aria-label="ปิด">&times;</button>
        </div>
        <div class="simple-modal-body">
            <label class="simple-field">
                ชื่อโปรเจกต์
                <input type="text" name="project_name" maxlength="80" required placeholder="เช่น โปรเจกต์ออกแบบ Dashboard">
            </label>
            <div class="simple-field">
                รายการงานในโปรเจกต์
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
            <div class="simple-field-row">
                <label class="simple-field">
                    วันที่เริ่มงาน
                    <input type="date" name="job_start_at" data-newtask-start required>
                </label>
                <label class="simple-field">
                    วันที่สิ้นสุดงาน
                    <input type="date" name="job_due_at" data-newtask-due required>
                </label>
            </div>
            <label class="simple-field">
                ความสำคัญ
                <select name="job_priority">
                    <option value="1">ไม่สำคัญ/ทั่วไป</option>
                    <option value="2" selected>สำคัญ/ไม่ด่วน</option>
                    <option value="3">ด่วน/สำคัญมาก</option>
                </select>
            </label>
            <label class="simple-field">
                ไฟล์ตัวอย่าง / ไฟล์อ้างอิงงาน <span class="field-optional">(ไม่บังคับ)</span>
                <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx" data-newtask-attachments>
                <small class="field-hint">ใช้แนบโจทย์งาน ไฟล์ตัวอย่าง รูปแบบที่อยากได้ หรือเอกสารประกอบ — ไฟล์ละไม่เกิน 10MB สูงสุด 5 ไฟล์</small>
            </label>
            <div class="simple-actions">
                <button type="button" class="secondary-btn" data-close-new-task-modal>ยกเลิก</button>
                <button type="submit" class="primary-btn">เพิ่มโปรเจกต์</button>
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
    const statusText = {2:'สถานะ', 4:'เสร็จสิ้น', 5:'พักงาน'};
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
                <td data-label="ความคืบหน้า"><button type="button" class="progress-cell" data-expand-task="${id}"><span class="progress-track"><span style="width:0%"></span></span><strong>0%</strong></button></td>
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
