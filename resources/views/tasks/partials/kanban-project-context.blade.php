@php($projectAttachments = $list->attachments ?? collect())
@if(filled($adminSenderName ?? null) || $projectAttachments->isNotEmpty())
    <div class="mytasks-kanban__project-context">
        @include('tasks.partials.admin-assignment-marker', ['adminSenderName' => $adminSenderName ?? null])
        @if($projectAttachments->isNotEmpty())
            <details class="mytasks-kanban__project-files">
                <summary><i class="bi bi-paperclip"></i>{{ $projectAttachments->count() }} ไฟล์โปรเจกต์</summary>
                <div>
                    @foreach($projectAttachments as $attachment)
                        <a href="{{ route('media.show', ['path' => $attachment->file_path]) }}" target="_blank" rel="noopener">{{ $attachment->original_name }}</a>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@endif
