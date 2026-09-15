@php
    use App\Support\WorkBoardDesign;
@endphp

{{--
    พนักงานของแผนกไปร่วมงานของแผนกอื่น — มุมมองติดตาม ไม่ใช่คิวรออนุมัติ

    จัดกลุ่มตามแผนกปลายทางของงาน ปุ่ม "ดูในบอร์ด" พาไป Workspace ของพนักงานคนนั้น
    ในมุมมองบอร์ด กรอง "งานข้ามแผนก" และเปิดงานใบนั้นให้ทันที Workspace นั้นอ่านอย่างเดียว
    หัวหน้าต้นสังกัดดูงานและคอมเมนต์ได้ (WorkOrderPolicy::comment) แต่แก้เวลาหรือสถานะไม่ได้
--}}
<section class="admin-approvals-queue" id="outgoing-collaboration-queue" aria-labelledby="outgoingCollaborationHeading">
    <div class="admin-approvals-queue__heading">
        <div>
            <span>OUTGOING COLLABORATION</span>
            <h2 id="outgoingCollaborationHeading">พนักงานไปร่วมงานแผนกอื่น</h2>
            <p>ดูงานและคอมเมนต์ได้ แต่ปรับเวลาหรือเปลี่ยนสถานะงานของแผนกอื่นไม่ได้</p>
        </div>
        <span class="badge rounded-pill text-bg-primary">{{ $outgoingCollaborations->count() }} รายการ</span>
    </div>

    @if($outgoingCollaborations->isEmpty())
        <div class="admin-approvals-empty">
            <i class="bi bi-check-circle" aria-hidden="true"></i>
            <p>ยังไม่มีพนักงานที่ไปร่วมงานกับแผนกอื่น</p>
        </div>
    @else
        @foreach($outgoingCollaborations->groupBy('destination') as $destination => $rows)
            <div class="admin-approvals-group">
                <header class="admin-approvals-group__heading">
                    <i class="bi bi-building" aria-hidden="true"></i>
                    <strong>แผนก{{ $destination }}</strong>
                    <span>{{ $rows->count() }} รายการ</span>
                </header>
                <div class="admin-approvals-list" role="table" aria-label="พนักงานที่ไปร่วมงานกับแผนก{{ $destination }}">
                    <div class="admin-approvals-list__header" role="row">
                        <span role="columnheader">งาน / Project</span>
                        <span role="columnheader">พนักงาน</span>
                        <span role="columnheader">ผู้รับผิดชอบงาน</span>
                        <span role="columnheader">สถานะ</span>
                        <span role="columnheader">ดูงาน</span>
                    </div>
                    @foreach($rows as $row)
                        @php
                            $task = $row['task'];
                            $member = $row['member'];
                            $status = WorkBoardDesign::status($task);
                            $boardQuery = ['view' => 'board', 'status' => 'cross_department', 'open_task' => $task->job_id];
                            $boardUrl = $isAdminViewer
                                ? route('admin.work-board.member', [$member->department_id, $member, ...$boardQuery])
                                : route('work-board.member', [$member->department_id, $member, 'workspace' => 1, ...$boardQuery]);
                        @endphp
                        <article class="admin-approvals-request" role="row">
                            <div class="admin-approvals-request__cell admin-approvals-request__topic" role="cell" data-label="งาน / Project">
                                <strong>{{ $task->job_topic }}</strong>
                                @if($task->parent)
                                    <span>งานย่อยของ {{ $task->parent->job_topic }}</span>
                                @endif
                                <span>{{ $task->taskList?->name ?? 'งานทั่วไป' }}</span>
                            </div>
                            <div class="admin-approvals-request__cell" role="cell" data-label="พนักงาน">
                                <strong>{{ $member->name }}</strong>
                                <span>{{ $member->department?->department_name ?? 'ไม่ระบุแผนก' }}</span>
                            </div>
                            <div class="admin-approvals-request__cell" role="cell" data-label="ผู้รับผิดชอบงาน">
                                <strong>{{ $task->user?->name ?? '-' }}</strong>
                                <span>{{ $task->user?->department?->department_name ?? 'ไม่ระบุแผนก' }}</span>
                            </div>
                            <div class="admin-approvals-request__cell" role="cell" data-label="สถานะ">
                                <span class="admin-approvals-status is-{{ $status['tone'] }}"><i class="bi {{ $status['icon'] }}" aria-hidden="true"></i>{{ $status['label'] }}</span>
                            </div>
                            <div class="admin-approvals-request__cell admin-approvals-request__actions" role="cell" data-label="ดูงาน">
                                <a class="btn btn-sm btn-outline-primary" href="{{ $boardUrl }}">
                                    <i class="bi bi-kanban" aria-hidden="true"></i> ดูในบอร์ด
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</section>
