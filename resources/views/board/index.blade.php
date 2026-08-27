@extends('layouts.app')

@section('title', 'บอร์ดงาน')

@push('styles')
    @vite(auth()->user()->role === 'admin' ? 'resources/css/pages/board-admin.css' : 'resources/css/pages/board.css')
@endpush

@if(auth()->user()->role === 'admin')
    @push('scripts')
        @vite('resources/js/pages/board/assignment-approval.js')
    @endpush
@endif

@section('content')
@php
    $statusLabels = [
        1 => ['label' => 'รอดำเนินการ', 'tone' => 'gray', 'icon' => 'bi-clock'],
        2 => ['label' => 'กำลังทำ', 'tone' => 'blue', 'icon' => 'bi-lightning-charge-fill'],
        3 => ['label' => 'ตรวจสอบ', 'tone' => 'amber', 'icon' => 'bi-eye'],
        4 => ['label' => 'เสร็จสิ้น', 'tone' => 'green', 'icon' => 'bi-check-circle-fill'],
        5 => ['label' => 'พักงานชั่วคราว', 'tone' => 'gray', 'icon' => 'bi-pause-circle'],
    ];

    $priorityLabels = [
        1 => ['label' => 'ต่ำ', 'tone' => 'gray'],
        2 => ['label' => 'กลาง', 'tone' => 'amber'],
        3 => ['label' => 'สูง', 'tone' => 'red'],
    ];

    $allJobs = isset($jobs) ? $jobs : collect();
    if ($allJobs->isEmpty() && isset($columns)) {
        foreach ($columns as $column) {
            $allJobs = $allJobs->concat($column['tasks']);
        }
    }

    $employeesByDept = $employees->groupBy('department_id');
    $totalJobs = $allJobs->count();
    $activeJobs = $allJobs->where('job_status', '!=', 4)->count();
    $doneJobs = $stats['done'] ?? $allJobs->where('job_status', 4)->count();
    $completionRate = $totalJobs > 0 ? round(($doneJobs / $totalJobs) * 100) : 0;

    $visibleJobs = $allJobs->sortByDesc('job_id')->values();
    $attention = ($attentionJobs ?? collect())->take(6);
    $deptSummary = $workloadByDepartment ?? collect();
    $teamWorkload = ($workloadByUser ?? collect())->sortByDesc('active_count')->values();
@endphp

@include('board.components.admin-overview')

@if($canManageTasks)
<div class="modal fade" id="boardCreateTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <form action="{{ route('admin.tasks.store') }}" method="POST" enctype="multipart/form-data" data-admin-project-form>
                @csrf
                <div class="modal-header board-modal-head">
                    <div>
                        <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>สร้างโปรเจกต์และมอบหมายงาน</h5>
                        <div class="text-muted small">สร้างโปรเจกต์หนึ่งรายการ พร้อมงานภายในอย่างน้อยหนึ่งงาน</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <section class="board-project-fields mb-4" aria-labelledby="boardProjectHeading">
                        <h6 id="boardProjectHeading" class="fw-bold mb-3">ข้อมูลโปรเจกต์</h6>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold" for="boardProjectName">ชื่อโปรเจกต์ <span class="text-danger">*</span></label>
                                <input type="text" name="project_name" id="boardProjectName" class="form-control" maxlength="80" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold" for="boardProjectPriority">ความสำคัญโปรเจกต์</label>
                                <select name="project_priority" id="boardProjectPriority" class="form-select" required>
                                    <option value="1">ต่ำ</option><option value="2" selected>กลาง</option><option value="3">สูง</option>
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
                    <section class="board-project-task mb-3" data-admin-task data-task-index="0">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <strong data-task-title>งานที่ 1</strong>
                            <button type="button" class="btn btn-sm btn-outline-danger d-none" data-remove-admin-task aria-label="ลบงานนี้"><i class="bi bi-trash"></i></button>
                        </div>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label fw-bold">ชื่องาน <span class="text-danger">*</span></label>
                            <input type="text" name="tasks[0][job_topic]" class="form-control form-control-lg" placeholder="เช่น ติดตั้งโปรแกรม / ตรวจสอบเอกสาร / โทรติดตามลูกค้า" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold">ผู้รับผิดชอบ <span class="text-danger">*</span></label>
                            <div class="assignee-picker dropdown">
                                <button type="button" class="assignee-picker-toggle form-control form-control-lg d-flex align-items-center justify-content-between dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                    <span class="assignee-picker-label text-muted" id="boardAssigneeLabel">เลือกผู้รับผิดชอบ...</span>
                                </button>
                                <div class="dropdown-menu assignee-picker-menu p-2 w-100">
                                    <input type="search" class="form-control form-control-sm mb-2" id="boardAssigneeSearch" data-task-assignee-search placeholder="พิมพ์ชื่อพนักงานหรือแผนก" autocomplete="off">
                                    <div class="assignee-picker-list" id="boardAssigneeList">
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
                                    <div class="text-muted small text-center py-2 d-none" id="boardAssigneeEmpty">ไม่พบพนักงานที่ตรงกับคำค้นหา</div>
                                </div>
                            </div>
                            <input type="hidden" name="tasks[0][user_id]" id="boardTaskAssigneeId" data-task-assignee required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">ความสำคัญ</label>
                            <div class="priority-picker dropdown">
                                <button type="button" class="priority-picker-toggle form-control form-control-lg d-flex align-items-center justify-content-between dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                    <span class="priority-picker-label text-muted d-flex align-items-center gap-2" id="boardPriorityLabel">
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
                            <input type="hidden" name="tasks[0][job_priority]" id="boardTaskPriority" data-task-priority value="2">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">วันที่เริ่ม <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="tasks[0][job_start_at]" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">กำหนดส่ง <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="tasks[0][job_due_at]" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                <label class="form-label fw-bold mb-0">ผู้ร่วมงาน</label>
                                <span class="text-muted small">ไม่จำเป็นต้องเลือก</span>
                            </div>
                            <select class="form-select mb-2" id="boardCollaboratorDept" data-task-collaborator-department>
                                <option value="">1 เลือกแผนกก่อน...</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                                @endforeach
                            </select>
                            <input type="search" class="form-control mb-2 d-none" id="boardCollaboratorSearch" data-task-collaborator-search placeholder="2) ค้นหาชื่อพนักงานในแผนกนี้" autocomplete="off">
                            <div class="row g-2 board-collaborator-list" id="boardCollaboratorList">
                                @foreach($employees as $employee)
                                    <div class="col-md-6 board-collab-item d-none" data-department-id="{{ $employee->department_id }}" data-search="{{ Str::lower($employee->name . ' ' . optional($employee->department)->department_name) }}">
                                        <label class="w-100 p-2 border rounded-3 d-flex gap-2 align-items-center board-collaborator-choice">
                                            <input type="checkbox" name="tasks[0][collaborators][]" value="{{ $employee->id }}">
                                            <span class="avatar-mini">{{ mb_substr($employee->name, 0, 2) }}</span>
                                            <span>
                                                <strong>{{ $employee->name }}</strong>
                                                <small class="d-block text-muted">{{ optional($employee->department)->department_name ?? '-' }}</small>
                                            </span>
                                        </label>
                                    </div>
                                @endforeach
                                <div class="text-muted small text-center py-3" id="boardCollaboratorHint">กรุณาเลือกแผนกด้านบนก่อน จึงจะเลือกผู้ร่วมงานในแผนกนั้นได้</div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label fw-bold mb-0">งานย่อย</label>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-add-admin-subtask><i class="bi bi-plus-lg me-1"></i>เพิ่มงานย่อย</button>
                            </div>
                            <div data-admin-subtask-list></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">ไฟล์แนบของงาน</label>
                            <input type="file" name="tasks[0][attachments][]" id="boardAttachmentsInput" class="form-control" accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx" multiple>
                            <div class="text-muted small mt-1">
                                รองรับเฉพาะไฟล์ <strong>รูปภาพ (JPG, PNG)</strong>, <strong>Word (DOC, DOCX)</strong>,
                                <strong>Excel (XLS, XLSX)</strong> และ <strong>PowerPoint (PPT, PPTX)</strong> เท่านั้น
                                — ไฟล์ละไม่เกิน <strong>10 MB</strong> สูงสุด 5 ไฟล์ต่องาน
                                <span class="text-danger">ไม่รองรับไฟล์ ZIP หรือไฟล์ประเภทอื่นใดทั้งสิ้น</span>
                            </div>
                            <div class="text-danger small mt-1 d-none" id="boardAttachmentsError"></div>
                        </div>
                    </div>
                    </section>
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
@endif
@endsection

@push('scripts')
<script>
    // ---------- แจ้งเตือนผลลัพธ์การสร้างงาน (Swal Toast มุมขวาบน) ----------
    (function () {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toastEl) => {
                toastEl.addEventListener('mouseenter', Swal.stopTimer);
                toastEl.addEventListener('mouseleave', Swal.resumeTimer);
            },
        });

        @if (session('success'))
            Toast.fire({
                icon: 'success',
                title: @json(session('success')),
            });
        @endif

        @if ($errors->any())
            Toast.fire({
                icon: 'error',
                title: @json($errors->first()),
            });
        @endif
    })();




    // ---------- ความสำคัญ: dropdown เลือกระดับ พร้อมสี ----------
    (function () {
        const toggle = document.querySelector('.priority-picker .priority-picker-toggle');
        const label = document.getElementById('boardPriorityLabel');
        const hiddenInput = document.getElementById('boardTaskPriority');
        const options = Array.from(document.querySelectorAll('.priority-option'));
        if (!toggle || !hiddenInput) return;

        const TONE_COLORS = {
            red: { text: 'var(--board-red)', soft: 'var(--board-red-soft)' },
            amber: { text: 'var(--board-amber)', soft: 'var(--board-amber-soft)' },
            gray: { text: '#475467', soft: 'var(--board-gray-soft)' },
        };

        options.forEach((option) => {
            option.addEventListener('click', () => {
                options.forEach((item) => item.classList.remove('active'));
                option.classList.add('active');

                hiddenInput.value = option.dataset.value || '';

                const tone = option.dataset.tone;
                const colors = TONE_COLORS[tone];

                if (label) {
                    label.classList.remove('text-muted');
                    label.innerHTML = '<span class="priority-dot tone-dot-' + tone + '"></span><span>' + option.dataset.label + '</span>';
                }

                if (colors) {
                    toggle.style.borderColor = colors.text;
                    toggle.style.background = colors.soft;
                    toggle.style.color = colors.text;
                    toggle.style.fontWeight = '600';
                }

                bootstrap.Dropdown.getOrCreateInstance(toggle)?.hide();
            });
        });
    })();

    // ---------- ผู้รับผิดชอบ: dropdown ค้นหาได้ พร้อมแสดงแผนกของแต่ละคน ----------
    (function () {
        const search = document.getElementById('boardAssigneeSearch');
        const list = document.getElementById('boardAssigneeList');
        const empty = document.getElementById('boardAssigneeEmpty');
        const label = document.getElementById('boardAssigneeLabel');
        const hiddenInput = document.getElementById('boardTaskAssigneeId');
        if (!list || !hiddenInput) return;

        const options = Array.from(list.querySelectorAll('.assignee-option'));

        search?.addEventListener('input', function () {
            const keyword = this.value.trim().toLowerCase();
            let visibleCount = 0;
            options.forEach((option) => {
                const matches = option.dataset.search.includes(keyword);
                option.classList.toggle('d-none', !matches);
                if (matches) visibleCount += 1;
            });
            empty?.classList.toggle('d-none', visibleCount !== 0);
        });

        options.forEach((option) => {
            option.addEventListener('click', () => {
                options.forEach((item) => item.classList.remove('active'));
                option.classList.add('active');

                hiddenInput.value = option.dataset.id || '';
                if (label) {
                    label.textContent = option.dataset.name + ' — ' + option.dataset.dept;
                    label.classList.remove('text-muted');
                }

                const dropdownToggle = document.querySelector('.assignee-picker .assignee-picker-toggle');
                bootstrap.Dropdown.getOrCreateInstance(dropdownToggle)?.hide();
            });
        });
    })();

    // ---------- ผู้ร่วมงาน: ต้องเลือกแผนกก่อน ถึงจะเลือกคนในแผนกนั้นได้ ----------
    (function () {
        const deptSelect = document.getElementById('boardCollaboratorDept');
        const search = document.getElementById('boardCollaboratorSearch');
        const hint = document.getElementById('boardCollaboratorHint');
        const items = Array.from(document.querySelectorAll('.board-collab-item'));
        if (!deptSelect) return;

        function applyCollaboratorFilter() {
            const departmentId = deptSelect.value;
            const keyword = (search?.value || '').trim().toLowerCase();

            if (!departmentId) {
                // ยังไม่ได้เลือกแผนก: ซ่อนรายชื่อทั้งหมด บังคับให้เลือกแผนกก่อน
                items.forEach((item) => item.classList.add('d-none'));
                if (search) {
                    search.classList.add('d-none');
                    search.disabled = true;
                    search.value = '';
                }
                if (hint) {
                    hint.classList.remove('d-none');
                    hint.textContent = 'กรุณาเลือกแผนกด้านบนก่อน จึงจะเลือกผู้ร่วมงานในแผนกนั้นได้';
                }
                return;
            }

            search?.classList.remove('d-none');
            if (search) search.disabled = false;

            let visibleCount = 0;
            items.forEach((item) => {
                const inDepartment = item.dataset.departmentId === departmentId;
                const matchesKeyword = !keyword || item.dataset.search.includes(keyword);
                const shouldShow = inDepartment && matchesKeyword;
                item.classList.toggle('d-none', !shouldShow);
                if (shouldShow) visibleCount += 1;
            });

            if (hint) {
                hint.classList.toggle('d-none', visibleCount !== 0);
                hint.textContent = 'ไม่พบพนักงานในแผนกนี้ที่ตรงกับคำค้นหา';
            }
        }

        deptSelect.addEventListener('change', applyCollaboratorFilter);
        search?.addEventListener('input', applyCollaboratorFilter);
        applyCollaboratorFilter();
    })();

    // ---------- ไฟล์แนบ: ตรวจสอบนามสกุลไฟล์และขนาดฝั่ง client ก่อนส่งจริง (ฝั่งเซิร์ฟเวอร์ยังคงเป็นตัวตัดสินสุดท้าย) ----------
    (function () {
        const input = document.getElementById('boardAttachmentsInput');
        const errorBox = document.getElementById('boardAttachmentsError');
        if (!input) return;

        const ALLOWED_ATTACHMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
        const MAX_ATTACHMENT_MB = 10;

        input.addEventListener('change', function () {
            const invalidFiles = [];

            Array.from(this.files).forEach((file) => {
                const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';

                if (!ALLOWED_ATTACHMENT_EXTENSIONS.includes(extension)) {
                    invalidFiles.push(file.name + ' (ไม่ใช่ประเภทไฟล์ที่อนุญาต)');
                } else if (file.size > MAX_ATTACHMENT_MB * 1024 * 1024) {
                    invalidFiles.push(file.name + ' (ขนาดเกิน ' + MAX_ATTACHMENT_MB + ' MB)');
                } else {
                    // ไฟล์ผ่านเงื่อนไข ไม่ต้องทำอะไรเพิ่ม
                }
            });

            if (invalidFiles.length > 0) {
                this.value = '';
                if (errorBox) {
                    errorBox.textContent = 'ไม่สามารถแนบไฟล์ต่อไปนี้ได้: ' + invalidFiles.join(', ');
                    errorBox.classList.remove('d-none');
                }
            } else if (errorBox) {
                errorBox.classList.add('d-none');
                errorBox.textContent = '';
            }
        });
    })();

    (function () {
        const form = document.querySelector('[data-admin-project-form]');
        const taskList = form?.querySelector('[data-admin-task-list]');
        const addTaskButton = form?.querySelector('[data-add-admin-task]');
        if (!form || !taskList || !addTaskButton) return;
        const taskTemplate = taskList.querySelector('[data-admin-task]')?.cloneNode(true);
        if (!taskTemplate) return;

        const priorityTones = {
            red: { text: 'var(--board-red)', soft: 'var(--board-red-soft)' },
            amber: { text: 'var(--board-amber)', soft: 'var(--board-amber-soft)' },
            gray: { text: '#475467', soft: 'var(--board-gray-soft)' },
        };

        const setTaskPriority = (task, option) => {
            if (!option) return;

            const value = option.dataset.value || '2';
            const tone = option.dataset.tone || 'amber';
            const colors = priorityTones[tone] || priorityTones.amber;
            const toggle = task.querySelector('.priority-picker-toggle');
            const label = task.querySelector('.priority-picker-label');

            task.querySelector('[data-task-priority]').value = value;
            task.querySelectorAll('.priority-option').forEach((item) => item.classList.toggle('active', item === option));

            label.classList.remove('text-muted');
            label.innerHTML = `<span class="priority-dot tone-dot-${tone}"></span><span>${option.dataset.label || ''}</span>`;
            toggle.style.borderColor = colors.text;
            toggle.style.background = colors.soft;
            toggle.style.color = colors.text;
            toggle.style.fontWeight = '600';
        };

        const reindexSubtasks = (task) => {
            task.querySelectorAll('.board-project-subtask').forEach((row, subtaskIndex) => {
                row.querySelectorAll('[name]').forEach((field) => {
                    field.name = field.name.replace(/\[subtasks\]\[\d+\]/, `[subtasks][${subtaskIndex}]`);
                });
            });
        };

        const reindexTasks = () => {
            const tasks = Array.from(taskList.querySelectorAll('[data-admin-task]'));
            tasks.forEach((task, taskIndex) => {
                task.dataset.taskIndex = taskIndex;
                task.querySelector('[data-task-title]').textContent = `งานที่ ${taskIndex + 1}`;
                task.querySelector('[data-remove-admin-task]')?.classList.toggle('d-none', tasks.length === 1);
                task.querySelectorAll('[name]').forEach((field) => {
                    field.name = field.name.replace(/tasks\[\d+\]/, `tasks[${taskIndex}]`);
                });
                reindexSubtasks(task);
            });
        };

        const resetTask = (task) => {
            task.querySelectorAll('input, textarea, select').forEach((field) => {
                if (field.type === 'checkbox' || field.type === 'radio') field.checked = false;
                else if (field.type === 'file') field.value = '';
                else if (field.matches('[data-task-priority]')) field.value = '2';
                else field.value = '';
            });
            task.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'));
            task.querySelector('.assignee-picker-label').textContent = 'เลือกผู้รับผิดชอบ...';
            task.querySelector('.assignee-picker-label').classList.add('text-muted');
            task.querySelectorAll('.assignee-option').forEach((option) => option.classList.remove('active', 'd-none'));
            task.querySelector('.assignee-picker-menu .text-center')?.classList.add('d-none');
            setTaskPriority(task, task.querySelector('.priority-option[data-value="2"]'));
            const collaboratorSearch = task.querySelector('[data-task-collaborator-search]');
            collaboratorSearch.classList.add('d-none');
            collaboratorSearch.disabled = true;
            task.querySelectorAll('.board-collab-item').forEach((item) => item.classList.add('d-none'));
            task.querySelector('[data-admin-subtask-list]').innerHTML = '';
        };

        addTaskButton.addEventListener('click', () => {
            const task = taskTemplate.cloneNode(true);
            resetTask(task);
            taskList.appendChild(task);
            reindexTasks();
        });

        form.addEventListener('click', (event) => {
            const removeTask = event.target.closest('[data-remove-admin-task]');
            if (removeTask && taskList.querySelectorAll('[data-admin-task]').length > 1) {
                removeTask.closest('[data-admin-task]').remove();
                reindexTasks();
                return;
            }

            const assigneeOption = event.target.closest('.assignee-option');
            if (assigneeOption) {
                const task = assigneeOption.closest('[data-admin-task]');
                task.querySelectorAll('.assignee-option').forEach((option) => option.classList.remove('active'));
                assigneeOption.classList.add('active');
                task.querySelector('[data-task-assignee]').value = assigneeOption.dataset.id || '';
                const label = task.querySelector('.assignee-picker-label');
                label.textContent = `${assigneeOption.dataset.name} — ${assigneeOption.dataset.dept}`;
                label.classList.remove('text-muted');
                bootstrap.Dropdown.getOrCreateInstance(task.querySelector('.assignee-picker-toggle'))?.hide();
                return;
            }

            const priorityOption = event.target.closest('.priority-option');
            if (priorityOption) {
                const task = priorityOption.closest('[data-admin-task]');
                setTaskPriority(task, priorityOption);
            }

            const addSubtask = event.target.closest('[data-add-admin-subtask]');
            if (addSubtask) {
                const task = addSubtask.closest('[data-admin-task]');
                const container = task.querySelector('[data-admin-subtask-list]');
                const taskIndex = Number(task.dataset.taskIndex);
                const subtaskIndex = container.children.length;
                const row = document.createElement('div');
                row.className = 'board-project-subtask row g-2 mb-2';
                row.innerHTML = `<div class="col-md-5"><input class="form-control" name="tasks[${taskIndex}][subtasks][${subtaskIndex}][title]" maxlength="255" placeholder="ชื่องานย่อย"></div><div class="col-md-6"><input class="form-control" name="tasks[${taskIndex}][subtasks][${subtaskIndex}][details]" maxlength="2000" placeholder="รายละเอียด"></div><div class="col-md-1 d-grid"><button type="button" class="btn btn-outline-danger" data-remove-admin-subtask aria-label="ลบงานย่อย"><i class="bi bi-x-lg"></i></button></div>`;
                container.appendChild(row);
                reindexSubtasks(task);
                return;
            }

            const removeSubtask = event.target.closest('[data-remove-admin-subtask]');
            if (removeSubtask) {
                const task = removeSubtask.closest('[data-admin-task]');
                removeSubtask.closest('.board-project-subtask').remove();
                reindexSubtasks(task);
            }
        });

        form.addEventListener('input', (event) => {
            if (event.target.matches('[data-task-assignee-search]')) {
                const keyword = event.target.value.trim().toLowerCase();
                event.target.closest('[data-admin-task]').querySelectorAll('.assignee-option').forEach((option) => {
                    option.classList.toggle('d-none', !option.dataset.search.includes(keyword));
                });
            }

            if (event.target.matches('[data-task-collaborator-search]')) {
                const task = event.target.closest('[data-admin-task]');
                const departmentId = task.querySelector('[data-task-collaborator-department]').value;
                const keyword = event.target.value.trim().toLowerCase();
                task.querySelectorAll('.board-collab-item').forEach((item) => {
                    item.classList.toggle('d-none', item.dataset.departmentId !== departmentId || !item.dataset.search.includes(keyword));
                });
            }
        });

        form.addEventListener('change', (event) => {
            if (!event.target.matches('[data-task-collaborator-department]')) return;
            const task = event.target.closest('[data-admin-task]');
            const departmentId = event.target.value;
            const search = task.querySelector('[data-task-collaborator-search]');
            search.value = '';
            search.classList.toggle('d-none', !departmentId);
            search.disabled = !departmentId;
            task.querySelectorAll('.board-collab-item').forEach((item) => {
                item.classList.toggle('d-none', !departmentId || item.dataset.departmentId !== departmentId);
            });
        });

        form.addEventListener('submit', (event) => {
            const missingAssignee = Array.from(form.querySelectorAll('[data-task-assignee]')).some((field) => !field.value);
            if (missingAssignee) {
                event.preventDefault();
                alert('กรุณาเลือกผู้รับผิดชอบให้ครบทุกงาน');
            }
        });

        taskList.querySelectorAll('[data-admin-task]').forEach((task) => {
            const value = task.querySelector('[data-task-priority]').value || '2';
            setTaskPriority(task, task.querySelector(`.priority-option[data-value="${value}"]`));
        });
        reindexTasks();
    })();

    (function () {
        const modal = document.getElementById('boardCreateTaskModal');
        if (!modal) return;

        document.querySelectorAll('[data-open-admin-assignment]').forEach((trigger) => {
            trigger.addEventListener('click', () => bootstrap.Modal.getOrCreateInstance(modal).show());
        });

        const shouldOpen = @json(request()->boolean('open_assignment'));
        const requestedAssignee = @json((string) request('assign_to'));
        if (!shouldOpen) return;

        if (requestedAssignee) {
            const options = Array.from(modal.querySelectorAll('[data-admin-task]:first-child .assignee-option'));
            options.find((option) => option.dataset.id === requestedAssignee)?.click();
        }

        bootstrap.Modal.getOrCreateInstance(modal).show();
    })();
</script>
@endpush
