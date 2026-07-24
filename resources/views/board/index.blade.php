@extends('layouts.app')

@section('title', 'บอร์ดงาน')

@push('styles')
    @vite('resources/css/pages/board.css')
@endpush

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

<div class="board-page">
    <div class="board-hero">
        <div>
            <div class="board-kicker">
                <i class="bi bi-calendar3"></i>
                วันนี้ {{ \Carbon\Carbon::now()->locale('th')->isoFormat('D MMMM YYYY') }}
            </div>
            <h1 class="board-title">บอร์ดรวมงาน</h1>
            <p class="board-subtitle">สรุปงานสำคัญขององค์กร ดูสถานะ ผู้รับผิดชอบ และงานที่ต้องติดตามได้เร็วขึ้น</p>
        </div>
        <div class="hero-actions">
            <a href="{{ route('board.index') }}" class="action-btn">
                <i class="bi bi-arrow-clockwise"></i>
                รีเซ็ตมุมมอง
            </a>
            @if($canManageTasks)
                <button type="button" class="action-btn primary" data-bs-toggle="modal" data-bs-target="#boardCreateTaskModal">
                    <i class="bi bi-plus-circle-fill"></i>
                    สร้างงานใหม่
                </button>
            @endif
        </div>
    </div>

    <section class="filter-panel">
        <div class="filter-grid">
            <div class="dept-tabs" aria-label="กรองตามแผนก">
                <a href="{{ route('board.index') }}" class="dept-tab {{ !$currentDeptId ? 'active' : '' }}">
                    <i class="bi bi-grid"></i>
                    ทุกแผนก
                    <span class="count">{{ $employees->count() }} คน</span>
                </a>

                @foreach($departments as $dept)
                    @php $deptEmployeeCount = $employeesByDept->get($dept->id, collect())->count(); @endphp
                    <a href="{{ route('board.index', ['department_id' => $dept->id, 'assignee' => $currentAssignee]) }}"
                       class="dept-tab {{ $currentDeptId === $dept->id ? 'active' : '' }}">
                        <i class="bi bi-building"></i>
                        {{ $dept->department_name }}
                        <span class="count">{{ $deptEmployeeCount }}</span>
                    </a>
                @endforeach
            </div>

            <select onchange="filterByAssignee(this.value)" class="assignee-select" aria-label="กรองตามผู้รับผิดชอบ">
                <option value="">ผู้รับผิดชอบทั้งหมด</option>
                @foreach($employees as $employee)
                    <option value="{{ $employee->id }}" {{ $currentAssignee === $employee->id ? 'selected' : '' }}>
                        {{ $employee->name }}
                    </option>
                @endforeach
            </select>
        </div>
    </section>

    <section class="metric-grid" aria-label="สรุปภาพรวมงาน">
        <button type="button" class="metric-card active" data-metric-filter="all" aria-pressed="true">
            <div class="metric-icon tone-gray"><i class="bi bi-kanban"></i></div>
            <div>
                <div class="metric-label">งานทั้งหมด</div>
                <div class="metric-value">{{ $totalJobs }}</div>
            </div>
        </button>
        <button type="button" class="metric-card" data-metric-filter="pending" aria-pressed="false">
            <div class="metric-icon tone-amber"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="metric-label">รอดำเนินการ</div>
                <div class="metric-value">{{ $stats['pending'] ?? 0 }}</div>
            </div>
        </button>
        <button type="button" class="metric-card" data-metric-filter="doing" aria-pressed="false">
            <div class="metric-icon tone-blue"><i class="bi bi-lightning-charge-fill"></i></div>
            <div>
                <div class="metric-label">กำลังทำ</div>
                <div class="metric-value">{{ $stats['doing'] ?? 0 }}</div>
            </div>
        </button>
        <button type="button" class="metric-card" data-metric-filter="done" aria-pressed="false">
            <div class="metric-icon tone-green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="metric-label">เสร็จสิ้น</div>
                <div class="metric-value">{{ $doneJobs }}</div>
            </div>
        </button>
        <button type="button" class="metric-card" data-metric-filter="paused" aria-pressed="false">
            <div class="metric-icon tone-gray"><i class="bi bi-pause-circle"></i></div>
            <div>
                <div class="metric-label">พักงาน</div>
                <div class="metric-value">{{ $stats['paused'] ?? 0 }}</div>
            </div>
        </button>
        <button type="button" class="metric-card" data-metric-filter="late" aria-pressed="false">
            <div class="metric-icon tone-red"><i class="bi bi-exclamation-circle-fill"></i></div>
            <div>
                <div class="metric-label">ล่าช้า</div>
                <div class="metric-value">{{ $stats['late'] ?? 0 }}</div>
            </div>
        </button>
    </section>

    <div class="content-grid">
        <div class="main-stack">


            <section class="surface-card">
                <div class="card-head">
                    <div>
                        <h2 class="card-title" id="boardListTitle">รายการงานทั้งหมด</h2>
                        <p class="card-desc" id="boardListDesc">คลิกกล่องสรุปด้านบนเพื่อดูงานตามสถานะ หรือค้นหาในหน้านี้</p>
                    </div>
                    <div class="head-meta"><span id="visibleJobCount">{{ $visibleJobs->count() }}</span> งาน</div>
                </div>

                <div class="table-tools">
                    <label class="board-search">
                        <i class="bi bi-search"></i>
                        <input type="search" id="boardSearch" placeholder="ค้นหาชื่องาน ผู้รับผิดชอบ หรือแผนก" oninput="filterRows(this.value)">
                    </label>
                    @if($currentAssignee || $currentDeptId)
                        <a href="{{ route('board.index') }}" class="action-btn">
                            <i class="bi bi-x-circle"></i>
                            ล้างตัวกรอง
                        </a>
                    @endif
                </div>

                <div class="table-wrap">
                    <table class="work-table">
                        <thead>
                            <tr>
                                <th>งาน</th>
                                <th>ผู้รับผิดชอบ</th>
                                <th>แผนก</th>
                                <th>สถานะ</th>
                                <th>ความสำคัญ</th>
                                <th>กำหนดส่ง</th>
                                @if($canManageTasks)
                                    <th>จัดการ</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody id="workRows">
                            @forelse($visibleJobs as $job)
                                @php
                                    $status = $statusLabels[$job->job_status] ?? $statusLabels[1];
                                    $priority = $priorityLabels[$job->job_priority] ?? $priorityLabels[2];
                                    $isOverdue = $job->is_overdue ?? false;
                                    $isPendingApproval = ($job->approval_status ?? 'approved') === 'pending';
                                    $assigneeName = optional($job->user)->name ?? 'ไม่ระบุ';
                                    $departmentName = optional($job->department)->department_name ?? '-';
                                    $avatar = mb_substr($assigneeName, 0, 2);
                                @endphp
                                <tr data-search="{{ Str::lower($job->job_topic . ' ' . $assigneeName . ' ' . $departmentName) }}"
                                    data-status="{{ $job->job_status }}"
                                    data-overdue="{{ $isOverdue ? '1' : '0' }}">
                                    <td>
                                        <a href="{{ route('tasks.show', $job->job_id) }}" class="work-link">
                                            {{ $job->job_topic }}
                                        </a>
                                    </td>
                                    <td>
                                        <div class="person">
                                            <span class="person-name">{{ $assigneeName }}</span>
                                        </div>
                                    </td>
                                    <td class="muted">{{ $departmentName }}</td>
                                    <td>
                                        <span class="pill {{ $isPendingApproval ? 'amber' : ($isOverdue ? 'red' : $status['tone']) }}">
                                            <i class="bi {{ $isPendingApproval ? 'bi-hourglass-split' : ($isOverdue ? 'bi-exclamation-triangle-fill' : $status['icon']) }}"></i>
                                            {{ $isPendingApproval ? 'รออนุมัติ' : ($isOverdue ? 'ล่าช้า' : $status['label']) }}
                                        </span>
                                    </td>
                                    <td><span class="pill {{ $priority['tone'] }}">{{ $priority['label'] }}</span></td>
                                    <td class="{{ $isOverdue ? 'text-danger fw-bold' : 'muted' }}">
                                        {{ $job->job_due_at ? \Carbon\Carbon::parse($job->job_due_at)->locale('th')->isoFormat('D MMM YYYY') : '-' }}
                                    </td>
                                    @if($canManageTasks)
                                        <td>
                                            @if($isPendingApproval)
                                                <div class="d-flex gap-1">
                                                    <form method="POST" action="{{ route('admin.tasks.approval', $job->job_id) }}">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="approval_status" value="approved">
                                                        <button type="submit" class="btn btn-sm btn-success">อนุมัติ</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('admin.tasks.approval', $job->job_id) }}">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="approval_status" value="rejected">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">ปฏิเสธ</button>
                                                    </form>
                                                </div>
                                            @else
                                                <span class="muted">อนุมัติแล้ว</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $canManageTasks ? 7 : 6 }}">
                                        <div class="empty-box">ยังไม่มีงานตามเงื่อนไขที่เลือก</div>
                                    </td>
                                </tr>
                            @endforelse
                            <tr id="boardFilterEmpty" style="display:none;">
                                <td colspan="{{ $canManageTasks ? 7 : 6 }}">
                                    <div class="empty-box">ไม่พบงานในกลุ่มที่เลือก</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
                        <section class="surface-card">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">ต้องติดตามก่อน</h2>
                        <p class="card-desc">งานล่าช้าและงานใกล้ครบกำหนด </p>
                    </div>
                    <div class="head-meta">{{ $attention->count() }} รายการ</div>
                </div>

                @if($attention->isNotEmpty())
                    <div class="attention-list">
                        @foreach($attention as $job)
                            @php
                                $status = $statusLabels[$job->job_status] ?? $statusLabels[1];
                                $priority = $priorityLabels[$job->job_priority] ?? $priorityLabels[2];
                                $isOverdue = $job->is_overdue ?? false;
                            @endphp
                            <a href="{{ route('tasks.show', $job->job_id) }}" class="attention-card">
                                <p class="task-title">{{ $job->job_topic }}</p>
                                <div class="task-meta">
                                    <span class="pill {{ $isOverdue ? 'red' : $status['tone'] }}">
                                        <i class="bi {{ $isOverdue ? 'bi-exclamation-triangle-fill' : $status['icon'] }}"></i>
                                        {{ $isOverdue ? 'ล่าช้า' : $status['label'] }}
                                    </span>
                                    <span class="pill {{ $priority['tone'] }}">{{ $priority['label'] }}</span>
                                    <span><i class="bi bi-person"></i> {{ optional($job->user)->name ?? 'ไม่ระบุ' }}</span>
                                    @if($job->job_due_at)
                                        <span><i class="bi bi-calendar3"></i> {{ \Carbon\Carbon::parse($job->job_due_at)->locale('th')->isoFormat('D MMM YYYY') }}</span>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="empty-box">
                        <i class="bi bi-check2-circle"></i>
                        ไม่มีงานเร่งด่วนหรืองานล่าช้าในตอนนี้
                    </div>
                @endif
            </section>
        </div>

        <aside class="side-stack">
            <section class="surface-card">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">ความคืบหน้าโดยรวม</h2>
                        <p class="card-desc">วัดจากจำนวนงานที่เสร็จเทียบกับงานทั้งหมด</p>
                    </div>
                    <div class="head-meta">{{ $completionRate }}%</div>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" style="width: {{ $completionRate }}%;"></div>
                </div>
                <div class="mini-stats">
                    <span>งาน active {{ $activeJobs }}</span>
                    <span>เสร็จแล้ว {{ $doneJobs }}</span>
                    <span>ทั้งหมด {{ $totalJobs }}</span>
                </div>
            </section>
            <section class="surface-card">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">ภาระงานทีม</h2>
                        <p class="card-desc">เรียงจากคนที่มีงาน active มากที่สุด</p>
                    </div>
                </div>

                <div class="team-list">
                    @forelse($teamWorkload as $member)
                        @php $avatar = mb_substr($member['name'], 0, 2); @endphp
                        <div class="team-row">
                            <span class="avatar-mini">
                                @if(! empty($member['profile_image']))
                                    <img src="{{ route('media.show', ['path' => $member['profile_image']]) }}" alt="{{ $member['name'] }}">
                                @else
                                    {{ $avatar }}
                                @endif
                            </span>
                            <div>
                                <div class="team-name">{{ $member['name'] }}</div>
                                <div class="team-sub">{{ $member['department'] }}{{ $member['latest_job'] ? ' • ' . Str::limit($member['latest_job'], 28) : '' }}</div>
                            </div>
                            <div class="team-count">{{ $member['active_count'] }}</div>
                        </div>
                    @empty
                        <div class="empty-box">ยังไม่มีข้อมูลพนักงาน</div>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
</div>

@if($canManageTasks)
<div class="modal fade" id="boardCreateTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <form action="{{ route('tasks.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="modal-header board-modal-head">
                    <div>
                        <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>สร้างงานใหม่</h5>
                        <div class="text-muted small">มอบหมายงานให้พนักงาน เลือกผู้ร่วมงานได้โดยไม่บังคับ</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label fw-bold">ชื่องาน <span class="text-danger">*</span></label>
                            <input type="text" name="job_topic" class="form-control form-control-lg" placeholder="เช่น ติดตั้งโปรแกรม / ตรวจสอบเอกสาร / โทรติดตามลูกค้า" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold">ผู้รับผิดชอบ <span class="text-danger">*</span></label>
                            <div class="assignee-picker dropdown">
                                <button type="button" class="assignee-picker-toggle form-control form-control-lg d-flex align-items-center justify-content-between dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                    <span class="assignee-picker-label text-muted" id="boardAssigneeLabel">เลือกผู้รับผิดชอบ...</span>
                                </button>
                                <div class="dropdown-menu assignee-picker-menu p-2 w-100">
                                    <input type="search" class="form-control form-control-sm mb-2" id="boardAssigneeSearch" placeholder="พิมพ์ชื่อพนักงานหรือแผนก" autocomplete="off">
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
                            <input type="hidden" name="user_id" id="boardTaskAssigneeId" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">รายละเอียดงาน</label>
                            <textarea name="job_details" class="form-control" rows="3" placeholder="อธิบายรายละเอียด เป้าหมาย หรือสิ่งที่ต้องส่งมอบ"></textarea>
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
                            <input type="hidden" name="job_priority" id="boardTaskPriority">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">วันที่เริ่ม <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="job_start_at" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">กำหนดส่ง <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="job_due_at" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                <label class="form-label fw-bold mb-0">ผู้ร่วมงาน</label>
                                <span class="text-muted small">ไม่จำเป็นต้องเลือก</span>
                            </div>
                            <select class="form-select mb-2" id="boardCollaboratorDept">
                                <option value="">1 เลือกแผนกก่อน...</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                                @endforeach
                            </select>
                            <input type="search" class="form-control mb-2 d-none" id="boardCollaboratorSearch" placeholder="2) ค้นหาชื่อพนักงานในแผนกนี้" autocomplete="off">
                            <div class="row g-2 board-collaborator-list" id="boardCollaboratorList">
                                @foreach($employees as $employee)
                                    <div class="col-md-6 board-collab-item d-none" data-department-id="{{ $employee->department_id }}" data-search="{{ Str::lower($employee->name . ' ' . optional($employee->department)->department_name) }}">
                                        <label class="w-100 p-2 border rounded-3 d-flex gap-2 align-items-center board-collaborator-choice">
                                            <input type="checkbox" name="collaborators[]" value="{{ $employee->id }}">
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
                            <label class="form-label fw-bold">ไฟล์แนบ</label>
                            <input type="file" name="attachments[]" id="boardAttachmentsInput" class="form-control" accept=".jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.ppt,.pptx" multiple>
                            <div class="text-muted small mt-1">
                                รองรับเฉพาะไฟล์ <strong>รูปภาพ (JPG, PNG)</strong>, <strong>Word (DOC, DOCX)</strong>,
                                <strong>Excel (XLS, XLSX)</strong> และ <strong>PowerPoint (PPT, PPTX)</strong> เท่านั้น
                                — ไฟล์ละไม่เกิน <strong>10 MB</strong> สูงสุด 5 ไฟล์ต่องาน
                                <span class="text-danger">ไม่รองรับไฟล์ ZIP หรือไฟล์ประเภทอื่นใดทั้งสิ้น</span>
                            </div>
                            <div class="text-danger small mt-1 d-none" id="boardAttachmentsError"></div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer board-modal-foot">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึกงาน</button>
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

    let activeMetricFilter = 'all';

    const metricFilterLabels = {
        all: ['รายการงานทั้งหมด', 'แสดงงานทั้งหมดตามแผนกและผู้รับผิดชอบที่เลือก'],
        pending: ['งานรอดำเนินการ', 'แสดงคนที่มีงานรอดำเนินการอยู่ตอนนี้'],
        doing: ['งานกำลังทำ', 'แสดงคนที่กำลังดำเนินงานอยู่ตอนนี้'],
        done: ['งานเสร็จสิ้น', 'แสดงงานที่ทำเสร็จแล้ว'],
        paused: ['งานพักชั่วคราว', 'แสดงงานที่พักไว้ชั่วคราว'],
        late: ['งานล่าช้า', 'แสดงงานที่เลยกำหนดหรืออยู่ในสถานะล่าช้า'],
    };

    function filterByAssignee(value) {
        const url = new URL(window.location.href);
        if (value) {
            url.searchParams.set('assignee', value);
        } else {
            url.searchParams.delete('assignee');
        }
        window.location.href = url.toString();
    }

    function filterRows(value) {
        applyBoardFilters();
    }

    function rowMatchesMetric(row) {
        if (activeMetricFilter === 'all') return true;
        if (activeMetricFilter === 'late') return row.dataset.overdue === '1';

        const statusMap = {
            pending: '1',
            doing: '2',
            done: '4',
            paused: '5',
        };

        return row.dataset.status === statusMap[activeMetricFilter];
    }

    function applyBoardFilters() {
        const keyword = (document.getElementById('boardSearch')?.value || '').trim().toLowerCase();
        let visibleCount = 0;

        document.querySelectorAll('#workRows tr[data-search]').forEach((row) => {
            const visible = row.dataset.search.includes(keyword) && rowMatchesMetric(row);
            row.style.display = visible ? '' : 'none';
            if (visible) visibleCount += 1;
        });

        const emptyRow = document.getElementById('boardFilterEmpty');
        if (emptyRow) emptyRow.style.display = visibleCount === 0 ? '' : 'none';

        const count = document.getElementById('visibleJobCount');
        if (count) count.textContent = visibleCount;

        const label = metricFilterLabels[activeMetricFilter] || metricFilterLabels.all;
        const title = document.getElementById('boardListTitle');
        const desc = document.getElementById('boardListDesc');
        if (title) title.textContent = label[0];
        if (desc) desc.textContent = label[1];
    }

    document.querySelectorAll('[data-metric-filter]').forEach((card) => {
        card.addEventListener('click', () => {
            activeMetricFilter = card.dataset.metricFilter || 'all';

            document.querySelectorAll('[data-metric-filter]').forEach((item) => {
                const isActive = item === card;
                item.classList.toggle('active', isActive);
                item.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            applyBoardFilters();
            document.getElementById('boardListTitle')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

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

    document.querySelector('#boardCreateTaskModal form')?.addEventListener('submit', function (event) {
        const assigneeId = document.getElementById('boardTaskAssigneeId')?.value;
        if (!assigneeId) {
            event.preventDefault();
            alert('กรุณาเลือกผู้รับผิดชอบจากรายชื่อที่ระบบแนะนำ');
        }
    });
</script>
@endpush


