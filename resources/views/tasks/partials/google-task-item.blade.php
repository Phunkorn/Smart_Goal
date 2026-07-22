@php
    $isCompleted = (int) $task->job_status === 4;
    $currentUser = auth()->user();
    $isLocked = $isCompleted && $currentUser?->role !== 'admin';
    $canDeleteTask = $currentUser && (
        $currentUser->role === 'admin'
        || $task->user_id === $currentUser->id
        || $task->created_by === $currentUser->id
        || $task->leader_user_id === $currentUser->id
    ) && ! $isLocked;
    $requiresDeleteRequest = $currentUser
        && $currentUser->role !== 'admin'
        && $task->creator?->role === 'admin';
    $canManageTeam = $currentUser && ! $isLocked && (
        $currentUser->role === 'admin'
        || $task->created_by === $currentUser->id
        || $task->leader_user_id === $currentUser->id
    );
    $statusMeta = [
        4 => ['label' => 'เสร็จสิ้น', 'tone' => 'done'],
        5 => ['label' => 'พักงาน', 'tone' => 'paused'],
    ];
    $priorityMeta = [
        1 => ['label' => 'ต่ำ', 'tone' => 'low'],
        2 => ['label' => 'ปกติ', 'tone' => 'medium'],
        3 => ['label' => 'สูง', 'tone' => 'high'],
    ];
    $actionLabels = [
        'created' => 'สร้างงาน',
        'updated' => 'แก้ไขข้อมูล',
        'deleted' => 'ลบงาน',
        'status_changed' => 'เปลี่ยนสถานะงาน',
        'priority_changed' => 'เปลี่ยนความสำคัญ',
        'due_date_changed' => 'เปลี่ยนกำหนดส่ง',
        'progress_updated' => 'เพิ่มความคิดเห็น/อัปเดตงาน',
        'attachments_uploaded' => 'เพิ่มไฟล์อ้างอิงงาน',
        'delete_requested' => 'ส่งคำขอลบงาน',
        'delete_request_rejected' => 'ปฏิเสธคำขอลบงาน',
        'approval_updated' => 'อัปเดตการอนุมัติ',
        'collaborator_added' => 'เพิ่มผู้ร่วมโปรเจกต์',
        'collaborator_removed' => 'นำผู้ร่วมโปรเจกต์ออก',
        'project_leader_assigned' => 'กำหนดหัวหน้าโปรเจกต์',
    ];
    $statusValue = in_array((int) $task->job_status, [4, 5], true) ? (int) $task->job_status : 2;
    $priority = $priorityMeta[(int) $task->job_priority] ?? $priorityMeta[2];
    $subtaskCount = $task->subtasks->count();
    $doneSubtasks = $task->subtasks->where('is_completed', true)->count();
    $updateCount = $task->updates->count();
    $attachmentCount = (int) ($task->images_count ?? $task->images->count());
    $activityCount = $task->activityLogs->count();
    $progress = $subtaskCount > 0 ? (int) round(($doneSubtasks / $subtaskCount) * 100) : (int) $task->job_progress;
    $search = Str::lower($task->job_topic . ' ' . $task->job_details . ' ' . $task->subtasks->pluck('title')->join(' ') . ' ' . $task->subtasks->pluck('details')->join(' '));
    $dueState = '';
    if ($task->job_due_at && ! $isCompleted) {
        $dueState = $task->job_due_at->isPast() ? 'overdue' : ($task->job_due_at->diffInDays(now()) <= 2 ? 'soon' : '');
    }
    $isOverdue = $dueState === 'overdue';
    $avatarColors = ['#0073EA', '#E2445C', '#00C875', '#FDAB3D', '#7C4DFF', '#00A9A5'];
    $taskMembers = collect([$task->user])->merge($task->collaborators)->filter()->unique('id')->values();
@endphp

<tr class="task-row {{ $isCompleted ? 'is-completed' : '' }}"
    data-task-row
    data-task-id="{{ $task->job_id }}"
    data-subtask-total="{{ $subtaskCount }}"
    data-subtask-done="{{ $doneSubtasks }}"
    data-search="{{ $search }}"
    data-status="{{ $isOverdue ? 'overdue' : $statusValue }}"
    data-priority="{{ (int) $task->job_priority }}"
    data-due="{{ optional($task->job_due_at)->format('Y-m-d') }}">
    <td class="check-col" data-label="">
        <button type="button"
            class="task-check {{ $isCompleted ? 'is-on' : '' }}"
            data-task-complete
            data-url="{{ route('mytasks.complete', $task->job_id) }}"
            data-completed="{{ $isCompleted ? '0' : '1' }}"
            @disabled($task->approval_status !== 'approved' || $isLocked)
            aria-label="{{ $isCompleted ? 'ย้ายกลับไปงานที่ต้องทำ' : 'ทำเครื่องหมายว่าเสร็จ' }}">
            <i class="bi bi-check-lg"></i>
        </button>
    </td>

    <td class="name-col" data-label="งาน">
        <button type="button" class="expand-task" data-expand-task="{{ $task->job_id }}" aria-label="ดูรายละเอียดงาน">
            <i class="bi bi-chevron-right"></i>
        </button>
        <div class="task-name-wrap">
            <div class="task-title-line">
                <span class="task-title-text">{{ $task->job_topic }}</span>
                @if ($task->approval_status === 'pending')
                    <span class="approval-badge">รออนุมัติจาก Admin</span>
                @endif
            </div>
            @if ($task->job_details)
                <div class="task-detail-line">{{ $task->job_details }}</div>
            @endif
        </div>
    </td>

    <td data-label="ความสำคัญ">
        <select class="label-select priority-label priority-{{ $priority['tone'] }}"
            data-priority-select
            data-url="{{ route('mytasks.updatePriority', $task->job_id) }}"
            @disabled($isLocked)>
            @foreach ($priorityMeta as $value => $meta)
                <option value="{{ $value }}" @selected((int) $task->job_priority === $value)>{{ $meta['label'] }}</option>
            @endforeach
        </select>
    </td>

    <td data-label="ความคืบหน้า">
        <button type="button" class="progress-cell" data-expand-task="{{ $task->job_id }}">
            <span class="progress-track"><span style="width: {{ $progress }}%"></span></span>
            <strong>{{ $progress }}%</strong>
        </button>
    </td>

    <td data-label="กำหนดส่ง">
        <input type="date"
            class="due-input {{ $dueState }}"
            value="{{ optional($task->job_due_at)->format('Y-m-d') }}"
            data-due-input
            data-url="{{ route('mytasks.updateDueDate', $task->job_id) }}"
            @disabled($isLocked)>
    </td>

    <td data-label="สถานะ">
        @if ($isOverdue)
            <span class="label-select status-label status-overdue">ล่าช้า</span>
        @else
            <select class="label-select status-label status-{{ $statusMeta[$statusValue]['tone'] ?? 'working' }}"
                data-status-select
                data-url="{{ route('mytasks.updateStatus', $task->job_id) }}"
                data-current-value="{{ $statusValue }}"
                @disabled($task->approval_status !== 'approved' || $isLocked)>
                <option value="2" @selected($statusValue === 2) disabled>สถานะ</option>
                @foreach ($statusMeta as $value => $meta)
                    <option value="{{ $value }}" @selected($statusValue === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        @endif
    </td>

    <td class="row-actions" data-label="">
        <button type="button"
            class="icon-row-btn task-activity-trigger"
            data-open-task-activity-modal="{{ $task->job_id }}"
            aria-label="ความคิดเห็น ไฟล์อ้างอิง และประวัติ">
            <i class="bi bi-chat-dots" aria-hidden="true"></i>
            @if (($updateCount + $attachmentCount + $activityCount) > 0)
                <span class="task-activity-badge">{{ $updateCount + $attachmentCount + $activityCount }}</span>
            @endif
        </button>
        @if ($canDeleteTask)
            <button type="button"
                class="icon-row-btn danger"
                data-delete-task
                data-task-title="{{ $task->job_topic }}"
                data-delete-request="{{ $requiresDeleteRequest ? '1' : '0' }}"
                data-url="{{ route('mytasks.destroy', $task->job_id) }}"
                aria-label="{{ $requiresDeleteRequest ? 'ส่งคำขอลบงาน' : 'ลบงาน' }}">
                <i class="bi bi-trash3"></i>
            </button>
        @endif

        <div class="simple-modal task-activity-modal" data-task-activity-modal="{{ $task->job_id }}" hidden>
            <div class="simple-modal-card full-task-card" role="dialog" aria-modal="true" aria-labelledby="taskActivityTitle{{ $task->job_id }}">
                <div class="simple-modal-head">
                    <div>
                        <h2 id="taskActivityTitle{{ $task->job_id }}">{{ $task->job_topic }}</h2>
                        <p>ความคิดเห็น ไฟล์อ้างอิงงาน และประวัติการทำงาน</p>
                    </div>
                    <button type="button" class="simple-modal-close" data-close-task-activity-modal aria-label="ปิด">&times;</button>
                </div>
                <div class="task-activity-tabs" role="tablist">
                    <button type="button" class="is-active" data-task-activity-tab="updates" role="tab"><i class="bi bi-chat-left-text"></i> ความคิดเห็น <span>{{ $updateCount }}</span></button>
                    <button type="button" data-task-activity-tab="attachments" role="tab"><i class="bi bi-paperclip"></i> ไฟล์อ้างอิง <span>{{ $attachmentCount }}</span></button>
                    <button type="button" data-task-activity-tab="activity" role="tab"><i class="bi bi-clock-history"></i> ประวัติ <span>{{ $activityCount }}</span></button>
                </div>
                <div class="simple-modal-body task-activity-body">
                    <section data-task-activity-panel="updates">
                        @unless ($isLocked)
                            <form class="update-inline-form" action="{{ route('tasks.progress.store', $task->job_id) }}" method="POST">
                                @csrf
                                <textarea name="note" maxlength="2000" required placeholder="พิมพ์คอมเมนต์หรืออัปเดตงาน..."></textarea>
                                <button type="submit"><i class="bi bi-send"></i> บันทึก</button>
                            </form>
                        @endunless
                        <div class="update-list">
                            @forelse ($task->updates as $update)
                                <div class="update-item"><strong>{{ $update->user?->name ?? 'ผู้ใช้' }}</strong><span>{{ $update->created_at?->diffForHumans() }}</span><p>{{ $update->note }}</p></div>
                            @empty
                                <div class="subtask-empty">ยังไม่มีความคิดเห็น</div>
                            @endforelse
                        </div>
                    </section>
                    <section data-task-activity-panel="attachments" hidden>
                        <div class="attachment-list">
                            @forelse ($task->images as $file)
                                <a href="{{ route('media.show', ['path' => $file->file_path]) }}" target="_blank" rel="noopener" class="attachment-item"><i class="bi bi-paperclip"></i><span>{{ $file->original_name ?: basename($file->file_path) }}</span></a>
                            @empty
                                <div class="subtask-empty">ยังไม่มีไฟล์อ้างอิงงาน</div>
                            @endforelse
                        </div>
                        @unless ($attachmentCount >= 5 || $isLocked)
                            <form class="attachment-inline-form" action="{{ route('tasks.attachments.store', $task->job_id) }}" method="POST" enctype="multipart/form-data" data-existing-files="{{ $attachmentCount }}">
                                @csrf
                                <label class="attachment-drop"><i class="bi bi-cloud-arrow-up"></i><span>เพิ่มไฟล์อ้างอิงงาน</span><small>ใช้สำหรับไฟล์ตัวอย่าง โจทย์งาน หรือเอกสารประกอบ สูงสุด 5 ไฟล์ ไฟล์ละไม่เกิน 10MB: jpg, png, Word, Excel, PowerPoint</small><input type="file" name="completion_attachments[]" multiple accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx"></label>
                                <button type="submit"><i class="bi bi-upload"></i> เพิ่มไฟล์</button>
                            </form>
                        @endunless
                    </section>
                    <section data-task-activity-panel="activity" hidden>
                        <div class="activity-list">
                            @forelse ($task->activityLogs as $log)
                                <div class="activity-item">
                                    <strong>{{ $log->user?->name ?? 'ระบบ' }}{{ $log->user?->department?->department_name ? ' (' . $log->user->department->department_name . ')' : '' }}</strong>
                                    <span>{{ $actionLabels[$log->action] ?? ($log->description ?: $log->action) }} · {{ $log->created_at?->diffForHumans() }}</span>
                                </div>
                            @empty
                                <div class="subtask-empty">ยังไม่มีประวัติการทำงาน</div>
                            @endforelse
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </td>
</tr>

<tr class="subtask-row" data-subtask-panel="{{ $task->job_id }}" hidden>
    <td></td>
    <td colspan="6">
        <div class="subtask-panel">
            <div class="subtask-tree">
                <div class="subtask-tree-label">งานย่อย</div>
                @forelse ($task->subtasks as $subtask)
                    <div class="subtask-tree-item {{ $subtask->is_completed ? 'is-completed' : '' }}">
                        <button type="button"
                            class="task-check small {{ $subtask->is_completed ? 'is-on' : '' }}"
                            data-subtask-toggle
                            data-url="{{ route('mytasks.subtasks.toggle', $subtask->id) }}"
                            data-completed="{{ $subtask->is_completed ? '0' : '1' }}"
                            @disabled($isLocked)>
                            <i class="bi bi-check-lg"></i>
                        </button>
                        <span class="subtask-title">{{ $subtask->title }}</span>
                    </div>
                @empty
                    <div class="subtask-empty">ยังไม่มีงานย่อย</div>
                @endforelse
                @unless ($isLocked)
                    <form class="subtask-inline-form" action="{{ route('mytasks.subtasks.store', $task->job_id) }}" method="POST">
                        @csrf
                        <input type="text" name="title" maxlength="255" required placeholder="เพิ่มงานย่อย">
                        <button type="submit" title="เพิ่มงานย่อย"><i class="bi bi-plus-lg"></i></button>
                    </form>
                @endunless
            </div>

            <div class="panel-section">
                <h3>ความคิดเห็น</h3>
                <div class="update-list">
                    @forelse ($task->updates->take(4) as $update)
                        <div class="update-item">
                            <strong>{{ $update->user?->name ?? 'ผู้ใช้' }}:</strong>
                            <span>{{ Str::limit($update->note, 96) }}</span>
                        </div>
                    @empty
                        <div class="subtask-empty">ยังไม่มีความคิดเห็น</div>
                    @endforelse
                </div>
            </div>

            <div class="panel-section">
                <h3>ไฟล์อ้างอิง</h3>
                <div class="attachment-list">
                    @forelse ($task->images->take(4) as $file)
                        <a href="{{ route('media.show', ['path' => $file->file_path]) }}" target="_blank" rel="noopener" class="attachment-item">
                            <i class="bi bi-paperclip"></i>
                            <span>{{ Str::limit($file->original_name ?: basename($file->file_path), 30) }}</span>
                        </a>
                    @empty
                        <div class="subtask-empty">ยังไม่มีไฟล์อ้างอิงงาน</div>
                    @endforelse
                </div>
            </div>

            <div class="panel-section">
                <h3>ประวัติ</h3>
                <div class="activity-list">
                    @forelse ($task->activityLogs->take(4) as $log)
                        <div class="activity-item">
                            <strong>{{ $log->user?->name ?? 'ระบบ' }}</strong>
                            <span>{{ Str::limit($actionLabels[$log->action] ?? ($log->description ?: $log->action), 76) }} · {{ $log->created_at?->diffForHumans() }}</span>
                        </div>
                    @empty
                        <div class="subtask-empty">ยังไม่มีประวัติการทำงาน</div>
                    @endforelse
                </div>
            </div>
        </div>
    </td>
</tr>
