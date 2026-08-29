<section class="admin-approvals-queue" id="collaborator-approval-queue" aria-labelledby="collaboratorApprovalHeading">
    <div class="admin-approvals-queue__heading">
        <div>
            <span>COLLABORATOR APPROVAL</span>
            <h2 id="collaboratorApprovalHeading">ผู้ร่วมงานข้ามแผนก</h2>
        </div>
        <span class="badge rounded-pill text-bg-warning">{{ $approvalCounts['collaborators'] }} รายการ</span>
    </div>

    @if($approvalCounts['collaborators'] === 0)
        <div class="admin-approvals-empty">
            <i class="bi bi-check-circle" aria-hidden="true"></i>
            <p>ไม่มีคำขอผู้ร่วมงานข้ามแผนกที่รอการตัดสินใจ</p>
        </div>
    @else
        <div class="admin-approvals-list" role="table" aria-label="ผู้ร่วมงานข้ามแผนกรออนุมัติ">
            <div class="admin-approvals-list__header" role="row">
                <span role="columnheader">งาน / Project</span>
                <span role="columnheader">ผู้เชิญ</span>
                <span role="columnheader">ผู้ร่วมงาน</span>
                <span role="columnheader">สถานะ</span>
                <span role="columnheader">จัดการ</span>
            </div>
            @foreach($pendingCollaboratorTasks as $task)
                @foreach($task->collaborators as $candidate)
                    @php($inviter = $pendingCollaboratorInviters->get($candidate->pivot?->added_by))
                    <article class="admin-approvals-request" role="row">
                        <div class="admin-approvals-request__cell admin-approvals-request__topic" role="cell" data-label="งาน / Project">
                            <strong>{{ $task->job_topic }}</strong>
                            <span>{{ $task->taskList?->name ?? 'งานทั่วไป' }}</span>
                        </div>
                        <div class="admin-approvals-request__cell" role="cell" data-label="ผู้เชิญ">
                            <strong>{{ $inviter?->name ?? '-' }}</strong>
                            <span>{{ $inviter?->department?->department_name ?? 'ไม่ระบุแผนก' }}</span>
                        </div>
                        <div class="admin-approvals-request__cell" role="cell" data-label="ผู้ร่วมงาน">
                            <strong>{{ $candidate->name }}</strong>
                            <span>{{ $candidate->department?->department_name ?? 'ไม่ระบุแผนก' }}</span>
                        </div>
                        <div class="admin-approvals-request__cell" role="cell" data-label="สถานะ">
                            <span class="badge rounded-pill text-bg-warning">pending</span>
                        </div>
                        <div class="admin-approvals-request__cell admin-approvals-request__actions" role="cell" data-label="จัดการ">
                            @foreach(['accepted' => ['อนุมัติ', 'btn-success', 'bi-check-lg'], 'rejected' => ['ปฏิเสธ', 'btn-outline-danger', 'bi-x-lg']] as $decision => $meta)
                                <form method="POST" action="{{ route('admin.tasks.collaborators.approval', [$task, $candidate]) }}"
                                    data-approval-form data-approval-kind="collaborator" data-decision="{{ $decision }}" data-topic="{{ $task->job_topic }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ $decision }}">
                                    <button type="submit" class="btn btn-sm {{ $meta[1] }}"><i class="bi {{ $meta[2] }}" aria-hidden="true"></i> {{ $meta[0] }}</button>
                                </form>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            @endforeach
        </div>
    @endif
</section>
