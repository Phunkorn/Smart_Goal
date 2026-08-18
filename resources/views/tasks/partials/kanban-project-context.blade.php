@php
    $projectAttachments = $list->attachments ?? collect();
    $workspaceContext = $workspaceContext ?? 'user';
    $canManageProjectAttachments = $workspaceContext === 'admin-member' && auth()->user()->can('manage', $list);
@endphp
@if(filled($adminSenderName ?? null) || $projectAttachments->isNotEmpty() || $canManageProjectAttachments)
    <div class="mytasks-kanban__project-context">
        @include('tasks.partials.admin-assignment-marker', ['adminSenderName' => $adminSenderName ?? null])
        @if($projectAttachments->isNotEmpty() || $canManageProjectAttachments)
            <details class="mytasks-kanban__project-files" data-project-attachments data-upload-url="{{ route('mytasks.lists.attachments.store', $list) }}">
                <summary><i class="bi bi-paperclip"></i><span data-project-attachment-count>{{ $projectAttachments->count() }}</span> ไฟล์โปรเจกต์</summary>
                <div>
                    @foreach($projectAttachments as $attachment)
                        <span><a href="{{ route('media.show', ['path' => $attachment->file_path]) }}" target="_blank" rel="noopener">{{ $attachment->original_name }}</a>@if($canManageProjectAttachments)<button type="button" data-project-attachment-delete="{{ route('mytasks.lists.attachments.destroy', [$list, $attachment]) }}" aria-label="ลบไฟล์ {{ $attachment->original_name }}"><i class="bi bi-x-lg"></i></button>@endif</span>
                    @endforeach
                    @if($canManageProjectAttachments)<label><i class="bi bi-plus-lg"></i> เพิ่มไฟล์<input type="file" multiple data-project-attachment-input accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx"></label>@endif
                </div>
            </details>
        @endif
    </div>
@endif
