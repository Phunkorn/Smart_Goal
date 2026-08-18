@if(filled($adminSenderName ?? null) || $task->job_details || $task->subtasks->isNotEmpty() || ($taskLinkMode ?? false) || ($includeCollaborators ?? false))
    <template data-task-support-source data-task-id="{{ $task->job_id }}">
        @include('tasks.partials.admin-assignment-marker', ['adminSenderName' => $adminSenderName ?? null])
        @include('tasks.partials.task-subtasks-summary', ['task' => $task])
        @if($includeCollaborators ?? false)
            @include('tasks.partials.kanban-collaborators', ['task' => $task])
        @endif
        @if($taskLinkMode ?? false)
            @include('tasks.partials.task-open-link', ['task' => $task])
        @endif
    </template>
@endif
