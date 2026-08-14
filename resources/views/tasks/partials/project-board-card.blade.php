@php
    $projectTasks = $allTasks->where('work_order_list_id', $list->id)->values();
    $projectProgress = $projectTasks->count() ? (int) round($projectTasks->avg(fn ($task) => $task->progress_from_subtasks)) : 0;
    $adminAssigned = $projectTasks->contains(fn ($task) => $task->creator?->role === 'admin');
    $members = $projectTasks->flatMap(function ($task) {
        return collect([$task->user, $task->leader])
            ->merge($task->collaborators->filter(fn ($person) => $person->pivot?->status === 'accepted'));
    })->filter()->unique('id')->values();
@endphp
@if($projectTasks->isNotEmpty())
<article class="project-board-card" data-project-card data-project-list-id="{{ $list->id }}" data-project-name="{{ $list->name }}">
    <header>
        <div class="board-project-title"><span class="board-folder"><i class="bi bi-folder2"></i></span><div><h2>{{ $list->name }}</h2><small>{{ $projectTasks->count() }} รายการ · คืบหน้า {{ $projectProgress }}%</small></div></div>
        <div class="board-project-actions">
            <button type="button" data-add-in-group data-list-id="{{ $list->id }}" title="เพิ่มรายการ"><i class="bi bi-plus-lg"></i></button>
            @can('manage', $list)
                <button type="button" data-edit-project data-name="{{ $list->name }}" data-url="{{ route('mytasks.lists.update', $list) }}" title="แก้ไขชื่อ"><i class="bi bi-pencil"></i></button>
                <button type="button" class="danger" data-delete-project data-name="{{ $list->name }}" data-url="{{ route('mytasks.lists.destroy', $list) }}" title="ลบโปรเจกต์"><i class="bi bi-trash3"></i></button>
            @endcan
        </div>
    </header>
    <div class="board-project-meta">
        @if($adminAssigned)<span class="admin-assigned"><i class="bi bi-shield-check"></i> งานที่ Admin มอบหมาย</span>@endif
        <div class="board-members" title="สมาชิกในโปรเจกต์">
            <span class="member-label"><i class="bi bi-people"></i> สมาชิก {{ $members->count() }}</span>
            <span class="member-stack">@foreach($members->take(5) as $person)<i title="{{ $person->name }}">{{ Str::substr($person->name, 0, 1) }}</i>@endforeach @if($members->count()>5)<b>+{{ $members->count()-5 }}</b>@endif</span>
        </div>
    </div>
    <div class="board-project-progress"><i><b style="width:{{ $projectProgress }}%"></b></i><span>{{ $projectProgress }}%</span></div>
    <div class="board-task-list">
        @foreach($projectTasks as $task)
            @php
                $taskIsLate = (int)$task->job_status !== 4 && $task->job_due_at?->isPast();
                $taskStatus = [1=>'ยังไม่เริ่ม',2=>'กำลังทำ',3=>'รอตรวจสอบ',4=>'เสร็จแล้ว',5=>'พักงาน'][(int)$task->job_status] ?? 'ยังไม่เริ่ม';
            @endphp
            <section class="board-task-card" data-board-task data-task-id="{{ $task->job_id }}" data-status="{{ $task->job_status }}" data-late="{{ $taskIsLate ? 1 : 0 }}">
                <button type="button" class="board-task-main" data-board-open-task="{{ $task->job_id }}">
                    <span><strong>{{ $task->job_topic }}</strong><small>{{ Str::limit($task->job_details ?: 'ยังไม่มีรายละเอียดงาน', 72) }}</small></span>
                    <i class="bi bi-chevron-right"></i>
                </button>
                <div class="board-task-info">
                    <span class="board-status status-{{ (int)$task->job_status }}">{{ $taskIsLate ? 'ล่าช้า' : $taskStatus }}</span>
                    <span><i class="bi bi-calendar3"></i> {{ optional($task->job_due_at)->format('d/m/Y') ?: 'ไม่มีกำหนด' }}</span>
                    <span><i class="bi bi-person"></i> {{ $task->user?->name ?? 'ไม่ระบุ' }}</span>
                    @if($task->creator?->role==='admin')<span class="task-admin"><i class="bi bi-shield-check"></i> Admin</span>@endif
                </div>
            </section>
        @endforeach
    </div>
</article>
@endif
