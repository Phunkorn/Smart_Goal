<?php
    $workspaceContext = $workspaceContext ?? 'user';
    $showCreateActions = $showCreateActions ?? true;
    $forceReadOnly = $forceReadOnly ?? false;
    /*
     * โครงสร้างสมาชิกโปรเจกต์ที่หน้าจอใช้ ตรงกับ App\Support\ProjectRoles ทุกประการ:
     *   leader       — หัวหน้าโปรเจกต์ คือผู้สร้างงานใบนั้น
     *   participants — ผู้เข้าร่วมทั้งหมด ทั้งผู้รับผิดชอบงานและคนที่ถูกเพิ่มเข้ามา
     *
     * cross_department เป็นเพียงป้ายบอกที่มาของคน ไม่ใช่สถานะรออนุมัติ — ผู้เข้าร่วม
     * ต่างแผนกเข้าร่วมได้ทันทีเหมือนคนในแผนก (CollaboratorInvitationService::invite())
     */
    $personPayload = fn ($person) => $person === null ? null : [
        'id' => $person->id,
        'name' => $person->name,
        'email' => $person->email,
        'department' => $person->department?->department_name,
        'avatar_url' => $person->profile_image ? route('media.profile', $person) : null,
    ];
    $teamData = $allTasks->mapWithKeys(function ($task) use ($forceReadOnly, $personPayload) {
        $canManage = ! $forceReadOnly && auth()->user()->can('manageTeam', $task);
        $leader = \App\Support\ProjectRoles::leader($task);

        return [(string) $task->job_id => [
            'id' => $task->job_id,
            'topic' => $task->job_topic,
            'locked' => $forceReadOnly || ((int) $task->job_status === 4 && auth()->user()->role !== 'admin'),
            'can_manage' => $canManage,
            'add_url' => $canManage ? route('tasks.collaborators.store', $task->job_id) : null,
            'remove_url' => $canManage ? route('tasks.collaborators.destroy', [$task->job_id, '__USER__']) : null,
            'project_department' => \App\Support\ProjectRoles::projectDepartmentName($task),
            'leader' => $leader === null ? null : [
                ...$personPayload($leader),
                'cross_department' => \App\Support\ProjectRoles::joinsAcrossDepartments($task, $leader),
            ],
            // หัวหน้าโปรเจกต์ ผู้รับผิดชอบ และหัวหน้างาน นำออกจากโปรเจกต์ไม่ได้
            // (TaskCollaboratorController::removeCollaborator)
            'protected_ids' => array_values(array_filter([$task->user_id, $task->created_by, $task->leader_user_id])),
            'participants' => \App\Support\ProjectRoles::participants($task)
                ->map(fn (array $entry) => [
                    ...$personPayload($entry['user']),
                    'status' => $entry['status'],
                    'is_assignee' => $entry['is_assignee'],
                    'cross_department' => $entry['cross_department'],
                    // บัญชีที่ถูกปิดยังแสดงไว้เพื่อรักษาประวัติ แต่ต้องบอกสถานะให้ชัดและเพิ่มซ้ำไม่ได้
                    'is_active' => (bool) $entry['user']->is_active,
                ])
                ->values(),
        ]];
    });
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
        'is_closed' => (int) $task->job_status === 4,
        'can_upload' => ! $forceReadOnly && auth()->user()->can('work', $task),
        'upload_url' => ! $forceReadOnly && auth()->user()->can('work', $task) ? route('tasks.attachments.store', $task->job_id) : null,
        // รูปแบบเดียวกับที่ TaskAttachmentController และ TaskStatusController ส่งกลับหลังแนบ/ลบไฟล์
        'files' => \App\Support\TaskAttachmentPresenter::files($task, ! $forceReadOnly && auth()->user()->can('work', $task)),
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
    /*
     * รายชื่อที่ @กล่าวถึงได้ในแต่ละงาน — ชุดเดียวกับผู้รับแจ้งเตือนคอมเมนต์ของงานใบนั้น
     * (TaskCommentService::mentionCandidates()) ไม่ใช่ TaskCollaboratorOptions ซึ่งเป็นรายชื่อ
     * ทั่วทั้งระบบสำหรับหน้าเพิ่มผู้เข้าร่วมโปรเจกต์ คนละขอบเขตกัน
     */
    $taskCommentService = app(\App\Services\TaskCommentService::class);
    $mentionableData = $allTasks->mapWithKeys(fn ($task) => [(string) $task->job_id =>
        $taskCommentService->mentionCandidates($task)->map(fn ($person) => [
            'id' => $person->id,
            'name' => $person->name,
            'avatar_url' => $person->profile_image ? route('media.profile', $person) : null,
        ])->values(),
    ]);
?>
<script type="application/json" data-mentionable-data>@json($mentionableData)</script>
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
        'shows_review_stage' => (int) $task->job_status === 3,
        'approver_id' => null,
        'allowed_statuses' => [(int) $task->job_status],
        // shape ต้องตรงกับ TaskStatusTransitionService::capabilities() ทุกคีย์
        // ไม่งั้น subtask gate ฝั่ง JS จะอ่านค่าไม่เจอใน workspace แบบอ่านอย่างเดียว
        'task_id' => (int) $task->job_id,
        'child_count' => 0,
        'open_child_count' => 0,
        'children_restricted' => false,
    ];
    $taskManagementData = $allTasks->mapWithKeys(fn ($task) => [(string) $task->job_id => [
        'transitions' => $forceReadOnly
            ? $readOnlyTransitions($task)
            : app(\App\Services\TaskStatusTransitionService::class)->capabilities($task, auth()->user()),
        // ใช้ตัดสินว่า Summary Bar จะเป็นตัวควบคุมที่กดได้ หรือแสดงเป็นข้อความอ่านอย่างเดียว
        // การซ่อนปุ่มเป็นเรื่อง UI เท่านั้น สิทธิ์จริงยังถูกตรวจซ้ำที่ Policy ฝั่ง server ทุกครั้ง
        'can_work' => ! $forceReadOnly && auth()->user()->can('work', $task),
        // ความคิดเห็นไม่ใช่การแก้งาน จึงไม่ถูกปิดด้วย forceReadOnly เหมือนปุ่มอื่น
        // Workspace แบบอ่านอย่างเดียวของหัวหน้าแผนกยังต้องตอบกลับในงานลูกทีมได้
        // สิทธิ์จริงตัดสินที่ WorkOrderPolicy::comment() ฝั่ง server ที่เดียว
        'can_comment' => auth()->user()->can('comment', $task),
        'can_view_comments' => auth()->user()->can('viewComments', $task),
        'can_manage_team' => ! $forceReadOnly && auth()->user()->can('manageTeam', $task),
        'project' => $task->taskList?->name ?? 'งานทั่วไป',
        'status' => (int) $task->job_status,
        'submitted_by' => $task->reviewSubmitter?->name,
        'submitted_at' => optional($task->submitted_for_review_at)->translatedFormat('j M Y H:i'),
        'comment_url' => auth()->user()->can('comment', $task) ? route('tasks.comments.store', $task) : null,
        'read_comments_url' => auth()->user()->can('viewComments', $task) ? route('tasks.comments.read', $task) : null,
        // __COMMENT__ ถูกแทนที่ด้วย id จริงฝั่ง client เหมือนรูปแบบ remove_url ของทีมด้านบน
        'pin_comment_url' => auth()->user()->can('comment', $task) ? route('tasks.comments.pin', [$task, '__COMMENT__']) : null,
        'unpin_comment_url' => auth()->user()->can('comment', $task) ? route('tasks.comments.unpin', [$task, '__COMMENT__']) : null,
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
                    <strong id="team-modal-title">สมาชิกโปรเจกต์</strong>
                    <small data-team-topic></small>
                </span>
            </div>
            <button type="button" class="task-modal-close" data-close-team aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        </header>

        {{--
            Team Manager เป็น component เดียว ไม่มีรายการสมาชิกกับฟอร์มเพิ่มแยกกันอีกแล้ว
            คอลัมน์ซ้ายคือคนที่ยังเพิ่มได้ คอลัมน์ขวาคือหัวหน้าโปรเจกต์ ผู้เข้าร่วมปัจจุบัน
            และคนที่เตรียมเพิ่ม (ดู App\Support\ProjectRoles)
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
                        'title' => 'เพิ่มผู้เข้าร่วม',
                        'hint' => 'ค้นหาและเลือกคนที่ต้องการเพิ่มเข้าร่วมโปรเจกต์ เพิ่มข้ามแผนกได้ทันที',
                        'search' => 'ค้นหาชื่อ อีเมล หรือแผนก',
                        'emptyOptions' => 'ไม่มีพนักงานที่เพิ่มเข้าโปรเจกต์นี้ได้',
                        'emptySelected' => 'ยังไม่ได้เลือกใคร',
                        'countTemplate' => 'เลือกเพิ่ม :count คน',
                        'removeHint' => 'กด × เพื่อยกเลิก',
                        'help' => 'แสดงเฉพาะบัญชีที่เปิดใช้งานและยังไม่อยู่ในโปรเจกต์',
                        'help2' => 'การเปลี่ยนแผนกไม่ยกเลิกคนที่เลือกไว้',
                    ],
                ])

            </div>

            <footer class="team-manager__footer">
                <div class="team-manager__footer-primary">
                    <p class="team-manager__hint"><i class="bi bi-info-circle" aria-hidden="true"></i> ผู้ที่เลือกจะเข้าร่วมโปรเจกต์ทันทีเมื่อกดยืนยัน ไม่ต้องรออนุมัติ</p>
                    <p class="team-manager__notice" data-team-notice hidden></p>
                </div>
                <div class="team-manager__actions">
                    <button type="submit" class="notion-primary" data-team-submit aria-busy="false" disabled><i class="bi bi-person-plus" aria-hidden="true"></i> <span data-team-submit-label>เพิ่มผู้เข้าร่วม (0 คน)</span></button>
                    <button type="button" class="task-secondary" data-close-team>ปิด</button>
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

{{--
    กล่องดูรูปในคอมเมนต์

    เดิมรูปในฟองแชทเป็นลิงก์ที่พาออกไปเปิด URL ของไฟล์ในแท็บใหม่ ผู้ใช้จึงหลุดจากบทสนทนา
    ที่กำลังอ่านอยู่ทุกครั้งที่อยากดูรูปให้ชัด ที่นี่จึงเปิดเป็นชั้นทับใน Workspace แทน
    และยังมีทางเปิดไฟล์จริงไว้ให้สำหรับคนที่ต้องการโหลดหรือเปิดเต็มจอ
--}}
<div class="notion-modal comment-image-modal" data-comment-image-modal hidden>
    <section class="comment-image-modal__card" role="dialog" aria-modal="true" aria-labelledby="comment-image-modal-title">
        <header>
            <strong id="comment-image-modal-title" data-comment-image-name></strong>
            <div class="comment-image-modal__actions">
                <a class="comment-image-modal__open" data-comment-image-source target="_blank" rel="noopener" title="เปิดไฟล์จริงในแท็บใหม่"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span>เปิดไฟล์</span></a>
                <button type="button" class="task-modal-close" data-close-comment-image aria-label="ปิด"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
        </header>
        <div class="comment-image-modal__stage">
            <img data-comment-image-view src="" alt="">
        </div>
    </section>
</div>

<div class="notion-modal board-attachment-modal" data-board-attachment-modal hidden>
    <section class="board-attachment-modal__card" role="dialog" aria-modal="true" aria-labelledby="board-attachment-modal-title">
        <header>
            <div><span>ATTACHMENTS</span><strong id="board-attachment-modal-title">ไฟล์แนบ</strong><small data-board-attachment-topic></small></div>
            <div class="board-attachment-modal__actions">
                {{-- ใช้กล่องเลือกดาวน์โหลดใบเดียวกับการ์ดไฟล์แนบใน Task Workspace
                     ปุ่มจึงเป็นแค่ตัวเปิด ไม่มีตรรกะดาวน์โหลดของตัวเอง
                     ซ่อนไว้จนกว่างานนั้นจะมีไฟล์จริง เหมือนปุ่มบนหัวการ์ด --}}
                <button type="button" class="task-workspace__panel-button" data-open-attachment-download data-task-id="" hidden>
                    <i class="bi bi-download" aria-hidden="true"></i> ดาวน์โหลด
                </button>
                <button type="button" class="task-modal-close" data-close-board-attachments aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
            </div>
        </header>
        <div class="board-attachment-modal__body">
            <div class="board-attachment-modal__list" data-board-attachment-list></div>
            <div class="board-attachment-modal__empty" data-board-attachment-empty hidden><i class="bi bi-paperclip"></i><strong>ยังไม่มีไฟล์แนบ</strong><span>ยังไม่มีเอกสารหรือรูปภาพในงานนี้</span></div>
            <div class="board-attachment-modal__upload" data-board-attachment-upload>
                <label><i class="bi bi-cloud-arrow-up"></i><span><strong>เพิ่มไฟล์แนบ</strong><small>{{ \App\Support\AttachmentPolicy::limitsLabel() }}</small></span><input type="file" multiple data-board-modal-attachment-input accept="{{ \App\Support\AttachmentPolicy::acceptAttribute() }}"></label>
            </div>
            <p class="task-workspace__locked-notice" data-board-attachment-locked hidden><i class="bi bi-lock-fill" aria-hidden="true"></i><span>งานนี้ปิดแล้ว ไม่สามารถแนบไฟล์ได้ หากต้องการแนบไฟล์ กรุณาเปิดงานอีกครั้ง</span></p>
        </div>
        <footer><button type="button" class="task-secondary" data-close-board-attachments>ปิด</button></footer>
    </section>
</div>

{{--
    กล่อง "ดาวน์โหลดไฟล์แนบ"

    การ์ดไฟล์แนบใน Task Workspace เปิดดูไฟล์ได้ทีละไฟล์เท่านั้น ไม่มีปุ่มดาวน์โหลด
    และเลือกหลายรายการพร้อมกันไม่ได้ กล่องนี้คือที่เดียวที่ทำสองเรื่องนั้น

    เป็น modal จริง (มี backdrop / aria-modal / ล็อก scroll) ผ่าน modalStack เท่านั้น
    จึงซ้อนบน Task Workspace ได้โดยไม่มี backdrop สองชั้นทับกัน
--}}
<div class="notion-modal attachment-download-modal" data-attachment-download-modal hidden>
    <section class="attachment-download-modal__card" role="dialog" aria-modal="true" aria-labelledby="attachment-download-title">
        <header>
            <div>
                <span>DOWNLOAD</span>
                <strong id="attachment-download-title">ดาวน์โหลดไฟล์แนบ</strong>
                <small data-attachment-download-topic></small>
            </div>
            <button type="button" class="task-modal-close" data-close-attachment-download aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        </header>
        <div class="attachment-download-modal__body">
            <div class="attachment-download-modal__toolbar" data-attachment-download-toolbar>
                <label class="attachment-download-modal__all">
                    <input type="checkbox" data-attachment-download-all checked>
                    <span>เลือกทั้งหมด</span>
                </label>
                <span class="attachment-download-modal__count" data-attachment-download-count></span>
            </div>
            <div class="attachment-download-modal__list" data-attachment-download-list role="group" aria-labelledby="attachment-download-title"></div>
            <p class="attachment-download-modal__empty" data-attachment-download-empty hidden>
                <i class="bi bi-paperclip" aria-hidden="true"></i>
                <strong>ยังไม่มีไฟล์แนบ</strong>
                <span>งานนี้ยังไม่มีเอกสารหรือรูปภาพให้ดาวน์โหลด</span>
            </p>
            <p class="attachment-download-modal__status" data-attachment-download-status role="status" aria-live="polite" hidden></p>
        </div>
        <footer>
            <p class="attachment-download-modal__hint"><i class="bi bi-info-circle" aria-hidden="true"></i> เลือกหลายไฟล์ได้ เบราว์เซอร์อาจถามยืนยันการบันทึกหลายไฟล์ครั้งแรก</p>
            <div class="attachment-download-modal__actions">
                <button type="button" class="notion-primary" data-attachment-download-start disabled>
                    <i class="bi bi-download" aria-hidden="true"></i> <span data-attachment-download-label>ดาวน์โหลด</span>
                </button>
                <button type="button" class="task-secondary" data-close-attachment-download>ปิด</button>
            </div>
        </footer>
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
    $priorityOptions = [3 => ['สำคัญด่วน', 'urgent'], 4 => ['ด่วนไม่ค่อยสำคัญ', 'quick'], 2 => ['สำคัญไม่ด่วน', 'important'], 5 => ['ไม่รีบ ไม่มีกำหนด', 'flexible']];
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
                    <input type="datetime-local" data-date-picker data-default-time="{{ \App\Support\TodayWorkspace::DEFAULT_START_TIME }}" name="job_start_at" class="task-workspace__date" required>
                </span>
            </label>

            <label class="task-workspace__cell">
                <span class="task-workspace__cell-icon tone-date"><i class="bi bi-calendar-check" aria-hidden="true"></i></span>
                <span class="task-workspace__cell-body">
                    <span class="task-workspace__cell-label">กำหนดส่ง</span>
                    <input type="datetime-local" data-date-picker data-default-time="{{ \App\Support\TodayWorkspace::DEFAULT_DUE_TIME }}" name="job_due_at" class="task-workspace__date" required>
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
                    <span class="task-workspace__cell-label">สมาชิกโปรเจกต์</span>
                    <button type="button" class="task-workspace__cell-action" data-manage-team>จัดการผู้เข้าร่วม</button>
                </span>
            </div>
        </section>

        <div class="task-workspace__panels">
            <section class="task-workspace__panel task-workspace__panel--files" data-task-attachments aria-labelledby="task-workspace-files-title">
                <header class="task-workspace__panel-head">
                    <span class="task-workspace__panel-icon"><i class="bi bi-paperclip" aria-hidden="true"></i></span>
                    <strong id="task-workspace-files-title">ไฟล์แนบ</strong>
                    {{-- เปิดกล่องเลือกไฟล์เพื่อดาวน์โหลด ซ่อนไว้จนกว่างานนี้จะมีไฟล์แนบจริง --}}
                    <button type="button" class="task-workspace__panel-button" data-open-attachment-download hidden>
                        <i class="bi bi-download" aria-hidden="true"></i> ดาวน์โหลด
                    </button>
                    <label class="task-workspace__add-file" data-task-inline-drop>
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่มไฟล์
                        <input type="file" multiple data-task-inline-file-input accept="{{ \App\Support\AttachmentPolicy::acceptAttribute() }}">
                    </label>
                </header>
                {{-- ทั้งพื้นที่นี้คือ drop zone ไม่ใช่แค่ปุ่ม "เพิ่มไฟล์" เล็ก ๆ ที่หัวการ์ด
                     ผู้ใช้ลากไฟล์มาที่กรอบว่างกลางการ์ดเป็นธรรมชาติที่สุด --}}
                <div class="task-workspace__panel-body" data-task-drop-zone>
                    <div class="task-inline-files" data-task-inline-files></div>
                    <p class="task-workspace__file-types" data-attachment-types>
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        เลือกหรือลากไฟล์หลายรายการมาวางที่นี่ได้ — รองรับ {{ \App\Support\AttachmentPolicy::limitsLabel() }}
                    </p>
                    <p class="task-workspace__locked-notice" data-attachment-locked hidden><i class="bi bi-lock-fill" aria-hidden="true"></i><span>งานนี้ปิดแล้ว ไม่สามารถแนบไฟล์ได้ หากต้องการแนบไฟล์ กรุณาเปิดงานอีกครั้ง</span></p>
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
                {{--
                    แถบข้อความที่ปักหมุดไว้ — ปักได้ทีละ 1 ข้อความต่องาน กดที่ข้อความเพื่อเลื่อนไปดู
                    ต้นฉบับ หรือกด × เพื่อเลิกปักหมุด (คนที่คอมเมนต์ได้ก็ปักหมุดได้เหมือนกัน)
                --}}
                <div class="task-timeline__pinned" data-comment-pinned hidden>
                    <i class="bi bi-pin-angle-fill" aria-hidden="true"></i>
                    <button type="button" class="task-timeline__pinned-body" data-comment-pinned-jump>
                        <strong data-comment-pinned-author></strong>
                        <span data-comment-pinned-note></span>
                    </button>
                    <button type="button" class="task-timeline__pinned-unpin" data-comment-pinned-unpin title="เลิกปักหมุด" aria-label="เลิกปักหมุด"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="task-workspace__timeline-items" data-timeline-items></div>
                {{--
                    แถบพรีวิวอยู่เหนือช่องพิมพ์ ผู้ใช้จึงเห็นว่ากำลังจะส่งรูปอะไรไปก่อนกดส่ง
                    และเอาออกทีละใบได้ ไม่ใช่ต้องล้างทั้งหมดแล้วเลือกใหม่
                --}}
                <div class="task-timeline__previews" data-comment-image-preview hidden></div>
                {{--
                    แถบข้อความที่กำลังตอบกลับแบบ quote คล้าย Facebook กด × เพื่อยกเลิก

                    ต้องมีป้าย "กำลังตอบกลับ" ชัดเจน ไม่ใช่แค่ชื่อคนกับข้อความลอย ๆ
                    ไม่งั้นผู้พิมพ์จะไม่แน่ใจว่าแถบนี้คือข้อความที่กำลังตอบกลับอยู่
                --}}
                <div class="task-timeline__reply-preview" data-comment-reply-preview hidden>
                    <i class="bi bi-reply-fill" aria-hidden="true"></i>
                    {{-- รูปย่อของข้อความต้นทาง ถูกเติมโดย JS เมื่อคอมเมนต์ที่ตอบกลับมีรูป --}}
                    <span class="task-timeline__reply-preview-thumb" data-comment-reply-thumb hidden></span>
                    <div class="task-timeline__reply-preview-body">
                        <small>กำลังตอบกลับ</small>
                        <span><strong data-comment-reply-author></strong> <span data-comment-reply-note></span></span>
                    </div>
                    <button type="button" class="task-timeline__reply-cancel" data-cancel-comment-reply title="ยกเลิกการตอบกลับ" aria-label="ยกเลิกการตอบกลับ"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="task-timeline__compose">
                    {{-- accept จำกัดที่หน้าจอเพื่อความสะดวก สิทธิ์และชนิดไฟล์จริงตรวจที่ TaskCommentController --}}
                    <label class="task-timeline__attach" title="แนบรูปภาพ">
                        <input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden data-comment-image-input>
                        <i class="bi bi-image" aria-hidden="true"></i>
                        <span class="visually-hidden">แนบรูปภาพ</span>
                    </label>
                    <div class="task-timeline__note-wrap">
                        {{-- rows=4 เพื่อให้สูงพอเห็นสิ่งที่พิมพ์ไปแล้วตั้งแต่ก่อน CSS โหลด --}}
                        <textarea data-task-update-note maxlength="2000" rows="4" placeholder="เขียนอัปเดต... พิมพ์ @ เพื่อกล่าวถึงเพื่อนร่วมงาน" aria-label="เขียนอัปเดต"></textarea>
                        {{-- popover ไม่ใช่ modal: ไม่ล็อก scroll ของหน้า ปิดด้วย Escape/คลิกนอกกล่อง --}}
                        <ul class="task-timeline__mention-menu" data-comment-mention-menu hidden role="listbox" aria-label="เลือกคนที่จะกล่าวถึง"></ul>
                    </div>
                    <button type="button" data-submit-task-update aria-label="ส่งอัปเดต"><i class="bi bi-send-fill" aria-hidden="true"></i></button>
                </div>
                <p class="task-workspace__locked-notice task-workspace__locked-notice--comment" data-comment-locked hidden><i class="bi bi-lock-fill" aria-hidden="true"></i><span>งานนี้ปิดแล้ว ไม่สามารถเพิ่มคอมเมนต์ได้ หากต้องการคอมเมนต์ กรุณาเปิดงานอีกครั้ง</span></p>
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
