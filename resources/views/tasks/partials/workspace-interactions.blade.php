<?php
    $workspaceContext = $workspaceContext ?? 'user';
    $showCreateActions = $showCreateActions ?? true;
    $forceReadOnly = $forceReadOnly ?? false;
    $teamData = $allTasks->mapWithKeys(fn ($task) => [(string) $task->job_id => [
        'id' => $task->job_id,
        'topic' => $task->job_topic,
        'locked' => $forceReadOnly || ((int) $task->job_status === 4 && auth()->user()->role !== 'admin'),
        'can_manage' => ! $forceReadOnly && auth()->user()->can('manageTeam', $task),
        'add_url' => ! $forceReadOnly && auth()->user()->can('manageTeam', $task) ? route('tasks.collaborators.store', $task->job_id) : null,
        'remove_url' => ! $forceReadOnly && auth()->user()->can('manageTeam', $task) ? route('tasks.collaborators.destroy', [$task->job_id, '__USER__']) : null,
        'assignee' => [
            'id' => $task->user?->id,
            'name' => $task->user?->name ?? 'ไม่ระบุ',
            'email' => $task->user?->email,
            'department' => $task->user?->department?->department_name,
            'avatar_url' => $task->user?->profile_image ? route('media.profile', $task->user) : null,
        ],
        // ผู้รับผิดชอบ ผู้สร้าง และหัวหน้างาน ลบออกจากทีมไม่ได้ (TaskCollaboratorController:87)
        'protected_ids' => array_values(array_filter([$task->user_id, $task->created_by, $task->leader_user_id])),
        'collaborators' => $task->collaborators->map(fn ($person) => [
            'id' => $person->id,
            'name' => $person->name,
            'email' => $person->email,
            'department' => $person->department?->department_name,
            'avatar_url' => $person->profile_image ? route('media.profile', $person) : null,
            'status' => $person->pivot?->status ?? 'pending',
            // บัญชีที่ถูกปิดยังแสดงไว้เพื่อรักษาประวัติ แต่ต้องบอกสถานะให้ชัดและเพิ่มซ้ำไม่ได้
            'is_active' => (bool) $person->is_active,
        ])->values(),
    ]]);
?>
<script type="application/json" data-team-data>@json($teamData)</script>

<?php
    $ownerData = $allTasks->mapWithKeys(fn ($task) => [(string) $task->job_id => [
        'name' => $task->user?->name ?? 'ไม่ระบุผู้รับผิดชอบ',
        'avatar_url' => $task->user?->profile_image ? route('media.profile', $task->user) : null,
        'initial' => Str::substr($task->user?->name ?? '?', 0, 1),
    ]]);
?>
<script type="application/json" data-owner-data>@json($ownerData)</script>

<?php
    $attachmentData = $allTasks->mapWithKeys(fn ($task) => [(string) $task->job_id => [
        'id' => $task->job_id,
        'topic' => $task->job_topic,
        'can_upload' => ! $forceReadOnly && auth()->user()->can('work', $task),
        'upload_url' => ! $forceReadOnly && auth()->user()->can('work', $task) ? route('tasks.attachments.store', $task->job_id) : null,
        'files' => $task->images->map(fn ($file) => [
            'name' => $file->original_name ?? basename($file->file_path),
            'url' => route('media.task-attachments.show', $file),
            'delete_url' => ! $forceReadOnly && auth()->user()->can('work', $task) ? route('tasks.attachments.destroy', [$task->job_id, $file]) : null,
        ])->values(),
    ]]);
?>
<script type="application/json" data-attachment-data
    data-max-files="{{ \App\Support\AttachmentPolicy::MAX_FILES }}"
    data-max-kilobytes="{{ \App\Support\AttachmentPolicy::MAX_KILOBYTES }}"
    data-max-size-label="{{ \App\Support\AttachmentPolicy::maxSizeLabel() }}"
    data-extensions="{{ implode(',', \App\Support\AttachmentPolicy::extensions()) }}"
    data-types-label="{{ \App\Support\AttachmentPolicy::typesLabel() }}">@json($attachmentData)</script>
<?php
    $commentPresenter = app(\App\Support\TaskCommentPresenter::class);
    $commentReceipts = $commentPresenter->receiptsForTasks($allTasks);
    $timelineData = $allTasks->mapWithKeys(function ($task) use ($commentPresenter, $commentReceipts) {
        $canViewComments = auth()->user()->can('viewComments', $task);
        $receipts = $commentReceipts->get((string) $task->job_id, []);

        return [(string) $task->job_id => [
            'updates' => $task->updates
                ->filter(fn ($update) => ! $update->is_comment || $canViewComments)
                ->map(fn ($update) => $commentPresenter->comment(
                    $update,
                    auth()->user(),
                    $receipts[(string) $update->id] ?? []
                ))
                ->values(),
            'activity' => $task->activityLogs->map(fn ($log) => [
                'author' => $log->user?->name ?? 'ระบบ',
                'avatar_url' => $log->user?->profile_image ? route('media.profile', $log->user) : null,
                'note' => $log->description,
                'at' => $commentPresenter->timestamp($log->created_at),
                'is_mine' => (int) $log->user_id === (int) auth()->id(),
            ])->values(),
        ]];
    });
?>
<script type="application/json" data-timeline-data>@json($timelineData)</script>
<?php
    $readOnlyTransitions = fn ($task) => [
        'can_edit' => false,
        'can_admin_override' => false,
        'can_submit_review' => false,
        'can_review' => false,
        'can_self_close' => false,
        'can_reopen' => false,
        'is_final' => (int) $task->job_status === 4,
        'is_self_task' => false,
        'approver_id' => null,
        'allowed_statuses' => [(int) $task->job_status],
    ];
    $taskManagementData = $allTasks->mapWithKeys(fn ($task) => [(string) $task->job_id => [
        'transitions' => $forceReadOnly
            ? $readOnlyTransitions($task)
            : app(\App\Services\TaskStatusTransitionService::class)->capabilities($task, auth()->user()),
        // ใช้ตัดสินว่า Summary Bar จะเป็นตัวควบคุมที่กดได้ หรือแสดงเป็นข้อความอ่านอย่างเดียว
        // การซ่อนปุ่มเป็นเรื่อง UI เท่านั้น สิทธิ์จริงยังถูกตรวจซ้ำที่ Policy ฝั่ง server ทุกครั้ง
        'can_work' => ! $forceReadOnly && auth()->user()->can('work', $task),
        'can_comment' => ! $forceReadOnly && auth()->user()->can('comment', $task),
        'can_view_comments' => auth()->user()->can('viewComments', $task),
        'can_manage_team' => ! $forceReadOnly && auth()->user()->can('manageTeam', $task),
        'project' => $task->taskList?->name ?? 'งานทั่วไป',
        'status' => (int) $task->job_status,
        'submitted_by' => $task->reviewSubmitter?->name,
        'submitted_at' => optional($task->submitted_for_review_at)->translatedFormat('j M Y H:i'),
        'comment_url' => ! $forceReadOnly && auth()->user()->can('comment', $task) ? route('tasks.comments.store', $task) : null,
        'read_comments_url' => auth()->user()->can('viewComments', $task) ? route('tasks.comments.read', $task) : null,
        'unread_comments' => (int) ($unreadCommentCounts[$task->job_id] ?? 0),
    ]]);
?>
<script type="application/json" data-task-management-data>@json($taskManagementData)</script>

<div class="team-modal notion-modal" data-team-modal hidden>
    <section class="team-modal-card" role="dialog" aria-modal="true" aria-labelledby="team-modal-title">
        <header class="team-modal__header">
            <div class="team-modal__heading">
                <span class="team-modal__heading-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
                <span class="team-modal__heading-copy">
                    <span class="task-edit-kicker">PROJECT TEAM</span>
                    <strong id="team-modal-title">ทีมของงานนี้</strong>
                    <small data-team-topic></small>
                </span>
            </div>
            <button type="button" class="task-modal-close" data-close-team aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        </header>

        {{--
            Team Manager เป็น component เดียว ไม่มีรายการสมาชิกกับฟอร์มเพิ่มแยกกันอีกแล้ว
            คอลัมน์ซ้ายคือคนที่ยังเพิ่มได้ คอลัมน์ขวาคือทีมปัจจุบันและคนที่เตรียมเพิ่ม
        --}}
        <form class="team-manager" data-team-form>
            <div class="team-manager__body">
                @php
                    // ปุ่มกรองใช้เฉพาะแผนกที่มีคนอยู่จริง จะได้ไม่มีปุ่มที่กดแล้วว่างเปล่า
                    $collaboratorDepartments = $availableCollaborators
                        ->pluck('department')
                        ->filter()
                        ->unique('id')
                        ->sortBy('department_name')
                        ->values();
                @endphp

                @include('components.people-selector', [
                    'instanceId' => 'task-collaborators',
                    'inputName' => 'collaborators[]',
                    'people' => $availableCollaborators,
                    'departments' => $collaboratorDepartments,
                    'selectedIds' => [],
                    'sidePanel' => 'tasks.partials.team-current-list',
                    'variant' => 'team-manager',
                    'labels' => [
                        'title' => 'เพิ่มผู้ร่วมงาน',
                        'hint' => 'ค้นหาและเลือกคนที่ต้องการเพิ่มเข้าร่วมงาน',
                        'search' => 'ค้นหาชื่อ อีเมล หรือแผนก',
                        'emptyOptions' => 'ไม่มีพนักงานที่เพิ่มเข้าทีมนี้ได้',
                        'emptySelected' => 'ยังไม่ได้เลือกใคร',
                        'countTemplate' => 'เลือกเพิ่ม :count คน',
                        'removeHint' => 'กด × เพื่อยกเลิก',
                        'help' => 'แสดงเฉพาะบัญชีที่เปิดใช้งานและยังไม่อยู่ในทีม',
                        'help2' => 'การเปลี่ยนแผนกไม่ยกเลิกคนที่เลือกไว้',
                    ],
                ])

                <p class="team-manager__notice" data-team-notice hidden></p>
            </div>

            <footer class="team-manager__footer">
                <p class="team-manager__hint"><i class="bi bi-info-circle" aria-hidden="true"></i> สมาชิกที่เลือกจะถูกเพิ่มเมื่อกดยืนยัน</p>
                <div class="team-manager__actions">
                    <button type="button" class="task-secondary" data-close-team>ปิด</button>
                    <button type="submit" class="notion-primary" data-team-submit aria-busy="false" disabled><i class="bi bi-person-plus" aria-hidden="true"></i> <span data-team-submit-label>เพิ่มผู้ร่วมงาน (0 คน)</span></button>
                </div>
            </footer>
        </form>
    </section>
</div>

<div class="notion-modal owner-modal" data-owner-modal hidden>
    <section class="owner-modal-card" role="dialog" aria-modal="true" aria-labelledby="owner-modal-title">
        <button type="button" class="task-modal-close owner-modal-close" data-close-owner aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        <div class="owner-modal-avatar" data-owner-avatar></div>
        <strong id="owner-modal-title" data-owner-name></strong>
    </section>
</div>

<div class="notion-modal board-attachment-modal" data-board-attachment-modal hidden>
    <section class="board-attachment-modal__card" role="dialog" aria-modal="true" aria-labelledby="board-attachment-modal-title">
        <header>
            <div><span>ATTACHMENTS</span><strong id="board-attachment-modal-title">ไฟล์แนบ</strong><small data-board-attachment-topic></small></div>
            <button type="button" class="task-modal-close" data-close-board-attachments aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        </header>
        <div class="board-attachment-modal__body">
            <div class="board-attachment-modal__list" data-board-attachment-list></div>
            <div class="board-attachment-modal__empty" data-board-attachment-empty hidden><i class="bi bi-paperclip"></i><strong>ยังไม่มีไฟล์แนบ</strong><span>เพิ่มเอกสารหรือรูปภาพที่เกี่ยวข้องกับงานนี้ได้ด้านล่าง</span></div>
            <div class="board-attachment-modal__upload" data-board-attachment-upload>
                <label><i class="bi bi-cloud-arrow-up"></i><span><strong>เพิ่มไฟล์แนบ</strong><small>{{ \App\Support\AttachmentPolicy::limitsLabel() }}</small></span><input type="file" multiple data-board-modal-attachment-input accept="{{ \App\Support\AttachmentPolicy::acceptAttribute() }}"></label>
                <label class="attachment-modal-folder"><i class="bi bi-folder-plus"></i><span><strong>เพิ่มทั้งโฟลเดอร์</strong><small>ระบบจะแนบเฉพาะไฟล์ที่รองรับ</small></span><input type="file" multiple webkitdirectory directory data-board-modal-attachment-folder></label>
            </div>
        </div>
        <footer><button type="button" class="task-secondary" data-close-board-attachments>ปิด</button></footer>
    </section>
</div>

@php
    /**
     * Task Workspace — หน้าจัดการรายการงานที่ Admin และ User ใช้ร่วมกันทั้งหมด
     *
     * โครงสร้างข้อมูลคือ โปรเจกต์ > รายการงาน จึงไม่มีช่อง "รายละเอียดงาน" อีกต่อไป
     * คอลัมน์ job_details ยังคงอยู่ในฐานข้อมูลเพื่อ backward compatibility แต่ไม่ถูกส่งจากที่นี่
     *
     * $workspaceRootLabel / $workspaceRootUrl คือปลายทางของ breadcrumb ระดับบน
     * ซึ่งต่างกันตามหน้าที่ฝัง partial นี้ (งานของฉัน หรือ Workspace ของสมาชิก)
     */
    $workspaceRootLabel = $workspaceRootLabel ?? 'งานของฉัน';
    $workspaceRootUrl = $workspaceRootUrl ?? route('mytasks.index');
    // ลำดับเดียวกับคอลัมน์บอร์ด: พักงาน → กำลังทำ → รอตรวจสอบ → ล่าช้า → เสร็จแล้ว
    $statusOptions = [5 => ['พักงาน', 'paused'], 2 => ['กำลังทำ', 'progress'], 3 => ['รอตรวจสอบ', 'review'], 6 => ['ล่าช้า', 'late'], 4 => ['เสร็จแล้ว', 'done']];
    $priorityOptions = [3 => ['สำคัญด่วน', 'urgent'], 4 => ['ด่วนไม่ค่อยสำคัญ', 'quick'], 2 => ['สำคัญไม่ด่วน', 'important'], 5 => ['ไม่รีบ ไม่มีกำหนด', 'flexible'], 1 => ['routine', 'routine']];
@endphp

{{-- sg-task-theme ให้ token และสไตล์เมนูสถานะ/ความสำคัญ โดยไม่พา layout ของหน้ามาบีบ backdrop --}}
<div class="task-workspace-modal notion-modal sg-task-theme" data-task-modal hidden>
    <form class="task-workspace" data-task-form data-readonly="false">

        <header class="task-workspace__header">
            <nav class="task-workspace__breadcrumb" aria-label="เส้นทางนำทาง">
                <a href="{{ $workspaceRootUrl }}">{{ $workspaceRootLabel }}</a>
                <span aria-hidden="true">/</span>
                <a href="{{ $workspaceRootUrl }}" data-workspace-project-link><span data-workspace-project>งานทั่วไป</span></a>
            </nav>

            <div class="task-workspace__title">
                <h2 data-workspace-title-text></h2>
                <button type="button" class="task-workspace__rename" data-rename-task aria-label="แก้ชื่อรายการงาน" title="แก้ชื่อรายการงาน"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                {{-- ไม่ใส่ required เพราะช่องนี้ถูกซ่อนเมื่อไม่ได้แก้ชื่อ เบราว์เซอร์จะบล็อกการ submit --}}
                {{-- ชื่อว่างถูกตรวจใน JavaScript ก่อนส่ง และ Backend ยังบังคับ required ซ้ำอีกชั้น --}}
                <input name="job_topic" maxlength="255" aria-label="ชื่อรายการงาน" hidden>
            </div>

            <p class="task-workspace__subtitle">ข้อมูลและความคืบหน้าของงาน</p>

            <button type="button" class="task-workspace__close" data-close-task aria-label="ปิด"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>

        <section class="task-workspace__summary" aria-label="ข้อมูลสรุปของงาน">
            <div class="task-workspace__cell">
                <span class="task-workspace__cell-icon tone-status"><i class="bi bi-check2-square" aria-hidden="true"></i></span>
                <span class="task-workspace__cell-body">
                    <span class="task-workspace__cell-label" id="task-workspace-status-label">สถานะ</span>
                    <select name="job_status" hidden aria-hidden="true" tabindex="-1">@foreach($statusOptions as $value => $meta)<option value="{{ $value }}">{{ $meta[0] }}</option>@endforeach</select>
                    <details class="board-status-menu modal-status-menu" data-modal-status-menu>
                        <summary class="board-status-pill" aria-labelledby="task-workspace-status-label"><span data-modal-status-label></span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
                        <div>@foreach($statusOptions as $value => $meta)<button type="button" class="status-{{ $meta[1] }}" data-modal-status-value="{{ $value }}">{{ $meta[0] }}</button>@endforeach</div>
                    </details>
                    <output class="task-workspace__cell-static" data-static-status hidden></output>
                </span>
            </div>

            <div class="task-workspace__cell">
                <span class="task-workspace__cell-icon tone-priority"><i class="bi bi-star-fill" aria-hidden="true"></i></span>
                <span class="task-workspace__cell-body">
                    <span class="task-workspace__cell-label" id="task-workspace-priority-label">ความสำคัญ</span>
                    <select name="job_priority" hidden aria-hidden="true" tabindex="-1">@foreach($priorityOptions as $value => $meta)<option value="{{ $value }}">{{ $meta[0] }}</option>@endforeach</select>
                    <details class="board-priority-menu modal-priority-menu" data-modal-priority-menu>
                        <summary class="board-priority" aria-labelledby="task-workspace-priority-label"><span data-modal-priority-label></span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
                        <div>@foreach($priorityOptions as $value => $meta)<button type="button" class="priority-{{ $meta[1] }}" data-modal-priority-value="{{ $value }}"><i class="bi bi-flag-fill" aria-hidden="true"></i>{{ $meta[0] }}</button>@endforeach</div>
                    </details>
                    <output class="task-workspace__cell-static" data-static-priority hidden></output>
                </span>
            </div>

            <label class="task-workspace__cell">
                <span class="task-workspace__cell-icon tone-date"><i class="bi bi-calendar-event" aria-hidden="true"></i></span>
                <span class="task-workspace__cell-body">
                    <span class="task-workspace__cell-label">วันที่เริ่ม</span>
                    <input type="date" data-date-picker name="job_start_at" class="task-workspace__date" required>
                </span>
            </label>

            <label class="task-workspace__cell">
                <span class="task-workspace__cell-icon tone-date"><i class="bi bi-calendar-check" aria-hidden="true"></i></span>
                <span class="task-workspace__cell-body">
                    <span class="task-workspace__cell-label">กำหนดส่ง</span>
                    <input type="date" data-date-picker name="job_due_at" class="task-workspace__date" required>
                </span>
            </label>

            <div class="task-workspace__cell">
                <span class="task-workspace__cell-icon tone-person"><i class="bi bi-person" aria-hidden="true"></i></span>
                <span class="task-workspace__cell-body">
                    <span class="task-workspace__cell-label">ผู้รับผิดชอบ</span>
                    {{-- ผู้รับผิดชอบเปลี่ยนได้จากหน้าจัดการทีมเท่านั้น ที่นี่จึงเป็นข้อมูลอ่านอย่างเดียวเสมอ --}}
                    <output class="task-workspace__cell-static" data-workspace-assignee></output>
                    <input name="assignee" type="hidden">
                </span>
            </div>

            <div class="task-workspace__cell">
                <span class="task-workspace__cell-icon tone-team"><i class="bi bi-people-fill" aria-hidden="true"></i></span>
                <span class="task-workspace__cell-body">
                    <span class="task-workspace__cell-label">ผู้ร่วมงาน</span>
                    <button type="button" class="task-workspace__cell-action" data-manage-team>เพิ่มผู้ร่วมงาน</button>
                </span>
            </div>
        </section>

        <div class="task-workspace__panels">
            <section class="task-workspace__panel task-workspace__panel--files" data-task-attachments aria-labelledby="task-workspace-files-title">
                <header class="task-workspace__panel-head">
                    <span class="task-workspace__panel-icon"><i class="bi bi-paperclip" aria-hidden="true"></i></span>
                    <strong id="task-workspace-files-title">ไฟล์แนบ</strong>
                    <label class="task-workspace__add-file" data-task-inline-drop>
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่มไฟล์
                        <input type="file" multiple data-task-inline-file-input accept="{{ \App\Support\AttachmentPolicy::acceptAttribute() }}">
                    </label>
                    {{-- HTML อัปโหลด "โฟลเดอร์" เป็นก้อนเดียวไม่ได้ webkitdirectory ให้เบราว์เซอร์
                         กางโฟลเดอร์เป็นไฟล์ย่อยแล้วส่งมาทีละไฟล์ การตรวจชนิดและขนาดจึงยังทำงานตามปกติ
                         เบราว์เซอร์ที่ไม่รองรับจะปฏิบัติกับปุ่มนี้เหมือนปุ่มเลือกไฟล์ธรรมดา --}}
                    <label class="task-workspace__add-file task-workspace__add-file--folder" title="เลือกทั้งโฟลเดอร์ ระบบจะแนบเฉพาะไฟล์ที่รองรับ">
                        <i class="bi bi-folder-plus" aria-hidden="true"></i> เพิ่มทั้งโฟลเดอร์
                        <input type="file" multiple webkitdirectory directory data-task-inline-folder-input>
                    </label>
                </header>
                {{-- ทั้งพื้นที่นี้คือ drop zone ไม่ใช่แค่ปุ่ม "เพิ่มไฟล์" เล็ก ๆ ที่หัวการ์ด
                     ผู้ใช้ลากไฟล์มาที่กรอบว่างกลางการ์ดเป็นธรรมชาติที่สุด --}}
                <div class="task-workspace__panel-body" data-task-drop-zone>
                    <div class="task-inline-files" data-task-inline-files></div>
                    <p class="task-workspace__file-types" data-attachment-types>
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        ลากไฟล์หรือทั้งโฟลเดอร์มาวางที่นี่ได้ — รองรับ {{ \App\Support\AttachmentPolicy::limitsLabel() }}
                    </p>
                    <p class="task-workspace__file-status" data-attachment-status role="status" aria-live="polite" hidden></p>
                    <div class="task-workspace__drop-overlay" data-attachment-drop-overlay aria-hidden="true">
                        <i class="bi bi-cloud-arrow-up-fill" aria-hidden="true"></i>
                        <strong>วางไฟล์เพื่อแนบ</strong>
                    </div>
                </div>
            </section>

            <section class="task-workspace__panel task-workspace__panel--timeline task-timeline" data-task-timeline>
                <nav class="task-workspace__tabs" role="tablist" aria-label="อัปเดตและกิจกรรม">
                    <button type="button" class="active" role="tab" aria-selected="true" data-timeline-tab="updates">อัปเดต</button>
                    <button type="button" role="tab" aria-selected="false" data-timeline-tab="activity">กิจกรรม</button>
                </nav>
                <div class="task-workspace__timeline-items" data-timeline-items></div>
                <div class="task-timeline__compose">
                    <textarea data-task-update-note maxlength="2000" rows="1" placeholder="เขียนอัปเดต..." aria-label="เขียนอัปเดต"></textarea>
                    <button type="button" data-submit-task-update aria-label="ส่งอัปเดต"><i class="bi bi-send-fill" aria-hidden="true"></i></button>
                </div>
            </section>
        </div>

        <footer class="task-workspace__footer">
            <p class="task-workspace__hint"><i class="bi bi-info-circle" aria-hidden="true"></i> การเปลี่ยนแปลงจะถูกบันทึกเมื่อกดปุ่มบันทึก</p>
            <div class="task-workspace__actions">
                <button type="button" class="task-secondary" data-review-return hidden>ส่งกลับแก้ไข</button>
                <button type="button" class="notion-primary" data-review-approve hidden>อนุมัติและปิดงาน</button>
                <button type="button" class="notion-primary" data-reopen-task hidden>เปิดงานอีกครั้ง</button>
                <button type="button" class="task-secondary" data-close-task>ยกเลิก</button>
                <button type="submit" class="notion-primary" data-save-task>บันทึกการแก้ไข</button>
            </div>
        </footer>
    </form>
</div>
