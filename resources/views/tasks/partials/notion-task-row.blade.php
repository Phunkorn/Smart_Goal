@php
    $showQuickAdd = $showQuickAdd ?? true;
    $workspaceContext = $workspaceContext ?? 'user';
    $isLate = (int) $task->job_status === 6;
    $statusText = $isLate ? 'ล่าช้า' : ($statusLabels[(int) $task->job_status] ?? 'สถานะไม่รองรับ');
    $statusClass = $isLate ? 'late' : match((int) $task->job_status) {2=>'progress',3=>'review',4=>'done',5=>'paused',default=>'unsupported'};
    $projectName = $task->taskList?->name ?? 'งานทั่วไป';
    $assigneeName = $task->user?->name ?? auth()->user()->name;
    $acceptedCollaborators = $task->collaborators->filter(fn ($person) => $person->pivot?->status === 'accepted')->values();
    $pendingCollaborators = $task->collaborators->filter(fn ($person) => $person->pivot?->status !== 'accepted')->values();
    $attachmentCount = (int) ($task->images_count ?? $task->images->count());
    $thaiMonths = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    $dueLabel = $task->job_due_at
        ? $task->job_due_at->day.' '.$thaiMonths[$task->job_due_at->month].' '.str_pad((string)(($task->job_due_at->year + 543) % 100), 2, '0', STR_PAD_LEFT)
        : 'ไม่มีกำหนด';
    $startLabel = $task->job_start_at
        ? $task->job_start_at->day.' '.$thaiMonths[$task->job_start_at->month]
        : '-';
    $taskAdminSenderName = $task->creator?->role === 'admin' ? $task->creator->name : null;
    $canQuickAddToList = $showQuickAdd && $task->taskList && auth()->user()->can('manage', $task->taskList);
    $canWork = auth()->user()->can('work', $task);
    $canManageTeam = auth()->user()->can('manageTeam', $task);
    $taskDeleteUrl = $workspaceContext === 'admin-member'
        ? route('admin.tasks.destroy', $task->job_id)
        : route('mytasks.destroy', $task->job_id);
@endphp
@include('tasks.partials.task-support-source', ['task' => $task, 'adminSenderName' => $taskAdminSenderName, 'taskLinkMode' => false])
<div class="notion-row" data-row data-id="{{ $task->job_id }}"
    @if($task->taskList && auth()->user()->can('manage', $task->taskList))
        data-list-update-url="{{ route('mytasks.lists.update', $task->taskList) }}"
        data-list-delete-url="{{ route('mytasks.lists.destroy', $task->taskList) }}"
    @endif data-status="{{ $task->job_status }}" data-late="{{ $isLate ? 1 : 0 }}" data-list-id="{{ $task->work_order_list_id }}" data-list-owned="{{ $canQuickAddToList ? 1 : 0 }}" data-list-priority="{{ $task->taskList?->priority ?? 2 }}" data-topic="{{ $task->job_topic }}" data-project="{{ $projectName }}" data-assignee="{{ $assigneeName }}" data-priority="{{ $task->job_priority }}" data-start="{{ \App\Support\TodayWorkspace::calendarDate($task->job_start_at) }}" data-due="{{ \App\Support\TodayWorkspace::calendarDate($task->job_due_at) }}">
    <button type="button" class="row-title" data-open-task-modal><strong title="{{ $task->job_topic }}">{{ $task->job_topic }}</strong>@include('tasks.partials.approval-state-marker', ['task' => $task])</button>
    @php($taskPriorityClass = [1=>'routine',2=>'important',3=>'urgent',4=>'quick',5=>'flexible'][(int) $task->job_priority] ?? 'important')
    @if($canWork)
        <details class="board-status-menu table-status-menu" data-table-status-menu>
            <summary class="board-status-pill status-{{ $statusClass }}"><span data-table-status-label>{{ $statusText }}</span><i class="bi bi-chevron-down"></i></summary>
            <div>@foreach([5=>['พักงาน','paused'],2=>['กำลังทำ','progress'],3=>['รอตรวจสอบ','review'],4=>['เสร็จแล้ว','done']] as $value=>$meta)<button type="button" class="status-{{ $meta[1] }}" data-table-status-value="{{ $value }}">{{ $meta[0] }}@if((int)$task->job_status === $value)<span class="bi bi-check2"></span>@endif</button>@endforeach</div>
        </details>
        <input type="hidden" data-field="status" value="{{ $task->job_status }}">
        <details class="board-priority-menu table-priority-menu" data-table-priority-menu>
            <summary class="board-priority priority-{{ $taskPriorityClass }}"><span data-table-priority-label>{{ $priorityLabels[(int) $task->job_priority] ?? $priorityLabels[2] }}</span><i class="bi bi-chevron-down"></i></summary>
            <div>@foreach([3=>['สำคัญด่วน','urgent'],4=>['ด่วนไม่ค่อยสำคัญ','quick'],2=>['สำคัญไม่ด่วน','important'],5=>['ไม่รีบ ไม่มีกำหนด','flexible'],1=>['routine','routine']] as $value=>$meta)<button type="button" class="priority-{{ $meta[1] }}" data-table-priority-value="{{ $value }}"><i class="bi bi-flag-fill"></i>{{ $meta[0] }}@if((int)$task->job_priority === $value)<span class="bi bi-check2"></span>@endif</button>@endforeach</div>
        </details>
        <input type="hidden" data-field="priority" value="{{ $task->job_priority }}">
    @else
        <span class="board-status-pill status-{{ $statusClass }}">{{ $statusText }}</span>
        <span class="board-priority priority-{{ $taskPriorityClass }}"><i class="bi bi-flag-fill" aria-hidden="true"></i>{{ $priorityLabels[(int) $task->job_priority] ?? $priorityLabels[2] }}</span>
    @endif    <button type="button" class="row-owner" data-open-owner="{{ $task->job_id }}" title="{{ $assigneeName }}">
        <i>@include('components.user-avatar-content', ['user' => $task->user ?? auth()->user()])</i>
    </button>
    <label class="row-duration {{ $isLate ? 'is-late' : '' }} {{ $canWork ? '' : 'is-readonly' }}">
        <span class="row-duration-copy"><span>{{ $startLabel }}</span><i class="bi bi-arrow-right"></i><span data-due-label>{{ $dueLabel }}</span></span>
        @if($canWork)<input class="cell-date" type="date" data-date-picker data-field="due" value="{{ optional($task->job_due_at)->format('Y-m-d') }}" aria-label="แก้ไขกำหนดส่ง">@endif
    </label>
    <button type="button" class="row-collaborators" data-manage-team="{{ $task->job_id }}" title="{{ $canManageTeam ? 'จัดการผู้ร่วมงาน' : 'ดูผู้ร่วมงาน' }}">
        <span class="collaborator-stack">
            @foreach($acceptedCollaborators->take(3) as $person)<i class="collaborator-avatar" title="{{ $person->name }}">@include('components.user-avatar-content', ['user' => $person])</i>@endforeach
            @foreach($pendingCollaborators->take(max(0, 3 - $acceptedCollaborators->take(3)->count())) as $person)<i class="collaborator-avatar pending" title="{{ $person->name }} — รอตอบรับ">@include('components.user-avatar-content', ['user' => $person])<b></b></i>@endforeach
            @if($acceptedCollaborators->count() + $pendingCollaborators->count() > 3)<b class="collaborator-more">+{{ $acceptedCollaborators->count() + $pendingCollaborators->count() - 3 }}</b>@endif
            @if($acceptedCollaborators->isEmpty() && $pendingCollaborators->isEmpty())<i class="collaborator-empty bi bi-person-plus"></i>@endif
        </span>
    </button>
    <button type="button" class="row-files {{ $attachmentCount ? 'has-files' : '' }}" data-open-attachments="{{ $task->job_id }}" title="ไฟล์แนบ {{ $attachmentCount }} ไฟล์"><i class="bi bi-paperclip"></i><b>{{ $attachmentCount }}</b></button>
    <span class="row-actions">
        <details class="task-more-menu">
            <summary aria-label="เมนูจัดการงาน"><i class="bi bi-three-dots"></i></summary>
            <div>
                <button type="button" data-open-task-modal><i class="bi bi-box-arrow-up-right"></i> เปิดงาน</button>
                <button type="button" data-manage-team="{{ $task->job_id }}"><i class="bi bi-people"></i> {{ $canManageTeam ? 'จัดการผู้ร่วมงาน' : 'ดูผู้ร่วมงาน' }}</button>
                @if($attachmentCount)<button type="button" data-open-attachments="{{ $task->job_id }}"><i class="bi bi-paperclip"></i> ไฟล์แนบ <small>{{ $attachmentCount }}</small></button>@endif
                <button type="button" data-open-task-modal><i class="bi {{ $canWork ? 'bi-pencil-square' : 'bi-eye' }}"></i> {{ $canWork ? 'แก้ไข' : 'ดูรายละเอียด' }}</button>
                @if($workspaceContext === 'admin-member')
                    @can('delete', $task)<button type="button" class="danger" data-delete-task-row data-url="{{ $taskDeleteUrl }}"><i class="bi bi-trash3"></i> ลบ</button>@endcan
                @else
                    @can('deleteOwn', $task)<button type="button" class="danger" data-delete-task-row data-url="{{ $taskDeleteUrl }}"><i class="bi bi-trash3"></i> ลบ</button>@endcan
                @endif
            </div>
        </details>
    </span>
</div>
