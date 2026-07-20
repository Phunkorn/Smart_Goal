@php
    $isCompleted = (int) $task->job_status === 4;
    $currentUser = auth()->user();
    $canDeleteTask = $currentUser && (
        $currentUser->role === 'admin'
        || $task->user_id === $currentUser->id
        || $task->created_by === $currentUser->id
        || $task->leader_user_id === $currentUser->id
    );
    $canManageTeam = $currentUser && (
        $currentUser->role === 'admin'
        || $task->created_by === $currentUser->id
        || $task->leader_user_id === $currentUser->id
    );
    $statusMeta = [
        2 => ['label' => 'กำลังดำเนินงาน', 'tone' => 'working'],
        4 => ['label' => 'งานเสร็จสิ้น', 'tone' => 'done'],
        5 => ['label' => 'พักงาน', 'tone' => 'paused'],
    ];
    $priorityMeta = [
        1 => ['label' => 'ไม่สำคัญ/ทั่วไป', 'tone' => 'low'],
        2 => ['label' => 'สำคัญ/ไม่ด่วน', 'tone' => 'medium'],
        3 => ['label' => 'ด่วน/สำคัญมาก', 'tone' => 'high'],
    ];
    $statusValue = in_array((int) $task->job_status, [4, 5], true) ? (int) $task->job_status : 2;
    $status = $statusMeta[$statusValue] ?? $statusMeta[2];
    $priority = $priorityMeta[(int) $task->job_priority] ?? $priorityMeta[2];
    $subtaskCount = $task->subtasks->count();
    $doneSubtasks = $task->subtasks->where('is_completed', true)->count();
    $progress = $subtaskCount > 0 ? (int) round(($doneSubtasks / $subtaskCount) * 100) : (int) $task->job_progress;
    $search = Str::lower($task->job_topic . ' ' . $task->job_details . ' ' . $task->subtasks->pluck('title')->join(' ') . ' ' . $task->subtasks->pluck('details')->join(' '));
    $dueState = '';
    if ($task->job_due_at && ! $isCompleted) {
        $dueState = $task->job_due_at->isPast() ? 'overdue' : ($task->job_due_at->diffInDays(now()) <= 2 ? 'soon' : '');
    }
    $isOverdue = $dueState === 'overdue';
    $displayStatus = $isOverdue ? ['label' => 'งานล่าช้า', 'tone' => 'overdue'] : $status;
@endphp

<tr class="task-row {{ $isCompleted ? 'is-completed' : '' }}"
    data-task-row
    data-task-id="{{ $task->job_id }}"
    data-search="{{ $search }}"
    data-status="{{ $isOverdue ? 'overdue' : $statusValue }}"
    data-priority="{{ (int) $task->job_priority }}"
    data-due="{{ optional($task->job_due_at)->format('Y-m-d') }}"
    data-starred="{{ $task->is_starred ? '1' : '0' }}">
    <td class="check-col" data-label="">
        <button type="button"
            class="task-check {{ $isCompleted ? 'is-on' : '' }}"
            data-task-complete
            data-url="{{ route('mytasks.complete', $task->job_id) }}"
            data-completed="{{ $isCompleted ? '0' : '1' }}"
            @disabled($task->approval_status !== 'approved')
            aria-label="{{ $isCompleted ? 'ย้ายกลับไปงานที่ต้องทำ' : 'ทำเครื่องหมายว่าเสร็จ' }}">
            <i class="bi bi-check-lg"></i>
        </button>
    </td>
    <td class="name-col" data-label="งาน">
        <button type="button" class="expand-task" data-expand-task="{{ $task->job_id }}" aria-label="ดูงานย่อย">
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
            @disabled($isCompleted)>
            @foreach ($priorityMeta as $value => $meta)
                <option value="{{ $value }}" @selected((int) $task->job_priority === $value)>{{ $meta['label'] }}</option>
            @endforeach
        </select>
    </td>
    <td data-label="กำหนดส่ง">
        <input type="date"
            class="due-input {{ $dueState }}"
            value="{{ optional($task->job_due_at)->format('Y-m-d') }}"
            data-due-input
            data-url="{{ route('mytasks.updateDueDate', $task->job_id) }}"
            @disabled($isCompleted)>
    </td>
    <td data-label="Subitem">
        <button type="button" class="progress-cell" data-expand-task="{{ $task->job_id }}">
            <span class="progress-track"><span style="width: {{ $progress }}%"></span></span>
            <strong>{{ $doneSubtasks }}/{{ $subtaskCount }}</strong>
        </button>
    </td>
    <td data-label="ผู้ร่วมงาน">
        <div class="avatar-stack">
            <span class="avatar-dot" title="{{ $task->user?->name }}">{{ Str::of($task->user?->name ?? 'U')->substr(0, 2) }}</span>
            @foreach ($task->collaborators->take(3) as $person)
                <span class="avatar-dot muted" title="{{ $person->name }}">{{ Str::of($person->name)->substr(0, 2) }}</span>
            @endforeach
            @if ($task->collaborators->count() > 3)
                <span class="avatar-more">+{{ $task->collaborators->count() - 3 }}</span>
            @endif
            @if ($canManageTeam && ! $isCompleted)
                <button type="button"
                    class="avatar-add"
                    data-open-collaborator-modal
                    data-task-id="{{ $task->job_id }}"
                    data-task-title="{{ $task->job_topic }}"
                    data-existing-users="{{ collect([$task->user_id, $task->leader_user_id])->merge($task->collaborators->pluck('id'))->filter()->unique()->values()->join(',') }}"
                    aria-label="เพิ่มผู้ร่วมงาน">
                    <i class="bi bi-plus-lg"></i>
                </button>
            @endif
        </div>
    </td>
    <td data-label="ไฟล์">
        <span class="file-pill"><i class="bi bi-paperclip"></i> {{ $task->images_count ?? 0 }}</span>
    </td>
    <td data-label="สถานะ">
        <select class="label-select status-label status-{{ $displayStatus['tone'] }}"
            data-status-select
            data-url="{{ route('mytasks.updateStatus', $task->job_id) }}"
            @disabled($task->approval_status !== 'approved' || $isCompleted)>
            @if ($isOverdue)
                <option value="{{ $statusValue }}" selected>งานล่าช้า</option>
            @endif
            @foreach ($statusMeta as $value => $meta)
                <option value="{{ $value }}" @selected(! $isOverdue && $statusValue === $value)>{{ $meta['label'] }}</option>
            @endforeach
        </select>
    </td>
    <td class="row-actions" data-label="">
        <button type="button"
            class="icon-row-btn {{ $task->is_starred ? 'is-starred' : '' }}"
            data-star-task
            data-url="{{ route('mytasks.star', $task->job_id) }}"
            data-starred="{{ $task->is_starred ? '0' : '1' }}"
            aria-label="{{ $task->is_starred ? 'ยกเลิกติดดาว' : 'ติดดาว' }}">
            <i class="bi {{ $task->is_starred ? 'bi-star-fill' : 'bi-star' }}"></i>
        </button>
        @if ($canDeleteTask)
            <button type="button"
                class="icon-row-btn danger"
                data-delete-task
                data-task-title="{{ $task->job_topic }}"
                data-url="{{ route('mytasks.destroy', $task->job_id) }}"
                aria-label="ลบงาน">
                <i class="bi bi-trash3"></i>
            </button>
        @endif
    </td>
</tr>
<tr class="subtask-row" data-subtask-panel="{{ $task->job_id }}" hidden>
    <td></td>
    <td colspan="8">
        <div class="subtask-panel">
            <div class="task-panel-tabs">
                <span><i class="bi bi-list-check"></i> งานย่อย</span>
                <span><i class="bi bi-chat-left-text"></i> อัปเดต</span>
                <span><i class="bi bi-paperclip"></i> ไฟล์</span>
                <span><i class="bi bi-clock-history"></i> Activity Log</span>
            </div>
            <table class="subitem-table">
                <thead>
                    <tr>
                        <th class="check-col"></th>
                        <th>Subitem</th>
                        <th>รายละเอียด</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($task->subtasks as $subtask)
                        <tr class="{{ $subtask->is_completed ? 'is-completed' : '' }}">
                            <td>
                                <button type="button"
                                    class="task-check small {{ $subtask->is_completed ? 'is-on' : '' }}"
                                    data-subtask-toggle
                                    data-url="{{ route('mytasks.subtasks.toggle', $subtask->id) }}"
                                    data-completed="{{ $subtask->is_completed ? '0' : '1' }}">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </td>
                            <td><span class="subtask-title">{{ $subtask->title }}</span></td>
                            <td><span class="subtask-details">{{ $subtask->details ?: '-' }}</span></td>
                            <td>-</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><div class="subtask-empty">ยังไม่มีงานย่อย</div></td></tr>
                    @endforelse
                </tbody>
            </table>
            @unless ($isCompleted)
                <form class="subtask-inline-form" action="{{ route('mytasks.subtasks.store', $task->job_id) }}" method="POST">
                    @csrf
                    <input type="text" name="title" maxlength="255" required placeholder="หัวข้องานย่อย">
                    <input type="text" name="details" maxlength="1000" placeholder="รายละเอียดสั้น ๆ">
                    <button type="submit"><i class="bi bi-plus-lg"></i> เพิ่มงานย่อย</button>
                </form>
            @endunless

            <div class="panel-section">
                <h3>อัปเดตงาน</h3>
                @unless ($isCompleted)
                    <form class="update-inline-form" action="{{ route('tasks.progress.store', $task->job_id) }}" method="POST">
                        @csrf
                        <input type="number" name="progress" min="0" max="99" value="{{ min((int) $task->job_progress, 99) }}" aria-label="เปอร์เซ็นต์ความคืบหน้า">
                        <textarea name="note" maxlength="2000" required placeholder="เขียนอัปเดตงาน..."></textarea>
                        <button type="submit"><i class="bi bi-send"></i> บันทึกอัปเดต</button>
                    </form>
                @endunless
                <div class="update-list">
                    @forelse ($task->updates as $update)
                        <div class="update-item">
                            <strong>{{ $update->user?->name ?? 'ผู้ใช้' }} อัปเดต {{ $update->progress }}%</strong>
                            <span>{{ $update->created_at?->diffForHumans() }}</span>
                            <p>{{ $update->note }}</p>
                        </div>
                    @empty
                        <div class="subtask-empty">ยังไม่มีอัปเดตงาน</div>
                    @endforelse
                </div>
            </div>

            <div class="panel-section">
                <h3>ไฟล์แนบ</h3>
                <div class="attachment-list">
                    @forelse ($task->images as $file)
                        <a href="{{ route('media.show', ['path' => $file->file_path]) }}" target="_blank" rel="noopener" class="attachment-item">
                            <i class="bi bi-paperclip"></i>
                            <span>{{ $file->original_name ?: basename($file->file_path) }}</span>
                        </a>
                    @empty
                        <div class="subtask-empty">ยังไม่มีไฟล์แนบ</div>
                    @endforelse
                </div>
                @unless (($task->images_count ?? 0) >= 5)
                    <form class="attachment-inline-form" action="{{ route('tasks.attachments.store', $task->job_id) }}" method="POST" enctype="multipart/form-data" data-existing-files="{{ $task->images_count ?? 0 }}">
                        @csrf
                        <label class="attachment-drop">
                            <i class="bi bi-cloud-arrow-up"></i>
                            <span>เลือกหรือลากไฟล์มาวาง</span>
                            <small>แนบได้สูงสุด 5 ไฟล์ ไฟล์ละไม่เกิน 5MB (jpg, png, pdf, xls, xlsx, csv, zip)</small>
                            <input type="file" name="completion_attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.xls,.xlsx,.csv,.zip">
                        </label>
                        <button type="submit"><i class="bi bi-upload"></i> บันทึกไฟล์</button>
                    </form>
                @endunless
            </div>

            <div class="panel-section">
                <h3>Activity Log</h3>
                <div class="activity-list">
                    @forelse ($task->activityLogs as $log)
                        <div class="activity-item">
                            <strong>{{ $log->user?->name ?? 'ระบบ' }}{{ $log->user?->department?->department_name ? ' (' . $log->user->department->department_name . ')' : '' }}</strong>
                            <span>{{ $log->description ?: $log->action }} · {{ $log->created_at?->diffForHumans() }}</span>
                        </div>
                    @empty
                        <div class="subtask-empty">ยังไม่มีประวัติการทำงาน</div>
                    @endforelse
                </div>
            </div>
        </div>
    </td>
</tr>
