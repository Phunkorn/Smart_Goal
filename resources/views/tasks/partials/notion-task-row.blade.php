@php
    $isLate = (int) $task->job_status !== 4 && $task->job_due_at?->isPast();
    $statusText = $isLate ? 'ล่าช้า' : ($statusLabels[(int) $task->job_status] ?? 'ยังไม่เริ่ม');
    $statusClass = $isLate ? 'late' : match((int) $task->job_status) {2=>'progress',3=>'review',4=>'done',5=>'paused',default=>'todo'};
    $projectName = $task->taskList?->name ?? 'งานทั่วไป';
    $assigneeName = $task->user?->name ?? auth()->user()->name;
@endphp
<div class="notion-row" data-row data-id="{{ $task->job_id }}"
    @if($task->taskList && auth()->user()->can('manage', $task->taskList))
        data-list-update-url="{{ route('mytasks.lists.update', $task->taskList) }}"
        data-list-delete-url="{{ route('mytasks.lists.destroy', $task->taskList) }}"
    @endif data-status="{{ $task->job_status }}" data-late="{{ $isLate ? 1 : 0 }}" data-list-id="{{ $task->work_order_list_id }}" data-topic="{{ $task->job_topic }}" data-details="{{ $task->job_details }}" data-project="{{ $projectName }}" data-assignee="{{ $assigneeName }}" data-priority="{{ $task->job_priority }}" data-due="{{ optional($task->job_due_at)->format('Y-m-d') }}">
    <span class="row-grip"><i class="bi bi-grip-vertical"></i></span>
    <button type="button" class="row-title" data-open-task-modal><strong title="{{ $task->job_topic }}">{{ $task->job_topic }}</strong><small>{{ Str::limit($task->job_details ?: 'ยังไม่มีรายละเอียดงาน', 80) }}</small></button>
    <span class="row-project" title="{{ $projectName }}">{{ $projectName }}</span>
    <select class="cell-select status-{{ $statusClass }}" data-field="status" {{ $isLate ? '' : '' }}>
        @foreach([1=>'ยังไม่เริ่ม',2=>'กำลังทำ',3=>'รอตรวจสอบ',4=>'เสร็จแล้ว',5=>'พักงาน'] as $value=>$label)<option value="{{ $value }}" @selected((int)$task->job_status===$value)>{{ $label }}</option>@endforeach
    </select>
    <span class="row-assignee"><i>{{ Str::substr($assigneeName, 0, 1) }}</i><span>{{ $assigneeName }}</span></span>
    <input class="cell-date {{ $isLate ? 'is-late' : '' }}" type="date" data-field="due" value="{{ optional($task->job_due_at)->format('Y-m-d') }}">
    <span class="row-progress"><i><b style="width:{{ (int)$task->job_progress }}%"></b></i><input type="number" data-field="progress" min="0" max="99" value="{{ min(99,(int)$task->job_progress) }}">%</span>
    <select class="cell-select priority-{{ $task->job_priority }}" data-field="priority">@foreach($priorityLabels as $value=>$label)<option value="{{ $value }}" @selected((int)$task->job_priority===$value)>{{ $label }}</option>@endforeach</select>
    <span class="row-actions">
        <button type="button" class="row-action row-open" data-open-task-modal title="เปิดและแก้ไขรายการ"><i class="bi bi-pencil-square"></i></button>
        @can('deleteOwn', $task)
            <button type="button" class="row-action danger" data-delete-task-row data-url="{{ route('mytasks.destroy', $task->job_id) }}" title="ลบรายการ"><i class="bi bi-trash3"></i></button>
        @endcan
    </span>
</div>
