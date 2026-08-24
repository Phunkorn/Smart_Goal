<?php
    $workspaceContext = $workspaceContext ?? 'user';
    $showCreateActions = $showCreateActions ?? true;
    $teamData = $allTasks->mapWithKeys(fn ($task) => [(string) $task->job_id => [
        'id' => $task->job_id,
        'topic' => $task->job_topic,
        'locked' => (int) $task->job_status === 4 && auth()->user()->role !== 'admin',
        'can_manage' => auth()->user()->can('manageTeam', $task),
        'add_url' => route('tasks.collaborators.store', $task->job_id),
        'remove_url' => route('tasks.collaborators.destroy', [$task->job_id, '__USER__']),
        'assignee' => ['id' => $task->user?->id, 'name' => $task->user?->name ?? 'ไม่ระบุ', 'department' => $task->user?->department?->department_name],
        'collaborators' => $task->collaborators->map(fn ($person) => [
            'id' => $person->id,
            'name' => $person->name,
            'department' => $person->department?->department_name,
            'status' => $person->pivot?->status ?? 'pending',
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
        'can_upload' => auth()->user()->can('update', $task),
        'upload_url' => route('tasks.attachments.store', $task->job_id),
        'files' => $task->images->map(fn ($file) => [
            'name' => $file->original_name ?? basename($file->file_path),
            'url' => route('media.task-attachments.show', $file),
            'delete_url' => route('tasks.attachments.destroy', [$task->job_id, $file]),
        ])->values(),
    ]]);
?>
<script type="application/json" data-attachment-data>@json($attachmentData)</script>
<?php
    $timelineData = $allTasks->mapWithKeys(fn ($task) => [(string) $task->job_id => ['updates' => $task->updates->map(fn ($update) => ['id' => $update->id, 'author' => $update->user?->name ?? 'ไม่ระบุ', 'avatar_url' => $update->user?->profile_image ? route('media.profile', $update->user) : null, 'note' => $update->note, 'at' => optional($update->created_at)->translatedFormat('j M Y H:i')])->values(), 'activity' => $task->activityLogs->map(fn ($log) => ['author' => $log->user?->name ?? 'ระบบ', 'avatar_url' => $log->user?->profile_image ? route('media.profile', $log->user) : null, 'note' => $log->description, 'at' => optional($log->created_at)->translatedFormat('j M Y H:i')])->values()]]);
?>
<script type="application/json" data-timeline-data>@json($timelineData)</script>
<?php
    $taskManagementData = $allTasks->mapWithKeys(fn ($task) => [(string) $task->job_id => [
        'transitions' => app(\App\Services\TaskStatusTransitionService::class)->capabilities($task, auth()->user()),
        'status' => (int) $task->job_status,
        'submitted_by' => $task->reviewSubmitter?->name,
        'submitted_at' => optional($task->submitted_for_review_at)->translatedFormat('j M Y H:i'),
        'comment_url' => route('tasks.comments.store', $task),
        'read_comments_url' => route('tasks.comments.read', $task),
        'unread_comments' => (int) ($unreadCommentCounts[$task->job_id] ?? 0),
    ]]);
?>
<script type="application/json" data-task-management-data>@json($taskManagementData)</script>

<div class="team-modal notion-modal" data-team-modal hidden>
    <section class="team-modal-card" role="dialog" aria-modal="true" aria-labelledby="team-modal-title">
        <header>
            <div><span class="task-edit-kicker">PROJECT TEAM</span><strong id="team-modal-title">ผู้รับผิดชอบและผู้ร่วมงาน</strong><small data-team-topic></small></div>
            <button type="button" class="task-modal-close" data-close-team aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        </header>
        <div class="team-modal-body">
            <section class="team-owner-card"><span class="team-section-label">ผู้รับผิดชอบหลัก</span><div data-team-owner></div></section>
            <section class="team-members-panel">
                <div class="team-section-heading"><div><strong>ผู้ร่วมงาน</strong><small>ผู้ที่เข้าร่วมและคำเชิญที่กำลังรอตอบรับ</small></div><span data-team-count>0 คน</span></div>
                <div class="team-member-list" data-team-members></div>
                <div class="team-empty" data-team-empty hidden><i class="bi bi-people"></i><strong>ยังไม่มีผู้ร่วมงาน</strong><span>เพิ่มสมาชิกเพื่อช่วยกันดำเนินงานนี้</span></div>
            </section>
            <form class="team-invite" data-team-form>
                <div><strong>เพิ่มผู้ร่วมงาน</strong><small>เลือกได้หลายคน ระบบจะแสดงสถานะคำเชิญให้ชัดเจน</small></div>
                <select name="collaborators[]" multiple size="5" required aria-label="เลือกผู้ร่วมงาน">
                    @foreach($availableCollaborators as $person)<option value="{{ $person->id }}">{{ $person->name }} · {{ $person->department?->department_name ?? 'ไม่ระบุแผนก' }}</option>@endforeach
                </select>
                <button type="submit" class="notion-primary"><i class="bi bi-person-plus"></i> เพิ่มผู้ร่วมงาน</button>
                <p data-team-notice hidden></p>
            </form>
        </div>
        <footer><button type="button" class="task-secondary" data-close-team>ปิด</button></footer>
    </section>
</div>

<div class="notion-modal owner-modal" data-owner-modal hidden>
    <section class="owner-modal-card" role="dialog" aria-modal="true" aria-labelledby="owner-modal-title">
        <button type="button" class="task-modal-close owner-modal-close" data-close-owner aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        <div class="owner-modal-avatar" data-owner-avatar></div>
        <strong id="owner-modal-title" data-owner-name></strong>
    </section>
</div>

<div class="task-edit-modal notion-modal" data-attachment-modal hidden>
    <section class="task-edit-card attachment-modal-card" role="dialog" aria-modal="true" aria-labelledby="attachment-modal-title">
        <header>
            <div><span class="task-edit-kicker">ATTACHMENTS</span><strong id="attachment-modal-title">ไฟล์แนบ</strong><small data-attachment-topic></small></div>
            <button type="button" class="task-modal-close" data-close-attachments aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        </header>
        <div class="attachment-modal-body">
            <div class="attachment-modal-list" data-attachment-list></div>
            <div class="attachment-modal-empty" data-attachment-empty hidden><i class="bi bi-paperclip"></i><strong>ยังไม่มีไฟล์แนบ</strong><span>เพิ่มเอกสารหรือรูปภาพที่เกี่ยวข้องกับงานนี้ได้ด้านล่าง</span></div>
            <div class="attachment-modal-upload" data-attachment-upload>
                <label><i class="bi bi-cloud-arrow-up"></i><span><strong>เพิ่มไฟล์แนบ</strong><small>JPG, PNG, Word, Excel, PowerPoint · สูงสุด 10 MB/ไฟล์ · รวมไม่เกิน 5 ไฟล์</small></span><input type="file" multiple data-modal-attachment-input accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx"></label>
            </div>
        </div>
        <footer><button type="button" class="task-secondary" data-close-attachments>ปิด</button></footer>
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
                <label><i class="bi bi-cloud-arrow-up"></i><span><strong>เพิ่มไฟล์แนบ</strong><small>JPG, PNG, Word, Excel, PowerPoint · สูงสุด 10 MB/ไฟล์ · รวมไม่เกิน 5 ไฟล์</small></span><input type="file" multiple data-board-modal-attachment-input accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx"></label>
            </div>
        </div>
        <footer><button type="button" class="task-secondary" data-close-board-attachments>ปิด</button></footer>
    </section>
</div>

@if($showCreateActions)
<div class="notion-modal" data-create-modal hidden>
    <form class="notion-modal-card project-create-card" data-create-form enctype="multipart/form-data">
        <header><div><span class="task-edit-kicker">NEW PROJECT</span><strong>เพิ่มโปรเจกต์ใหม่</strong><small>สร้างพื้นที่โปรเจกต์ก่อน แล้วเพิ่มรายการงานภายหลัง</small></div><button type="button" class="task-modal-close" data-close-create aria-label="ปิด"><i class="bi bi-x-lg"></i></button></header>
        <div class="modal-body project-create-body">
            <label class="project-create-name"><span>ชื่อโปรเจกต์</span><div class="project-input-shell"><i class="bi bi-folder"></i><input name="project_name" maxlength="80" required placeholder="เช่น ปรับปรุงเว็บไซต์บริษัท"></div></label>
            <label><span>ความสำคัญของโปรเจกต์</span><div class="project-input-shell"><i class="bi bi-flag"></i><select name="project_priority"><option value="1">ต่ำ</option><option value="2" selected>กลาง</option><option value="3">สูง</option></select></div></label>
            <label class="project-create-files"><span>ไฟล์แนบของโปรเจกต์ <em>สูงสุด 5 ไฟล์</em></span><div class="project-file-drop"><i class="bi bi-cloud-arrow-up"></i><div><strong>เลือกไฟล์ที่เกี่ยวข้อง</strong><small>JPG, PNG, Word, Excel, PowerPoint · สูงสุด 10 MB ต่อไฟล์</small></div><input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx"></div></label>
        </div>
        <footer><button type="button" class="task-secondary" data-close-create>ยกเลิก</button><button class="notion-primary" type="submit"><i class="bi bi-plus-lg"></i> สร้างโปรเจกต์</button></footer>
    </form>
</div>
@endif

<div class="task-edit-modal notion-modal my-tasks-page" data-task-modal hidden>
    <form class="task-edit-card" data-task-form>
        <header>
            <div><span class="task-edit-kicker">TASK DETAILS</span><strong>รายละเอียดงาน</strong><small>แก้ไขข้อมูลและบันทึกได้จากหน้านี้</small></div>
            <button type="button" class="task-modal-close" data-close-task aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        </header>
<div class="task-edit-body">

    <div class="task-detail-section-head">
        <div>
            <strong>รายละเอียดงาน</strong>
            <span>ข้อมูลและการตั้งค่าของงาน</span>
        </div>
    </div>

    <label class="task-field full">
        <span>ชื่องาน</span>
        <input name="job_topic" maxlength="255" required>
    </label>

    <label class="task-field full">
        <span>รายละเอียดงาน</span>
        <textarea
            name="job_details"
            rows="4"
            maxlength="5000"
            placeholder="อธิบายเป้าหมาย ผลลัพธ์ หรือข้อมูลที่เกี่ยวข้อง"
        ></textarea>
    </label>

    <div class="task-detail-divider"></div>

    <label class="task-field">
        <span>สถานะ</span>
        <select name="job_status" hidden aria-hidden="true" tabindex="-1"><option value="1">ยังไม่เริ่ม</option><option value="2">กำลังทำ</option><option value="3">รอตรวจสอบ</option><option value="4">เสร็จแล้ว</option><option value="5">พักงาน</option><option value="6">ล่าช้า</option></select>
        <details class="board-status-menu modal-status-menu" data-modal-status-menu><summary class="board-status-pill"><span data-modal-status-label></span><i class="bi bi-chevron-down"></i></summary><div>@foreach([1=>['ยังไม่เริ่ม','todo'],2=>['กำลังทำ','progress'],3=>['รอตรวจสอบ','review'],4=>['เสร็จแล้ว','done'],5=>['พักงาน','paused'],6=>['ล่าช้า','late']] as $value=>$meta)<button type="button" class="status-{{ $meta[1] }}" data-modal-status-value="{{ $value }}">{{ $meta[0] }}</button>@endforeach</div></details>
    </label>

    <label class="task-field">
        <span>ความสำคัญ</span>
        <select name="job_priority" hidden aria-hidden="true" tabindex="-1"><option value="3">สำคัญด่วน</option><option value="4">ด่วนไม่ค่อยสำคัญ</option><option value="2">สำคัญไม่ด่วน</option><option value="5">ไม่รีบ ไม่มีกำหนด</option><option value="1">routine</option></select>
        <details class="board-priority-menu modal-priority-menu" data-modal-priority-menu><summary class="board-priority"><span data-modal-priority-label></span><i class="bi bi-chevron-down"></i></summary><div>@foreach([3=>['สำคัญด่วน','urgent'],4=>['ด่วนไม่ค่อยสำคัญ','quick'],2=>['สำคัญไม่ด่วน','important'],5=>['ไม่รีบ ไม่มีกำหนด','flexible'],1=>['routine','routine']] as $value=>$meta)<button type="button" class="priority-{{ $meta[1] }}" data-modal-priority-value="{{ $value }}"><i class="bi bi-flag-fill"></i>{{ $meta[0] }}</button>@endforeach</div></details>
    </label>
    <label class="task-field">
        <span>วันที่เริ่ม</span>
        <input type="date" name="job_start_at" @readonly($workspaceContext !== 'admin-member')>
    </label>

    <label class="task-field">
        <span>กำหนดส่ง</span>
        <input type="date" name="job_due_at" required>
    </label>



    <label class="task-field">
        <span>ผู้รับผิดชอบ</span>
        <input name="assignee" readonly>
    </label>

    <div class="task-field task-collaborator-field">
        <span>ผู้ร่วมงาน</span>
        <button type="button" class="task-collaborator-button" data-manage-team><i class="bi bi-people"></i><span>เพิ่มผู้ร่วมงาน</span></button>
    </div>
    <section class="task-field task-attachment-field" data-task-attachments>
        <span>ไฟล์แนบ</span>
        <div class="task-inline-files" data-task-inline-files></div>
        <label class="task-inline-drop" data-task-inline-drop><i class="bi bi-cloud-arrow-up"></i><strong>เลือกไฟล์หรือวางไฟล์ที่นี่</strong><small>JPG, PNG, Word, Excel, PowerPoint · สูงสุด 10 MB/ไฟล์ · รวมไม่เกิน 5 ไฟล์</small><input type="file" multiple data-task-inline-file-input accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx"></label>
    </section>

</div>
        <section class="task-timeline" data-task-timeline hidden>
            <nav><button type="button" class="active" data-timeline-tab="updates">อัปเดต</button><button type="button" data-timeline-tab="activity">กิจกรรม</button></nav>
            <div data-timeline-items></div>
            <div class="task-timeline__compose"><textarea data-task-update-note maxlength="2000" placeholder="เขียนอัปเดต..."></textarea><button type="button" data-submit-task-update aria-label="ส่งอัปเดต"><i class="bi bi-send-fill"></i></button></div>
        </section>
        <footer><button type="button" class="task-secondary" data-review-return hidden>ส่งกลับแก้ไข</button><button type="button" class="notion-primary" data-review-approve hidden>อนุมัติและปิดงาน</button><button type="button" class="notion-primary" data-reopen-task hidden>เปิดงานอีกครั้ง</button><button type="button" class="task-secondary" data-close-task>ยกเลิก</button><button type="submit" class="notion-primary">บันทึกการแก้ไข</button></footer>
    </form>
</div>
