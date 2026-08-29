@php
    use App\Support\WorkBoardDesign;
@endphp

<section class="admin-approvals-queue" id="assignment-approval-queue" aria-labelledby="assignmentApprovalHeading">
    <div class="admin-approvals-queue__heading">
        <div>
            <span>ASSIGNMENT APPROVAL</span>
            <h2 id="assignmentApprovalHeading">งานข้ามแผนก</h2>
        </div>
        <span class="badge rounded-pill text-bg-warning">{{ $approvalCounts['assignments'] }} รายการ</span>
    </div>

    @if($pendingAssignments->isEmpty())
        <div class="admin-approvals-empty">
            <i class="bi bi-check-circle" aria-hidden="true"></i>
            <p>ไม่มีงานข้ามแผนกที่รอการตัดสินใจ</p>
        </div>
    @else
        <div class="admin-approvals-list" role="table" aria-label="งานข้ามแผนกรออนุมัติ">
            <div class="admin-approvals-list__header" role="row">
                <span role="columnheader">งาน / Project</span>
                <span role="columnheader">ผู้มอบหมาย</span>
                <span role="columnheader">ผู้รับงาน</span>
                <span role="columnheader">วันที่ / Priority</span>
                <span role="columnheader">จัดการ</span>
            </div>
            @foreach($pendingAssignments as $assignment)
                @php($priority = WorkBoardDesign::taskPriority((int) $assignment->job_priority))
                <article class="admin-approvals-request" role="row">
                    <div class="admin-approvals-request__cell admin-approvals-request__topic" role="cell" data-label="งาน / Project">
                        <strong>{{ $assignment->job_topic }}</strong>
                        <span>{{ $assignment->taskList?->name ?? 'งานทั่วไป' }}</span>
                        <span class="badge rounded-pill text-bg-warning">pending</span>
                    </div>
                    <div class="admin-approvals-request__cell" role="cell" data-label="ผู้มอบหมาย">
                        <strong>{{ $assignment->creator?->name ?? '-' }}</strong>
                        <span>{{ $assignment->creator?->department?->department_name ?? 'ไม่ระบุแผนก' }}</span>
                    </div>
                    <div class="admin-approvals-request__cell" role="cell" data-label="ผู้รับงาน">
                        <strong>{{ $assignment->user?->name ?? '-' }}</strong>
                        <span>{{ $assignment->user?->department?->department_name ?? 'ไม่ระบุแผนก' }}</span>
                    </div>
                    <div class="admin-approvals-request__cell" role="cell" data-label="วันที่ / Priority">
                        <strong>{{ $assignment->job_start_at?->timezone('Asia/Bangkok')->format('d/m/Y') ?? '-' }} – {{ $assignment->job_due_at?->timezone('Asia/Bangkok')->format('d/m/Y') ?? '-' }}</strong>
                        <span>Priority: {{ $priority['label'] }}</span>
                    </div>
                    <div class="admin-approvals-request__cell admin-approvals-request__actions" role="cell" data-label="จัดการ">
                        <form method="POST" action="{{ route('admin.tasks.approval', $assignment) }}"
                            data-assignment-approval-form data-approval-form data-approval-kind="assignment"
                            data-decision="approved" data-topic="{{ $assignment->job_topic }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="approval_status" value="approved">
                            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg" aria-hidden="true"></i> อนุมัติ</button>
                        </form>
                        <form method="POST" action="{{ route('admin.tasks.approval', $assignment) }}"
                            data-assignment-approval-form data-approval-form data-approval-kind="assignment"
                            data-decision="rejected" data-topic="{{ $assignment->job_topic }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="approval_status" value="rejected">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg" aria-hidden="true"></i> ปฏิเสธ</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
