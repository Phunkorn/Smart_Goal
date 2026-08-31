@php
    /** Shared two-step Admin assignment flow. */
    $openOnLoad = $openOnLoad ?? false;
    $defaultAssigneeId = (int) ($defaultAssigneeId ?? 0);
    $preselectAssigneeId = (int) ($preselectAssigneeId ?? 0);
    $assignmentOrigin = $assignmentOrigin ?? null;
    $projectOptions = collect($projectOptions ?? [])->unique('id')->values();
    $startsWithTask = ($startWithTask ?? false) && $projectOptions->isNotEmpty();
    $initialAssigneeId = $defaultAssigneeId ?: $preselectAssigneeId;
    $initialStart = now()->format('Y-m-d\TH:i');
    $initialDue = now()->addDay()->format('Y-m-d\TH:i');
@endphp

<div class="modal fade admin-assignment-flow" id="boardCreateTaskModal" tabindex="-1"
    aria-labelledby="boardCreateTaskModalLabel" aria-hidden="true"
    data-admin-assignment-modal data-initial-step="{{ $startsWithTask ? 'task' : 'project' }}"
    data-default-assignee-id="{{ $defaultAssigneeId }}" data-preselect-assignee-id="{{ $preselectAssigneeId }}"
    @if($openOnLoad) data-open-on-load="1" @endif>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <header class="modal-header board-modal-head">
                <div class="admin-assignment-heading">
                    <span class="admin-assignment-heading__icon" aria-hidden="true"><i class="bi bi-folder-plus"></i></span>
                    <div><span class="admin-assignment-heading__eyebrow">ADMIN ASSIGNMENT</span><h2 class="modal-title" id="boardCreateTaskModalLabel" data-assignment-title>สร้างโปรเจกต์</h2><p data-assignment-subtitle>สร้างพื้นที่โปรเจกต์ก่อน แล้วจึงเพิ่มและมอบหมายรายการงาน</p></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดหน้าต่าง"></button>
            </header>

            <div class="admin-assignment-progress" aria-label="ขั้นตอนการมอบหมายงาน">
                <span data-step-indicator="project"><b>1</b> โปรเจกต์</span><i aria-hidden="true"></i><span data-step-indicator="task"><b>2</b> รายการงาน</span>
            </div>
            <div class="alert alert-danger admin-assignment-error" role="alert" data-admin-assignment-errors hidden></div>

            <form action="{{ route('mytasks.create') }}" method="POST" enctype="multipart/form-data" data-admin-project-form @if($startsWithTask) hidden @endif>
                @csrf
                @if($initialAssigneeId)
                    <input type="hidden" name="project_owner_id" value="{{ $initialAssigneeId }}">
                @endif
                <div class="modal-body admin-assignment-panel">
                    <section class="admin-assignment-intro"><i class="bi bi-folder2-open" aria-hidden="true"></i><div><strong>ข้อมูลโปรเจกต์</strong><span>โปรเจกต์ว่างได้ และเพิ่มรายการงานภายหลังได้เสมอ</span></div></section>
                    <div class="project-create-body admin-project-fields">@include('tasks.components.project-form-fields')</div>
                </div>
                <footer class="modal-footer board-modal-foot">
                    <button type="button" class="admin-assignment-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="admin-assignment-primary" data-project-submit><span>สร้างโปรเจกต์และไปเพิ่มงาน</span><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                </footer>
            </form>

            <form action="{{ route('tasks.store') }}" method="POST" enctype="multipart/form-data" data-admin-task-form @if(!$startsWithTask) hidden @endif>
                @csrf
                <input type="hidden" name="work_order_list_id" data-selected-project-id value="{{ $startsWithTask ? $projectOptions->first()?->id : '' }}">
                @if($assignmentOrigin)
                    <input type="hidden" name="assignment_origin" value="admin-member"><input type="hidden" name="origin_department_id" value="{{ $assignmentOrigin['department_id'] }}"><input type="hidden" name="origin_member_id" value="{{ $assignmentOrigin['member_id'] }}">
                @endif

                <div class="modal-body admin-assignment-panel">
                    <section class="admin-project-choice">
                        <label for="adminAssignmentProject">โปรเจกต์ <span aria-hidden="true">*</span></label>
                        <div><select id="adminAssignmentProject" class="form-select" data-project-select required><option value="">เลือกโปรเจกต์</option>@foreach($projectOptions as $project)<option value="{{ $project->id }}" @selected($startsWithTask && $loop->first)>{{ $project->name }}</option>@endforeach</select><button type="button" data-create-another-project><i class="bi bi-plus-lg" aria-hidden="true"></i> สร้างโปรเจกต์ใหม่</button></div>
                        <small>รายการงานนี้จะอยู่ภายใต้โปรเจกต์ที่เลือก</small>
                    </section>

                    <section class="board-project-task" data-admin-task>
                        <div class="admin-task-heading"><div><strong>รายละเอียดรายการงาน</strong><span>งานใหม่จะเริ่มที่สถานะ “ยังไม่เริ่ม”</span></div><span class="admin-task-status"><i aria-hidden="true"></i> ยังไม่เริ่ม</span></div>
                        <div class="row g-3">
                            <div class="col-md-7"><label class="form-label" for="adminTaskTopic">ชื่องาน <span aria-hidden="true">*</span></label><input type="text" id="adminTaskTopic" name="job_topic" class="form-control form-control-lg" maxlength="255" placeholder="เช่น ตรวจสอบเอกสารโครงการ" required></div>
                            <div class="col-md-5">
                                <label class="form-label">ผู้รับผิดชอบ <span aria-hidden="true">*</span></label>
                                <div class="assignee-picker dropdown"><button type="button" class="assignee-picker-toggle form-control form-control-lg d-flex align-items-center justify-content-between dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"><span class="assignee-picker-label text-muted">เลือกผู้รับผิดชอบ...</span></button><div class="dropdown-menu assignee-picker-menu p-2 w-100"><input type="search" class="form-control form-control-sm mb-2" data-task-assignee-search placeholder="ค้นหาชื่อหรือแผนก" aria-label="ค้นหาผู้รับผิดชอบ" autocomplete="off"><div class="assignee-picker-list">@foreach($employees as $employee)<button type="button" class="assignee-option" data-id="{{ $employee->id }}" data-name="{{ $employee->name }}" data-dept="{{ optional($employee->department)->department_name ?? 'ไม่ระบุแผนก' }}" data-search="{{ Str::lower($employee->name.' '.optional($employee->department)->department_name) }}"><span class="avatar-mini">{{ mb_substr($employee->name, 0, 2) }}</span><strong>{{ $employee->name }}</strong><span class="assignee-option-dept">{{ optional($employee->department)->department_name ?? 'ไม่ระบุแผนก' }}</span></button>@endforeach</div><div class="text-muted small text-center py-2 d-none" data-task-assignee-empty>ไม่พบพนักงานที่ตรงกับคำค้นหา</div></div></div>
                                <input type="hidden" name="user_id" data-task-assignee value="{{ $initialAssigneeId ?: '' }}" required>
                            </div>
                            <div class="col-md-6"><label class="form-label" for="adminTaskStart">วันที่เริ่ม <span aria-hidden="true">*</span></label><input type="datetime-local" id="adminTaskStart" name="job_start_at" class="form-control" value="{{ $initialStart }}" required></div>
                            <div class="col-md-6"><label class="form-label" for="adminTaskDue">กำหนดส่ง <span aria-hidden="true">*</span></label><input type="datetime-local" id="adminTaskDue" name="job_due_at" class="form-control" value="{{ $initialDue }}" required></div>
                            <div class="col-12"><label class="form-label" for="adminTaskPriority">ความสำคัญ</label><select id="adminTaskPriority" name="job_priority" class="form-select">@foreach(\App\Support\WorkBoardDesign::TASK_PRIORITIES as $value => $meta)<option value="{{ $value }}" @selected($value === 2)>{{ $meta['label'] }}</option>@endforeach</select></div>
                            <div class="col-12">
                                <details class="admin-task-options"><summary><span><i class="bi bi-sliders" aria-hidden="true"></i> ตัวเลือกเพิ่มเติม</span><small>รายละเอียด ผู้ร่วมงาน และไฟล์แนบ</small></summary><div class="admin-task-options__body">
                                    <label><span>รายละเอียดงาน</span><textarea name="job_details" class="form-control" maxlength="2000" placeholder="ข้อมูลที่ผู้รับผิดชอบควรรู้"></textarea></label>
                                    <div><div class="admin-optional-heading"><span>ผู้ร่วมงาน</span><small>ไม่บังคับ</small></div><select class="form-select mb-2" data-task-collaborator-department aria-label="เลือกแผนกของผู้ร่วมงาน"><option value="">เลือกแผนกก่อน</option>@foreach($departments as $collaboratorDepartment)<option value="{{ $collaboratorDepartment->id }}">{{ $collaboratorDepartment->department_name }}</option>@endforeach</select><input type="search" class="form-control mb-2 d-none" data-task-collaborator-search disabled placeholder="ค้นหาชื่อพนักงาน" aria-label="ค้นหาผู้ร่วมงาน" autocomplete="off"><div class="row g-2 board-collaborator-list">@foreach($employees as $employee)<div class="col-md-6 board-collab-item d-none" data-department-id="{{ $employee->department_id }}" data-search="{{ Str::lower($employee->name.' '.optional($employee->department)->department_name) }}"><label class="board-collaborator-choice"><input type="checkbox" name="collaborators[]" value="{{ $employee->id }}"><span class="avatar-mini">{{ mb_substr($employee->name, 0, 2) }}</span><span><strong>{{ $employee->name }}</strong><small>{{ optional($employee->department)->department_name ?? '-' }}</small></span></label></div>@endforeach<div class="board-collaborator-hint" data-task-collaborator-hint>เลือกแผนกก่อนเพื่อดูรายชื่อผู้ร่วมงาน</div></div></div>
                                    <label><span>ไฟล์แนบของงาน <small>ไม่บังคับ · สูงสุด 5 ไฟล์</small></span><input type="file" name="attachments[]" class="form-control" data-task-attachments accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx" multiple><b class="text-danger small d-none" data-task-attachments-error></b></label>
                                </div></details>
                            </div>
                        </div>
                    </section>
                </div>
                <footer class="modal-footer board-modal-foot">
                    <button type="button" class="admin-assignment-secondary" data-back-to-project><i class="bi bi-arrow-left" aria-hidden="true"></i> โปรเจกต์</button><span class="admin-assignment-footer-spacer"></span><button type="button" class="admin-assignment-secondary" data-bs-dismiss="modal">ไว้เพิ่มภายหลัง</button><button type="submit" class="admin-assignment-secondary admin-assignment-save-next" value="next" data-task-submit="next">บันทึกและเพิ่มงานถัดไป</button><button type="submit" class="admin-assignment-primary" value="done" data-task-submit="done"><i class="bi bi-check2-square" aria-hidden="true"></i> มอบหมายงาน</button>
                </footer>
            </form>
        </div>
    </div>
</div>
