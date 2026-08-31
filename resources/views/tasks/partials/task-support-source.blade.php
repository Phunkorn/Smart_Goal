@if(filled($adminSenderName ?? null) || ($taskLinkMode ?? false) || ($includeCollaborators ?? false))
    <template data-task-support-source data-task-id="{{ $task->job_id }}">
        @include('tasks.partials.admin-assignment-marker', ['adminSenderName' => $adminSenderName ?? null])
        @if($includeCollaborators ?? false)
            @include('tasks.partials.kanban-collaborators', ['task' => $task])
        @endif
        @if($taskLinkMode ?? false)
            @include('tasks.partials.task-open-link', ['task' => $task])
        @endif
    </template>
@endif
