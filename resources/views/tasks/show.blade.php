@extends('layouts.app')

@section('title', 'รายละเอียดงาน')

@push('styles')
<style>
    .task-detail-page { max-width: 1180px; margin: 0 auto; }
    .back-link { display:inline-flex; align-items:center; gap:8px; color:var(--text-muted); font-weight:800; margin-bottom:14px; text-decoration:none; }
    .detail-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:16px; }
    .detail-head h1 { margin:0 0 10px; font-size:34px; font-weight:850; letter-spacing:0; }
    .detail-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
    .detail-grid { display:grid; grid-template-columns:minmax(0, 1fr) 360px; gap:18px; align-items:start; }
    .stack { display:grid; gap:16px; }
    .detail-card { background:#fff; border:1px solid var(--border); border-radius:16px; padding:18px; box-shadow:var(--shadow-sm); min-width:0; }
    .detail-card h2 { margin:0 0 12px; font-size:18px; font-weight:850; }
    .description { line-height:1.75; white-space:pre-line; font-size:16px; }
    .info-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
    .info-item { border:1px solid var(--border); border-radius:12px; padding:12px; background:var(--surface-1); }
    .info-label, .muted { color:var(--text-muted); font-size:12px; font-weight:700; }
    .info-value { font-weight:850; }
    .pill { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:7px 11px; font-size:13px; font-weight:850; white-space:nowrap; }
    .pill.gray { background:#f1f5f9; color:#475569; }
    .pill.amber { background:#fff7ed; color:#c2410c; }
    .pill.purple { background:#f3e8ff; color:#6d28d9; }
    .pill.green { background:#dcfce7; color:#15803d; }
    .pill.red { background:#fee2e2; color:#b91c1c; }
    .progress-track { height:12px; border-radius:999px; background:var(--surface-2); overflow:hidden; margin-top:10px; }
    .progress-fill { height:100%; width:var(--w); border-radius:999px; background:linear-gradient(90deg, var(--accent), #16a34a); }
    .btn-soft { min-height:42px; border-radius:12px; border:1px solid var(--border); background:#fff; color:var(--text-main); padding:0 14px; display:inline-flex; align-items:center; gap:8px; font-weight:800; text-decoration:none; }
    .btn-green { border:0; background:#16a34a; color:#fff; }
    .btn-red { border-color:#fecdd3; color:#be123c; background:#fff1f2; }
    .btn-primary-solid { border:0; background:var(--accent); color:#fff; }
    .form-grid { display:grid; gap:10px; }
    .form-grid select, .form-grid input, .form-grid textarea { min-height:42px; border:1px solid var(--border); border-radius:12px; padding:10px 12px; background:#fff; font-weight:700; }
    .form-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .form-actions .btn-soft { width:auto; min-width:150px; justify-content:center; }
    .people-list, .file-list, .update-list { display:grid; gap:10px; }
    .person-row, .file-row, .update-row { border:1px solid var(--border); border-radius:12px; padding:12px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .update-row { align-items:flex-start; }
    .avatar-sm { width:42px; height:42px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; color:#fff; font-weight:850; background:var(--accent); overflow:hidden; flex:0 0 auto; }
    .avatar-sm img { width:100%; height:100%; object-fit:cover; }
    .empty { color:var(--text-muted); text-align:center; padding:18px; border:1px dashed var(--border); border-radius:12px; background:var(--surface-1); }
    .danger-note { background:#fff1f2; border:1px solid #fecdd3; color:#9f1239; border-radius:12px; padding:12px; font-weight:700; }
    .drop-zone { border:1.5px dashed #cfd7e6; background:#f8fafc; border-radius:14px; padding:18px; text-align:center; cursor:pointer; transition:.18s ease; }
    .drop-zone:hover, .drop-zone.dragover { border-color:var(--accent); background:#f4f1ff; }
    .drop-zone i { display:block; font-size:28px; color:var(--accent); margin-bottom:6px; }
    .drop-zone input { display:none; }
    .file-selected { margin-top:8px; color:var(--text-muted); font-size:13px; font-weight:700; }
    .team-head { display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .team-toggle, .team-remove { width:32px; height:32px; border-radius:10px; border:1px solid var(--border); background:#fff; color:var(--accent); display:inline-flex; align-items:center; justify-content:center; }
    .team-remove { color:#be123c; background:#fff1f2; border-color:#fecdd3; }
    .invite-list { display:grid; gap:8px; max-height:320px; overflow:auto; padding-right:4px; }
    .invite-option { display:flex; align-items:center; gap:10px; border:1px solid var(--border); border-radius:12px; padding:10px; cursor:pointer; }
    .invite-option:hover { background:var(--surface-1); }
    .invite-option input { width:18px; height:18px; }
    .progress-input-row { display:grid; grid-template-columns:minmax(0, 1fr) 96px; gap:12px; align-items:center; }
    @media (max-width:980px) { .detail-head { flex-direction:column; } .detail-actions { justify-content:flex-start; } .detail-grid { grid-template-columns:1fr; } }
    @media (max-width:680px) { .info-grid { grid-template-columns:1fr; } .detail-head h1 { font-size:28px; } }
</style>
@endpush

@section('content')
@php
    $currentUser = auth()->user();
    $isAdmin = $currentUser?->role === 'admin';
    $isOwner = in_array($currentUser?->id, [$job->user_id, $job->created_by, $job->leader_user_id], true);
    $acceptedCollaborator = $job->collaborators->first(fn ($person) => $person->id === $currentUser?->id && $person->pivot?->status === 'accepted');
    $pendingInvitation = $job->collaborators->first(fn ($person) => $person->id === $currentUser?->id && $person->pivot?->status === 'pending');
    $canUpdateStatus = $isAdmin || $isOwner || (bool) $acceptedCollaborator;
    $isDone = (int) $job->job_status === 4;
    $isLate = $job->job_due_at && ! $isDone && $job->job_due_at->lt(now());
    $statusMap = [
        1 => ['label' => $job->approval_status === 'pending' ? 'รออนุมัติ' : 'รอดำเนินการ', 'tone' => $job->approval_status === 'pending' ? 'amber' : 'gray'],
        2 => ['label' => 'กำลังดำเนินงาน', 'tone' => 'purple'],
        3 => ['label' => 'ตรวจสอบ', 'tone' => 'amber'],
        4 => ['label' => 'เสร็จสิ้น', 'tone' => 'green'],
        5 => ['label' => 'พักงานชั่วคราว', 'tone' => 'gray'],
    ];
    $priorityMap = [
        1 => ['label' => 'ต่ำ', 'tone' => 'gray'],
        2 => ['label' => 'กลาง', 'tone' => 'amber'],
        3 => ['label' => 'สูง', 'tone' => 'red'],
    ];
    $status = $isLate ? ['label' => 'ล่าช้า', 'tone' => 'red'] : ($statusMap[(int) $job->job_status] ?? $statusMap[1]);
    $priority = $priorityMap[(int) $job->job_priority] ?? $priorityMap[2];
    $backRoute = $isAdmin || $currentUser?->role === 'viewer' ? route('board.index') : route('mytasks.index');
    $team = collect([$job->user, $job->leader])->filter()->merge($job->collaborators)->unique('id')->values();
    $canManageTeam = $canManageTeam ?? false;
@endphp

<div class="task-detail-page">
    <a href="{{ $backRoute }}" class="back-link"><i class="bi bi-arrow-left"></i> กลับไปหน้ารายการงาน</a>

    <div class="detail-head">
        <div>
            <h1>{{ $job->job_topic }}</h1>
            <div class="d-flex flex-wrap gap-2">
                <span class="pill {{ $status['tone'] }}">{{ $status['label'] }}</span>
                <span class="pill {{ $priority['tone'] }}">ความสำคัญ {{ $priority['label'] }}</span>
                @if($job->delete_requested_at)
                    <span class="pill red">รออนุมัติลบงาน</span>
                @endif
            </div>
        </div>

        <div class="detail-actions">
            @if($pendingInvitation)
                <form method="POST" action="{{ route('tasks.invitation.respond', $job->job_id) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="status" value="accepted">
                    <button type="submit" class="btn-soft btn-green"><i class="bi bi-check-lg"></i> รับเข้าร่วม</button>
                </form>
                <form method="POST" action="{{ route('tasks.invitation.respond', $job->job_id) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="status" value="rejected">
                    <button type="submit" class="btn-soft btn-red"><i class="bi bi-x-lg"></i> ปฏิเสธ</button>
                </form>
            @endif

            @if($isAdmin && $job->delete_requested_at)
                <form method="POST" action="{{ route('admin.tasks.deleteRequest.approve', $job->job_id) }}" class="confirm-delete-request-form">
                    @csrf @method('PATCH')
                    <button type="submit" class="btn-soft btn-red"><i class="bi bi-trash"></i> อนุมัติลบงาน</button>
                </form>
            @endif

            @if($isAdmin && ! $job->delete_requested_at)
                <form method="POST" action="{{ route('admin.tasks.destroy', $job->job_id) }}" class="admin-delete-form">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn-soft btn-red"><i class="bi bi-trash3"></i> ลบงานทันที</button>
                </form>
            @elseif($canUpdateStatus && ! $isDone && ! $job->delete_requested_at)
                <button type="button" class="btn-soft btn-red" data-bs-toggle="modal" data-bs-target="#deleteRequestModal">
                    <i class="bi bi-trash3"></i> ขอลบงาน
                </button>
            @endif

            @if($isAdmin && $job->approval_status === 'pending')
                <form method="POST" action="{{ route('admin.tasks.approval', $job->job_id) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="approval_status" value="approved">
                    <button type="submit" class="btn-soft btn-green"><i class="bi bi-check2-circle"></i> อนุมัติ</button>
                </form>
                <form method="POST" action="{{ route('admin.tasks.approval', $job->job_id) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="approval_status" value="rejected">
                    <button type="submit" class="btn-soft btn-red"><i class="bi bi-x-circle"></i> ปฏิเสธ</button>
                </form>
            @endif
        </div>
    </div>

    <div class="detail-grid">
        <div class="stack">
            <section class="detail-card">
                <h2>รายละเอียดงาน</h2>
                <div class="description">{{ $job->job_details ?: 'ยังไม่มีรายละเอียดงาน' }}</div>
            </section>

            <section class="detail-card">
                <h2>ข้อมูลสำคัญ</h2>
                <div class="info-grid">
                    <div class="info-item"><div class="info-label">ผู้รับผิดชอบหลัก</div><div class="info-value">{{ optional($job->user)->name ?? '-' }}</div></div>
                    <div class="info-item"><div class="info-label">หัวหน้างาน</div><div class="info-value">{{ optional($job->leader)->name ?? optional($job->user)->name ?? '-' }}</div></div>
                    <div class="info-item"><div class="info-label">วันที่เริ่ม</div><div class="info-value">{{ optional($job->job_start_at)->format('d/m/Y H:i') ?? '-' }}</div></div>
                    <div class="info-item"><div class="info-label">กำหนดส่ง</div><div class="info-value">{{ optional($job->job_due_at)->format('d/m/Y H:i') ?? '-' }}</div></div>
                </div>
            </section>

            @if($canUpdateStatus && ! $isDone && $job->approval_status === 'approved')
                <section class="detail-card">
                    <h2>อัปเดตความคืบหน้าและประวัติ</h2>
                    <form method="POST" action="{{ route('tasks.progress.store', $job->job_id) }}" class="form-grid ajax-form" data-success="อัปเดตความคืบหน้าสำเร็จ">
                        @csrf
                        <label class="field">
                            <span class="muted">ความคืบหน้า (%)</span>
                            <div class="progress-input-row">
                                <input type="range" min="0" max="99" step="1" name="progress" value="{{ min(99, (int) $job->job_progress) }}" oninput="document.getElementById('progressNumber').value=this.value;document.getElementById('progressValue').textContent=this.value + '%'">
                                <input id="progressNumber" type="number" min="0" max="99" step="1" value="{{ min(99, (int) $job->job_progress) }}" oninput="const value=Math.max(0, Math.min(99, this.value || 0)); this.value=value; this.form.querySelector('[name=progress]').value=value; document.getElementById('progressValue').textContent=value + '%'">
                            </div>
                            <strong id="progressValue">{{ min(99, (int) $job->job_progress) }}%</strong>
                        </label>
                        <label class="field">
                            <span class="muted">ตอนนี้ทำถึงขั้นไหนแล้ว</span>
                            <textarea name="note" rows="3" required placeholder="เช่น ติดตั้งโปรแกรมเสร็จแล้ว เหลือทดสอบกับเครื่องผู้ใช้"></textarea>
                        </label>
                        <div class="form-actions">
                            <button type="submit" class="btn-soft btn-primary-solid"><i class="bi bi-send-check"></i> บันทึกอัปเดต</button>
                        </div>
                    </form>

                    <div style="margin-top:16px;">
                        <h2 style="font-size:16px;">ประวัติการอัปเดต</h2>
                        @if($job->updates->isNotEmpty())
                            <div class="update-list">
                                @foreach($job->updates as $update)
                                    <div class="update-row">
                                        <div>
                                            <strong>{{ optional($update->user)->name ?? 'ผู้ใช้' }} อัปเดต {{ $update->progress }}%</strong>
                                            <div class="muted">{{ optional($update->created_at)->format('d/m/Y H:i') }}</div>
                                            <div style="margin-top:6px;">{{ $update->note }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="empty">ยังไม่มีประวัติการอัปเดตงาน</div>
                        @endif
                    </div>
                </section>
            @endif

            <section class="detail-card {{ $canUpdateStatus && ! $isDone && $job->approval_status === 'approved' ? 'd-none' : '' }}">
                <h2>ประวัติการอัปเดต</h2>
                @if($job->updates->isNotEmpty())
                    <div class="update-list">
                        @foreach($job->updates as $update)
                            <div class="update-row">
                                <div>
                                    <strong>{{ optional($update->user)->name ?? 'ผู้ใช้' }} อัปเดต {{ $update->progress }}%</strong>
                                    <div class="muted">{{ optional($update->created_at)->format('d/m/Y H:i') }}</div>
                                    <div style="margin-top:6px;">{{ $update->note }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="empty">ยังไม่มีประวัติการอัปเดตงาน</div>
                @endif
            </section>

            <section class="detail-card">
                <h2>ไฟล์แนบ</h2>
                @if($job->images->isNotEmpty())
                    <div class="file-list">
                        @foreach($job->images as $file)
                            <a class="file-row" href="{{ route('media.show', ['path' => $file->file_path]) }}" target="_blank">
                                <span><i class="bi bi-paperclip"></i> {{ $file->original_name ?? basename($file->file_path) }}</span>
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="empty">ยังไม่มีไฟล์แนบ</div>
                @endif

                @if($canUpdateStatus)
                    <form method="POST" action="{{ route('tasks.attachments.store', $job->job_id) }}" enctype="multipart/form-data" class="form-grid ajax-form mt-3 attachment-form" data-success="แนบไฟล์สำเร็จ">
                        @csrf
                        <label class="drop-zone">
                            <i class="bi bi-cloud-arrow-up"></i>
                            <strong>ลากไฟล์มาวาง หรือกดเพื่อเลือกไฟล์</strong>
                            <div class="muted">รองรับ PDF, PNG, JPG, JPEG, Excel, CSV และไฟล์ ZIP สำหรับโฟลเดอร์</div>
                            <input type="file" name="completion_attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,.xls,.xlsx,.csv,.zip">
                            <div class="file-selected">ยังไม่ได้เลือกไฟล์</div>
                        </label>
                        <div class="form-actions">
                            <button type="submit" class="btn-soft btn-primary-solid" disabled><i class="bi bi-save"></i> บันทึกไฟล์แนบ</button>
                        </div>
                    </form>
                @endif
            </section>
        </div>

        <aside class="stack">
            <section class="detail-card">
                <h2>ความคืบหน้า</h2>
                <div class="d-flex align-items-center justify-content-between">
                    <strong style="font-size:24px;">{{ (int) $job->job_progress }}%</strong>
                    <span class="pill {{ $status['tone'] }}">{{ $status['label'] }}</span>
                </div>
                <div class="progress-track"><div class="progress-fill" style="--w:{{ (int) $job->job_progress }}%;"></div></div>
            </section>

            @if($canUpdateStatus && $job->approval_status === 'approved' && ! $isDone)
                <section class="detail-card">
                    <h2>ปรับสถานะงาน</h2>
                    <form method="POST" action="{{ route('tasks.updateStatus', $job->job_id) }}" enctype="multipart/form-data" class="form-grid ajax-form status-form" data-success="บันทึกสถานะสำเร็จ">
                        @csrf @method('PATCH')
                        <select name="job_status" id="statusSelect">
                            <option value="2" @selected((int) $job->job_status === 2)>กำลังดำเนินงาน</option>
                            <option value="3" @selected((int) $job->job_status === 3)>ตรวจสอบ</option>
                            <option value="5" @selected((int) $job->job_status === 5)>พักงานชั่วคราว</option>
                            <option value="4">เสร็จสิ้น</option>
                        </select>
                        <button type="submit" class="btn-soft btn-green"><i class="bi bi-check-square"></i> บันทึกสถานะ</button>
                    </form>
                </section>
            @elseif($isDone)
                <section class="detail-card">
                    <div class="danger-note">งานนี้ปิดแล้ว ดูรายละเอียดได้เท่านั้น หากต้องการแนบหลักฐานเพิ่มเติมให้ใช้กล่องไฟล์แนบด้านซ้าย</div>
                </section>
            @endif

            @if($job->delete_requested_at)
                <section class="detail-card">
                    <h2>คำขอลบงาน</h2>
                    <div class="danger-note">
                        ขอโดย {{ optional($job->deleteRequester)->name ?? '-' }}<br>
                        เหตุผล: {{ $job->delete_request_reason ?: '-' }}
                    </div>
                </section>
            @endif

            <section class="detail-card">
                <div class="team-head">
                    <h2>ทีมในงานนี้</h2>
                    @if($canManageTeam)
                        <button type="button" class="team-toggle" title="เชิญผู้ร่วมงาน" data-bs-toggle="modal" data-bs-target="#inviteCollaboratorModal">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    @endif
                </div>
                <div class="people-list">
                    @foreach($team as $person)
                        <div class="person-row">
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar-sm">
                                    @if($person->profile_image)
                                        <img src="{{ route('media.show', ['path' => $person->profile_image]) }}" alt="{{ $person->name }}">
                                    @else
                                        {{ mb_substr($person->name, 0, 2) }}
                                    @endif
                                </span>
                                <div>
                                    <strong>{{ $person->name }}</strong>
                                    <div class="muted">
                                        @if($person->id === $job->user_id)
                                            ผู้รับผิดชอบหลัก
                                        @elseif($person->pivot?->status === 'pending')
                                            รอตอบรับคำเชิญ
                                        @else
                                            ผู้ร่วมงาน
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @if($canManageTeam && $job->collaborators->contains('id', $person->id) && ! in_array($person->id, [$job->user_id, $job->created_by, $job->leader_user_id], true))
                                <form method="POST" action="{{ route('tasks.collaborators.destroy', [$job->job_id, $person->id]) }}" class="ajax-form remove-collaborator-form" data-success="นำผู้ร่วมงานออกแล้ว">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="team-remove" title="นำออกจากทีม">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        </aside>
    </div>
</div>

@if($canManageTeam)
<div class="modal fade" id="inviteCollaboratorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('tasks.collaborators.store', $job->job_id) }}" class="modal-content ajax-form" data-success="ส่งคำเชิญเข้าร่วมโปรเจกต์สำเร็จ">
            @csrf
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold">เชิญคนเข้าร่วมโปรเจกต์</h5>
                    <div class="text-muted small">เลือกพนักงานที่ต้องการให้เข้ามาช่วยงานนี้</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                @if($availableCollaborators->isEmpty())
                    <div class="empty">ยังไม่มีพนักงานที่สามารถเชิญเข้าทีมได้</div>
                @else
                    <label class="field mb-3">
                        <span class="muted">ค้นหาพนักงาน</span>
                        <input type="search" id="inviteCollaboratorSearch" placeholder="พิมพ์ชื่อพนักงานหรือแผนก">
                    </label>
                    <div class="invite-list">
                        @foreach($availableCollaborators as $employee)
                            <label class="invite-option" data-search="{{ Str::lower($employee->name . ' ' . optional($employee->department)->department_name) }}">
                                <input type="checkbox" name="collaborators[]" value="{{ $employee->id }}">
                                <span class="avatar-sm">
                                    @if($employee->profile_image)
                                        <img src="{{ route('media.show', ['path' => $employee->profile_image]) }}" alt="{{ $employee->name }}">
                                    @else
                                        {{ mb_substr($employee->name, 0, 2) }}
                                    @endif
                                </span>
                                <span>
                                    <strong>{{ $employee->name }}</strong>
                                    <span class="muted d-block">{{ optional($employee->department)->department_name ?? '-' }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-soft" data-bs-dismiss="modal">ยกเลิก</button>
                @if($availableCollaborators->isNotEmpty())
                    <button type="submit" class="btn-soft btn-primary-solid"><i class="bi bi-send-plus"></i> ส่งคำเชิญ</button>
                @endif
            </div>
        </form>
    </div>
</div>
@endif

@if(! $isAdmin && $canUpdateStatus && ! $isDone && ! $job->delete_requested_at)
<div class="modal fade" id="deleteRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('tasks.deleteRequest.store', $job->job_id) }}" class="modal-content ajax-form" data-success="ส่งคำขอลบงานแล้ว">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title fw-bold">ขออนุญาตลบงาน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-grid">
                    <span class="muted">เหตุผลที่ต้องการลบงาน</span>
                    <textarea name="reason" rows="4" required placeholder="อธิบายเหตุผลให้ผู้ดูแลระบบพิจารณา"></textarea>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-soft" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn-soft btn-red">ส่งคำขอ</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

    document.querySelectorAll('.attachment-form').forEach((form) => {
        const zone = form.querySelector('.drop-zone');
        const input = form.querySelector('input[type="file"]');
        const label = form.querySelector('.file-selected');
        const button = form.querySelector('button[type="submit"]');
        const updateLabel = () => {
            const count = input.files?.length || 0;
            label.textContent = count ? `เลือกแล้ว ${count} ไฟล์` : 'ยังไม่ได้เลือกไฟล์';
            button.disabled = count === 0;
        };

        ['dragenter', 'dragover'].forEach((name) => {
            zone.addEventListener(name, (event) => {
                event.preventDefault();
                zone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach((name) => {
            zone.addEventListener(name, (event) => {
                event.preventDefault();
                zone.classList.remove('dragover');
            });
        });

        zone.addEventListener('drop', (event) => {
            input.files = event.dataTransfer.files;
            updateLabel();
        });

        input.addEventListener('change', updateLabel);
        updateLabel();
    });

    document.getElementById('inviteCollaboratorSearch')?.addEventListener('input', (event) => {
        const query = event.target.value.toLowerCase().trim();
        document.querySelectorAll('.invite-option').forEach((option) => {
            option.style.display = option.dataset.search.includes(query) ? 'flex' : 'none';
        });
    });

    document.querySelectorAll('.ajax-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (form.classList.contains('status-form') && form.querySelector('[name="job_status"]')?.value === '4') {
                const confirm = await Swal.fire({
                    icon: 'question',
                    title: 'ยืนยันปิดงาน?',
                    text: 'เมื่อปิดงานแล้วจะไม่สามารถเปลี่ยนสถานะกลับได้',
                    showCancelButton: true,
                    confirmButtonText: 'ปิดงาน',
                    cancelButtonText: 'ยกเลิก'
                });
                if (!confirm.isConfirmed) return;
            }

            const response = await fetch(form.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                body: new FormData(form),
            });

            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload.ok === false) {
                Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: payload.message || 'กรุณาลองใหม่' });
                return;
            }

            await Swal.fire({ icon: 'success', title: payload.message || form.dataset.success || 'สำเร็จ', timer: 1400, showConfirmButton: false });
            if (payload.redirect) {
                window.location.href = payload.redirect;
                return;
            }
            window.location.reload();
        });
    });

    document.querySelectorAll('.admin-delete-form, .confirm-delete-request-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const confirm = await Swal.fire({
                icon: 'warning',
                title: 'ยืนยันลบงาน?',
                text: 'เมื่อลบแล้วงานจะหายออกจากระบบ',
                showCancelButton: true,
                confirmButtonText: 'ลบงาน',
                cancelButtonText: 'ยกเลิก'
            });
            if (confirm.isConfirmed) form.submit();
        });
    });
</script>
@endpush
