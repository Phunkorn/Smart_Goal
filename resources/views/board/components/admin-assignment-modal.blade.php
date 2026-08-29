@php
    /**
     * โมดัล "สร้างโปรเจกต์และมอบหมายงาน" ที่ใช้ร่วมกันระหว่าง
     * Admin Board Overview (board.index) และ Admin Member Workspace
     * (admin.work-board.member) เพื่อให้มี implementation ชุดเดียว
     *
     * ตัวแปรที่รับได้:
     *  - $employees            รายชื่อผู้รับผิดชอบที่เลือกได้ (role user)
     *  - $departments          รายชื่อแผนกสำหรับตัวกรองผู้ร่วมงาน
     *  - $assignmentOrigin     ['department_id' => int, 'member_id' => int] เมื่อเปิดจาก Member Workspace
     *  - $defaultAssigneeId    ผู้รับผิดชอบเริ่มต้นของทุกงาน (รวมงานที่เพิ่มใหม่ใน Modal)
     *  - $preselectAssigneeId  ผู้รับผิดชอบเริ่มต้นเฉพาะงานแรก
     *  - $openOnLoad           เปิด Modal ทันทีที่โหลดหน้า
     */
    $assignmentOrigin = $assignmentOrigin ?? null;
    $defaultAssigneeId = $defaultAssigneeId ?? null;
    $preselectAssigneeId = $preselectAssigneeId ?? null;
    $openOnLoad = ($openOnLoad ?? false) || $errors->any();

    // เมื่อ validation ไม่ผ่าน Laravel จะพากลับมาที่หน้าเดิมพร้อม old input
    // จึง render แถวงานตามจำนวนที่ผู้ใช้กรอกไว้ แทนที่จะรีเซ็ตเหลืองานเดียว
    $oldTasks = old('tasks');
    $taskRows = is_array($oldTasks) && $oldTasks !== [] ? array_values($oldTasks) : [null];
@endphp

<div class="modal fade" id="boardCreateTaskModal" tabindex="-1" aria-hidden="true"
    data-admin-assignment-modal
    @if($openOnLoad) data-open-on-load="1" @endif
    @if($defaultAssigneeId) data-default-assignee-id="{{ $defaultAssigneeId }}" @endif
    @if($preselectAssigneeId) data-preselect-assignee-id="{{ $preselectAssigneeId }}" @endif>
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <form action="{{ route('admin.tasks.store') }}" method="POST" enctype="multipart/form-data" data-admin-project-form>
                @csrf
                @if($assignmentOrigin)
                    {{-- Origin ถูกตรวจซ้ำฝั่ง Server ก่อน redirect จึงไม่รับ URL ตรงจากฟอร์ม --}}
                    <input type="hidden" name="assignment_origin" value="admin-member">
                    <input type="hidden" name="origin_department_id" value="{{ $assignmentOrigin['department_id'] }}">
                    <input type="hidden" name="origin_member_id" value="{{ $assignmentOrigin['member_id'] }}">
                @endif
                <div class="modal-header board-modal-head">
                    <div>
                        <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>สร้างโปรเจกต์และมอบหมายงาน</h5>
                        <div class="text-muted small">สร้างโปรเจกต์หนึ่งรายการ พร้อมงานภายในอย่างน้อยหนึ่งงาน</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดหน้าต่างสร้างโปรเจกต์"></button>
                </div>

                <div class="modal-body">
                    @if($errors->any())
                        <div class="alert alert-danger" role="alert" data-admin-assignment-errors>
                            <strong>ยังบันทึกไม่ได้ กรุณาตรวจสอบข้อมูลต่อไปนี้</strong>
                            <ul class="mb-0 mt-2 ps-3">
                                @foreach($errors->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <section class="board-project-fields mb-4" aria-labelledby="boardProjectHeading">
                        <h6 id="boardProjectHeading" class="fw-bold mb-3">ข้อมูลโปรเจกต์</h6>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold" for="boardProjectName">ชื่อโปรเจกต์ <span class="text-danger">*</span></label>
                                <input type="text" name="project_name" id="boardProjectName" class="form-control @error('project_name') is-invalid @enderror" maxlength="80" value="{{ old('project_name') }}" required>
                                @error('project_name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold" for="boardProjectPriority">ความสำคัญโปรเจกต์</label>
                                <select name="project_priority" id="boardProjectPriority" class="form-select" required>
                                    @foreach([1 => 'ต่ำ', 2 => 'กลาง', 3 => 'สูง'] as $priorityValue => $priorityLabel)
                                        <option value="{{ $priorityValue }}" @selected((int) old('project_priority', 2) === $priorityValue)>{{ $priorityLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold" for="boardProjectAttachments">ไฟล์แนบโปรเจกต์</label>
                                <input type="file" name="project_attachments[]" id="boardProjectAttachments" class="form-control" accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx" multiple>
                                <div class="form-text">สูงสุด 5 ไฟล์ ไฟล์ละไม่เกิน 10 MB</div>
                            </div>
                        </div>
                    </section>

                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                        <h6 class="fw-bold mb-0">งานภายในโปรเจกต์</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-add-admin-task><i class="bi bi-plus-lg me-1"></i>เพิ่มงาน</button>
                    </div>
                    <div data-admin-task-list>
                    @foreach($taskRows as $taskIndex => $taskRow)
                        @php
                            $taskAssigneeId = data_get($taskRow, 'user_id') ?: ($taskIndex === 0 ? ($defaultAssigneeId ?: $preselectAssigneeId) : $defaultAssigneeId);
                            $taskCollaborators = array_map('intval', (array) data_get($taskRow, 'collaborators', []));
                            $taskSubtasks = array_values((array) data_get($taskRow, 'subtasks', []));
                        @endphp
                    <section class="board-project-task mb-3" data-admin-task data-task-index="{{ $taskIndex }}">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <strong data-task-title>งานที่ {{ $taskIndex + 1 }}</strong>
                            <button type="button" class="btn btn-sm btn-outline-danger {{ count($taskRows) === 1 ? 'd-none' : '' }}" data-remove-admin-task aria-label="ลบงานนี้"><i class="bi bi-trash"></i></button>
                        </div>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label fw-bold">ชื่องาน <span class="text-danger">*</span></label>
                            <input type="text" name="tasks[{{ $taskIndex }}][job_topic]" class="form-control form-control-lg" placeholder="เช่น ติดตั้งโปรแกรม / ตรวจสอบเอกสาร / โทรติดตามลูกค้า" value="{{ data_get($taskRow, 'job_topic') }}" required>
                            @error('tasks.'.$taskIndex.'.job_topic')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold">ผู้รับผิดชอบ <span class="text-danger">*</span></label>
                            <div class="assignee-picker dropdown">
                                <button type="button" class="assignee-picker-toggle form-control form-control-lg d-flex align-items-center justify-content-between dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                    <span class="assignee-picker-label text-muted">เลือกผู้รับผิดชอบ...</span>
                                </button>
                                <div class="dropdown-menu assignee-picker-menu p-2 w-100">
                                    <input type="search" class="form-control form-control-sm mb-2" data-task-assignee-search placeholder="พิมพ์ชื่อพนักงานหรือแผนก" aria-label="ค้นหาผู้รับผิดชอบ" autocomplete="off">
                                    <div class="assignee-picker-list">
                                        @foreach($employees as $employee)
                                            <button type="button" class="assignee-option" data-id="{{ $employee->id }}" data-name="{{ $employee->name }}" data-dept="{{ optional($employee->department)->department_name ?? 'ไม่ระบุแผนก' }}" data-search="{{ Str::lower($employee->name . ' ' . optional($employee->department)->department_name) }}">
                                                <span class="avatar-mini">{{ mb_substr($employee->name, 0, 2) }}</span>
                                                <span>
                                                    <strong>{{ $employee->name }}</strong>
                                                </span>
                                                <span class="assignee-option-dept">{{ optional($employee->department)->department_name ?? 'ไม่ระบุแผนก' }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                    <div class="text-muted small text-center py-2 d-none" data-task-assignee-empty>ไม่พบพนักงานที่ตรงกับคำค้นหา</div>
                                </div>
                            </div>
                            <input type="hidden" name="tasks[{{ $taskIndex }}][user_id]" data-task-assignee value="{{ $taskAssigneeId }}" required>
                            @error('tasks.'.$taskIndex.'.user_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">ความสำคัญ</label>
                            <div class="priority-picker dropdown">
                                <button type="button" class="priority-picker-toggle form-control form-control-lg d-flex align-items-center justify-content-between dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                    <span class="priority-picker-label text-muted d-flex align-items-center gap-2">
                                        <span>-- กรุณาเลือกความสำคัญ --</span>
                                    </span>
                                </button>
                                <div class="dropdown-menu priority-picker-menu p-2 w-100">
                                    <button type="button" class="priority-option" data-value="3" data-label="สำคัญมาก" data-tone="red">
                                        <span class="priority-dot tone-dot-red"></span>
                                        <span>สำคัญมาก</span>
                                    </button>
                                    <button type="button" class="priority-option" data-value="2" data-label="สำคัญทั่วไป" data-tone="amber">
                                        <span class="priority-dot tone-dot-amber"></span>
                                        <span>สำคัญทั่วไป</span>
                                    </button>
                                    <button type="button" class="priority-option" data-value="1" data-label="สำคัญน้อย" data-tone="gray">
                                        <span class="priority-dot tone-dot-gray"></span>
                                        <span>สำคัญน้อย</span>
                                    </button>
                                </div>
                            </div>
                            <input type="hidden" name="tasks[{{ $taskIndex }}][job_priority]" data-task-priority value="{{ data_get($taskRow, 'job_priority', 2) }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">วันที่เริ่ม <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="tasks[{{ $taskIndex }}][job_start_at]" class="form-control" value="{{ data_get($taskRow, 'job_start_at') }}" required>
                            @error('tasks.'.$taskIndex.'.job_start_at')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">กำหนดส่ง <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="tasks[{{ $taskIndex }}][job_due_at]" class="form-control" value="{{ data_get($taskRow, 'job_due_at') }}" required>
                            @error('tasks.'.$taskIndex.'.job_due_at')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                <label class="form-label fw-bold mb-0">ผู้ร่วมงาน</label>
                                <span class="text-muted small">ไม่จำเป็นต้องเลือก</span>
                            </div>
                            <select class="form-select mb-2" data-task-collaborator-department aria-label="เลือกแผนกของผู้ร่วมงาน">
                                <option value="">1 เลือกแผนกก่อน...</option>
                                @foreach($departments as $collaboratorDepartment)
                                    <option value="{{ $collaboratorDepartment->id }}">{{ $collaboratorDepartment->department_name }}</option>
                                @endforeach
                            </select>
                            <input type="search" class="form-control mb-2 d-none" data-task-collaborator-search placeholder="2) ค้นหาชื่อพนักงานในแผนกนี้" aria-label="ค้นหาผู้ร่วมงาน" autocomplete="off">
                            <div class="row g-2 board-collaborator-list">
                                @foreach($employees as $employee)
                                    <div class="col-md-6 board-collab-item d-none" data-department-id="{{ $employee->department_id }}" data-search="{{ Str::lower($employee->name . ' ' . optional($employee->department)->department_name) }}">
                                        <label class="w-100 p-2 border rounded-3 d-flex gap-2 align-items-center board-collaborator-choice">
                                            <input type="checkbox" name="tasks[{{ $taskIndex }}][collaborators][]" value="{{ $employee->id }}" @checked(in_array($employee->id, $taskCollaborators, true))>
                                            <span class="avatar-mini">{{ mb_substr($employee->name, 0, 2) }}</span>
                                            <span>
                                                <strong>{{ $employee->name }}</strong>
                                                <small class="d-block text-muted">{{ optional($employee->department)->department_name ?? '-' }}</small>
                                            </span>
                                        </label>
                                    </div>
                                @endforeach
                                <div class="text-muted small text-center py-3 board-collaborator-hint" data-task-collaborator-hint>กรุณาเลือกแผนกด้านบนก่อน จึงจะเลือกผู้ร่วมงานในแผนกนั้นได้</div>
                            </div>
                            @error('tasks.'.$taskIndex.'.collaborators')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label fw-bold mb-0">งานย่อย</label>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-add-admin-subtask><i class="bi bi-plus-lg me-1"></i>เพิ่มงานย่อย</button>
                            </div>
                            <div data-admin-subtask-list>
                                @foreach($taskSubtasks as $subtaskIndex => $subtask)
                                    <div class="board-project-subtask row g-2 mb-2">
                                        <div class="col-md-5"><input class="form-control" name="tasks[{{ $taskIndex }}][subtasks][{{ $subtaskIndex }}][title]" maxlength="255" placeholder="ชื่องานย่อย" value="{{ data_get($subtask, 'title') }}"></div>
                                        <div class="col-md-6"><input class="form-control" name="tasks[{{ $taskIndex }}][subtasks][{{ $subtaskIndex }}][details]" maxlength="2000" placeholder="รายละเอียด" value="{{ data_get($subtask, 'details') }}"></div>
                                        <div class="col-md-1 d-grid"><button type="button" class="btn btn-outline-danger" data-remove-admin-subtask aria-label="ลบงานย่อย"><i class="bi bi-x-lg"></i></button></div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">ไฟล์แนบของงาน</label>
                            <input type="file" name="tasks[{{ $taskIndex }}][attachments][]" class="form-control" data-task-attachments accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx" multiple>
                            <div class="text-muted small mt-1">
                                รองรับเฉพาะไฟล์ <strong>รูปภาพ (JPG, PNG)</strong>, <strong>Word (DOC, DOCX)</strong>,
                                <strong>Excel (XLS, XLSX)</strong> และ <strong>PowerPoint (PPT, PPTX)</strong> เท่านั้น
                                — ไฟล์ละไม่เกิน <strong>10 MB</strong> สูงสุด 5 ไฟล์ต่องาน
                                <span class="text-danger">ไม่รองรับไฟล์ ZIP หรือไฟล์ประเภทอื่นใดทั้งสิ้น</span>
                            </div>
                            <div class="text-danger small mt-1 d-none" data-task-attachments-error></div>
                        </div>
                    </div>
                    </section>
                    @endforeach
                    </div>
                </div>

                <div class="modal-footer board-modal-foot">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>สร้างโปรเจกต์</button>
                </div>
            </form>
        </div>
    </div>
</div>
