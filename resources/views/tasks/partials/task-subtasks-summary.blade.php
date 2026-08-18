@php($visibleSubtasks = $task->subtasks ?? collect())
@if($task->job_details || $visibleSubtasks->isNotEmpty())
    <span class="task-supporting-summary">
        @if($task->job_details)<small>{{ Str::limit($task->job_details, 90) }}</small>@endif
        @if($visibleSubtasks->isNotEmpty())
            <small class="task-subtask-summary"><i class="bi bi-list-check"></i>{{ $visibleSubtasks->where('is_completed', true)->count() }}/{{ $visibleSubtasks->count() }} งานย่อย</small>
        @endif
    </span>
@endif
