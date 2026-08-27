<section class="admin-assignment-approval mt-4" id="collaborator-approval-queue" aria-labelledby="collaboratorApprovalHeading">
    <div class="admin-assignment-approval__heading">
        <div>
            <span class="admin-assignment-approval__eyebrow">COLLABORATOR APPROVAL</span>
            <h2 id="collaboratorApprovalHeading">ผู้ร่วมงานข้ามแผนกรออนุมัติ</h2>
        </div>
        <span class="badge rounded-pill text-bg-warning">{{ $pendingCollaboratorTasks->sum(fn ($task) => $task->collaborators->count()) }} รายการ</span>
    </div>

    @if($pendingCollaboratorTasks->isEmpty())
        <p class="admin-assignment-approval__empty mb-0">ไม่มีคำขอผู้ร่วมงานข้ามแผนกที่รอการตัดสินใจ</p>
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>งาน / Project</th><th>ผู้เชิญ</th><th>ผู้ร่วมงาน</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                <tbody>
                    @foreach($pendingCollaboratorTasks as $task)
                        @foreach($task->collaborators as $candidate)
                            @php($inviter = $pendingCollaboratorInviters->get($candidate->pivot?->added_by))
                            <tr>
                                <td><strong class="d-block">{{ $task->job_topic }}</strong><span class="text-muted small">{{ $task->taskList?->name ?? 'งานทั่วไป' }}</span></td>
                                <td><span class="d-block">{{ $inviter?->name ?? '-' }}</span><span class="text-muted small">{{ $inviter?->department?->department_name ?? 'ไม่ระบุแผนก' }}</span></td>
                                <td><span class="d-block">{{ $candidate->name }}</span><span class="text-muted small">{{ $candidate->department?->department_name ?? 'ไม่ระบุแผนก' }}</span></td>
                                <td><span class="badge rounded-pill text-bg-warning">pending</span></td>
                                <td><div class="admin-assignment-approval__actions">
                                    @foreach(['accepted' => ['อนุมัติ', 'btn-success', 'bi-check-lg'], 'rejected' => ['ปฏิเสธ', 'btn-outline-danger', 'bi-x-lg']] as $decision => $meta)
                                        <form method="POST" action="{{ route('admin.tasks.collaborators.approval', [$task, $candidate]) }}" data-approval-form data-approval-kind="collaborator" data-decision="{{ $decision }}" data-topic="{{ $task->job_topic }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="{{ $decision }}">
                                            <button type="submit" class="btn btn-sm {{ $meta[1] }}"><i class="bi {{ $meta[2] }}" aria-hidden="true"></i> {{ $meta[0] }}</button>
                                        </form>
                                    @endforeach
                                </div></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
