@php($visibleSubtasks = $task->subtasks ?? collect())
@if($visibleSubtasks->isNotEmpty())
    <span class="task-supporting-summary">
        @if($visibleSubtasks->isNotEmpty())
            <small class="task-subtask-summary"><i class="bi bi-list-check"></i>{{ $visibleSubtasks->where('is_completed', true)->count() }}/{{ $visibleSubtasks->count() }} งานย่อย</small>
        @endif
    </span>
@endif
