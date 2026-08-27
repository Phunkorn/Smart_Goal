@php
    use App\Support\WorkBoardDesign;
@endphp

<section class="admin-assignment-approval" id="assignment-approval-queue" aria-labelledby="assignmentApprovalHeading">
    <div class="admin-assignment-approval__heading">
        <div>
            <span class="admin-assignment-approval__eyebrow">ASSIGNMENT APPROVAL</span>
            <h2 id="assignmentApprovalHeading">งานข้ามแผนกรออนุมัติ</h2>
        </div>
        <span class="badge rounded-pill text-bg-warning">{{ $pendingAssignments->count() }} รายการ</span>
    </div>

    @if($pendingAssignments->isEmpty())
        <p class="admin-assignment-approval__empty mb-0">ไม่มีงานข้ามแผนกที่รอการตัดสินใจ</p>
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>งาน / Project</th>
                        <th>ผู้มอบหมาย</th>
                        <th>ผู้รับ</th>
                        <th>วันที่</th>
                        <th>Priority</th>
                        <th>สถานะ</th>
                        <th class="text-end">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pendingAssignments as $assignment)
                        @php($priority = WorkBoardDesign::taskPriority((int) $assignment->job_priority))
                        <tr>
                            <td>
                                <strong class="d-block">{{ $assignment->job_topic }}</strong>
                                <span class="text-muted small">{{ $assignment->taskList?->name ?? 'งานทั่วไป' }}</span>
                            </td>
                            <td>
                                <span class="d-block">{{ $assignment->creator?->name ?? '-' }}</span>
                                <span class="text-muted small">{{ $assignment->creator?->department?->department_name ?? 'ไม่ระบุแผนก' }}</span>
                            </td>
                            <td>
                                <span class="d-block">{{ $assignment->user?->name ?? '-' }}</span>
                                <span class="text-muted small">{{ $assignment->user?->department?->department_name ?? 'ไม่ระบุแผนก' }}</span>
                            </td>
                            <td class="text-nowrap">
                                <span class="d-block">{{ $assignment->job_start_at?->timezone('Asia/Bangkok')->format('d/m/Y') ?? '-' }}</span>
                                <span class="text-muted small">ถึง {{ $assignment->job_due_at?->timezone('Asia/Bangkok')->format('d/m/Y') ?? '-' }}</span>
                            </td>
                            <td><span class="badge rounded-pill text-bg-light">{{ $priority['label'] }}</span></td>
                            <td><span class="badge rounded-pill text-bg-warning">pending</span></td>
                            <td>
                                <div class="admin-assignment-approval__actions">
                                    <form method="POST" action="{{ route('admin.tasks.approval', $assignment) }}"
                                        data-assignment-approval-form data-approval-form data-approval-kind="assignment" data-decision="approved" data-topic="{{ $assignment->job_topic }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="approval_status" value="approved">
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="bi bi-check-lg" aria-hidden="true"></i> อนุมัติ
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.tasks.approval', $assignment) }}"
                                        data-assignment-approval-form data-approval-form data-approval-kind="assignment" data-decision="rejected" data-topic="{{ $assignment->job_topic }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="approval_status" value="rejected">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x-lg" aria-hidden="true"></i> ปฏิเสธ
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
