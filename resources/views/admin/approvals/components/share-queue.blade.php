{{--
    คำขอร่วมงานที่มาจากการแชร์งาน — ชั้นที่สอง

    ต่างจากคิว "ผู้ร่วมงานข้ามแผนก" ด้านบนตรงทิศทางของคำขอ: ที่นั่นคือเจ้าของงานไป
    ขอยืมตัวคนของแผนกอื่น ผู้อนุมัติจึงเป็นหัวหน้าแผนกของคนที่ถูกยืม ส่วนที่นี่คือคนนอก
    มาขอเข้างานของแผนกเราเอง ผู้อนุมัติจึงเป็นหัวหน้าแผนกของ "งาน"

    คำขอจะมาถึงที่นี่เฉพาะเมื่อผู้แชร์เป็นพนักงานธรรมดา ถ้าหัวหน้าแผนกเป็นผู้แชร์เอง
    คำขอจะจบตั้งแต่ที่เขากดอนุมัติ เพราะเขาคือผู้มีอำนาจสูงสุดของงานนั้นอยู่แล้ว

    ใช้โครง markup ชุดเดียวกับคิวอื่นในหน้านี้ ปุ่มเป็นฟอร์ม POST จริงจึงทำงานได้แม้
    JavaScript ไม่ทำงาน
--}}
<section class="admin-approvals-queue" id="share-approval-queue" aria-labelledby="shareApprovalHeading">
    <div class="admin-approvals-queue__heading">
        <div>
            <span>SHARED TASK APPROVAL</span>
            <h2 id="shareApprovalHeading">ผู้ขอร่วมงานจากการแชร์งาน</h2>
        </div>
        <span class="badge rounded-pill text-bg-warning">{{ $approvalCounts['shares'] }} รายการ</span>
    </div>

    @if($approvalCounts['shares'] === 0)
        <div class="admin-approvals-empty">
            <i class="bi bi-check-circle" aria-hidden="true"></i>
            <p>ไม่มีคำขอร่วมงานจากการแชร์งานที่รอการตัดสินใจ</p>
        </div>
    @else
        <div class="admin-approvals-list" role="table" aria-label="คำขอร่วมงานจากการแชร์งานรออนุมัติ">
            <div class="admin-approvals-list__header" role="row">
                <span role="columnheader">งาน / Project</span>
                <span role="columnheader">ผู้แชร์</span>
                <span role="columnheader">ผู้ขอเข้าร่วม</span>
                <span role="columnheader">สถานะ</span>
                <span role="columnheader">จัดการ</span>
            </div>
            @foreach($shareJoinRequests as $shareRequest)
                @php
                    $task = $shareRequest->share?->workOrder;
                    $sharer = $shareRequest->share?->sharer;
                    $requester = $shareRequest->requester;
                @endphp
                <article class="admin-approvals-request" role="row">
                    <div class="admin-approvals-request__cell admin-approvals-request__topic" role="cell" data-label="งาน / Project">
                        <strong>{{ $task?->job_topic ?? 'งานถูกลบแล้ว' }}</strong>
                        <span>{{ $task?->taskList?->name ?? 'งานทั่วไป' }}</span>
                    </div>
                    <div class="admin-approvals-request__cell" role="cell" data-label="ผู้แชร์">
                        <strong>{{ $sharer?->name ?? '-' }}</strong>
                        <span>{{ $sharer?->department?->department_name ?? 'ไม่ระบุแผนก' }}</span>
                    </div>
                    <div class="admin-approvals-request__cell" role="cell" data-label="ผู้ขอเข้าร่วม">
                        <strong>{{ $requester?->name ?? '-' }}</strong>
                        <span>{{ $requester?->department?->department_name ?? 'ไม่ระบุแผนก' }}</span>
                    </div>
                    <div class="admin-approvals-request__cell" role="cell" data-label="สถานะ">
                        <span class="badge rounded-pill text-bg-warning">รอหัวหน้าแผนกตัดสิน</span>
                    </div>
                    <div class="admin-approvals-request__cell admin-approvals-request__actions" role="cell" data-label="จัดการ">
                        <form method="POST" action="{{ route('shares.requests.approve', $shareRequest) }}"
                            data-approval-form data-approval-kind="share" data-decision="accepted"
                            data-topic="{{ $task?->job_topic }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-sm btn-success">
                                <i class="bi bi-check-lg" aria-hidden="true"></i> อนุมัติ
                            </button>
                        </form>
                        <form method="POST" action="{{ route('shares.requests.reject', $shareRequest) }}"
                            data-approval-form data-approval-kind="share" data-decision="rejected"
                            data-topic="{{ $task?->job_topic }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-x-lg" aria-hidden="true"></i> ปฏิเสธ
                            </button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
