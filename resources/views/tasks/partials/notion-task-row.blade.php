@php
    $isLate = (int) $task->job_status !== 4 && $task->job_due_at?->isPast();
    $statusText = $isLate ? 'ล่าช้า' : ($statusLabels[(int) $task->job_status] ?? 'ยังไม่เริ่ม');
    $statusClass = $isLate ? 'late' : match((int) $task->job_status) {2=>'progress',3=>'review',4=>'done',5=>'paused',default=>'todo'};
    $projectName = $task->taskList?->name ?? 'งานทั่วไป';
    $assigneeName = $task->user?->name ?? auth()->user()->name;
    $acceptedCollaborators = $task->collaborators->filter(fn ($person) => $person->pivot?->status === 'accepted')->values();
    $pendingCollaborators = $task->collaborators->filter(fn ($person) => $person->pivot?->status !== 'accepted')->values();
    $attachmentCount = (int) ($task->images_count ?? $task->images->count());
    $displayProgress = (int) $task->progress_from_subtasks;
    $thaiMonths = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    $dueLabel = $task->job_due_at
        ? $task->job_due_at->day.' '.$thaiMonths[$task->job_due_at->month].' '.($task->job_due_at->year + 543)
        : 'ไม่มีกำหนด';
@endphp
<div class="notion-row" data-row data-id="{{ $task->job_id }}"
    @if($task->taskList && auth()->user()->can('manage', $task->taskList))
        data-list-update-url="{{ route('mytasks.lists.update', $task->taskList) }}"
        data-list-delete-url="{{ route('mytasks.lists.destroy', $task->taskList) }}"
    @endif data-status="{{ $task->job_status }}" data-late="{{ $isLate ? 1 : 0 }}" data-list-id="{{ $task->work_order_list_id }}" data-topic="{{ $task->job_topic }}" data-details="{{ $task->job_details }}" data-project="{{ $projectName }}" data-assignee="{{ $assigneeName }}" data-priority="{{ $task->job_priority }}" data-due="{{ optional($task->job_due_at)->format('Y-m-d') }}">
    <span class="row-grip"><i class="bi bi-grip-vertical"></i></span>
    <button type="button" class="row-title" data-open-task-modal><strong title="{{ $task->job_topic }}">{{ $task->job_topic }}</strong>@if($task->job_details)<small>{{ Str::limit($task->job_details, 80) }}</small>@endif</button>
    <span class="row-project" title="{{ $projectName }}">{{ $projectName }}</span>
    <div class="select-wrapper status-{{ $statusClass }}" data-status-choice>
        <i class="choice-dot" aria-hidden="true"></i><select class="cell-select" data-field="status">
            @foreach([1=>'ยังไม่เริ่ม',2=>'กำลังทำ',3=>'รอตรวจสอบ',4=>'เสร็จแล้ว',5=>'พักงาน'] as $value=>$label)<option class="status-option-{{ $value }}" value="{{ $value }}" @selected((int)$task->job_status===$value)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <span class="row-assignee" title="ผู้รับผิดชอบหลัก: {{ $assigneeName }} · ผู้ร่วมงาน: {{ $acceptedCollaborators->merge($pendingCollaborators)->pluck('name')->join(', ') }}">
        <span class="primary-assignee"><i>{{ Str::substr($assigneeName, 0, 1) }}</i><span><small>หลัก</small>{{ $assigneeName }}</span></span>
        <span class="collaborator-stack">
            @foreach($acceptedCollaborators->take(2) as $person)<i class="collaborator-avatar" title="{{ $person->name }}">{{ Str::substr($person->name, 0, 1) }}</i>@endforeach
            @foreach($pendingCollaborators->take(max(0, 2 - $acceptedCollaborators->take(2)->count())) as $person)<i class="collaborator-avatar pending" title="{{ $person->name }} — รอตอบรับ">{{ Str::substr($person->name, 0, 1) }}<b></b></i>@endforeach
            @if($acceptedCollaborators->count() + $pendingCollaborators->count() > 2)<b class="collaborator-more">+{{ $acceptedCollaborators->count() + $pendingCollaborators->count() - 2 }}</b>@endif
        </span>
    </span>
    <label class="row-due {{ $isLate ? 'is-late' : '' }}"><span data-due-label>{{ $dueLabel }}</span><input class="cell-date" type="date" data-field="due" value="{{ optional($task->job_due_at)->format('Y-m-d') }}" aria-label="แก้ไขกำหนดส่ง"></label>
    <span class="row-progress"><i><b style="width:{{ $displayProgress }}%"></b></i><input type="number" data-field="progress" min="0" max="{{ (int)$task->job_status === 4 ? 100 : 99 }}" value="{{ $displayProgress }}" @disabled((int)$task->job_status === 4)>%</span>
    <div class="select-wrapper priority-{{ $task->job_priority }}" data-priority-choice>
        <i class="bi bi-flag-fill" aria-hidden="true"></i><select class="cell-select" data-field="priority">@foreach($priorityLabels as $value=>$label)<option class="priority-option-{{ $value }}" value="{{ $value }}" @selected((int)$task->job_priority===$value)>{{ $label }}</option>@endforeach</select>
    </div>
    <span class="row-actions">
        <details class="task-more-menu">
            <summary aria-label="เมนูจัดการงาน"><i class="bi bi-three-dots"></i></summary>
            <div>
                <button type="button" data-open-task-modal><i class="bi bi-box-arrow-up-right"></i> เปิดงาน</button>
                <button type="button" data-manage-team="{{ $task->job_id }}"><i class="bi bi-people"></i> จัดการผู้ร่วมงาน</button>
                @if($attachmentCount)<a href="{{ route('tasks.show', $task->job_id) }}#attachments"><i class="bi bi-paperclip"></i> ไฟล์แนบ <small>{{ $attachmentCount }}</small></a>@endif
                <button type="button" data-open-task-modal><i class="bi bi-pencil-square"></i> แก้ไข</button>
                @can('deleteOwn', $task)<button type="button" class="danger" data-delete-task-row data-url="{{ route('mytasks.destroy', $task->job_id) }}"><i class="bi bi-trash3"></i> ลบ</button>@endcan
            </div>
        </details>
    </span>
</div>
