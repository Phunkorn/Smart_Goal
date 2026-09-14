{{--
    คำขอที่ฉันส่งไป

    สถานะที่สำคัญที่สุดคือ "ผู้แชร์อนุมัติแล้ว แต่ยังรอหัวหน้าแผนกของผู้แชร์" เพราะถ้าไม่
    พูดให้ชัด ผู้ขอจะเห็นคำว่าอนุมัติแล้วเปิดงานไม่เจอ แล้วคิดว่าระบบพัง
    (WorkOrderShareRequest::awaitsDepartmentHead())

    เมื่อสถานะเป็น approved แล้ว ผู้ขอเข้าร่วมงานสมบูรณ์เสมอ ไม่มีสถานะกึ่งกลางอีก
--}}
@php
    $share = $shareRequest->share;
    $task = $share?->workOrder;
    [$stateClass, $stateLabel] = match (true) {
        $shareRequest->status === 'pending' => ['is-pending', 'รอผู้แชร์พิจารณา'],
        $shareRequest->awaitsDepartmentHead() => ['is-waiting', 'ผู้แชร์อนุมัติแล้ว รอหัวหน้าแผนกของผู้แชร์'],
        $shareRequest->status === 'approved' => ['is-approved', 'เข้าร่วมงานแล้ว'],
        $shareRequest->status === 'rejected' => ['is-rejected', 'ถูกปฏิเสธ'],
        default => ['is-cancelled', 'ประกาศถูกปิดแล้ว'],
    };
@endphp
<article class="shares-request shares-request--mine {{ $stateClass }}">
    <div class="shares-request__body">
        <h3>{{ $task?->job_topic ?? 'งานถูกลบแล้ว' }}</h3>
        <p class="shares-request__meta">
            {{ $task?->taskList?->name ?? 'ไม่มีโปรเจกต์' }}
            · แชร์โดย {{ $share?->sharer?->name ?? '-' }}
            ({{ $share?->sharer?->department?->department_name ?? 'ไม่ระบุแผนก' }})
        </p>
        @if($shareRequest->decision_reason)
            <p class="shares-request__reason">เหตุผล: {{ $shareRequest->decision_reason }}</p>
        @endif
    </div>

    <div class="shares-request__actions">
        <span class="shares-chip shares-chip--state">{{ $stateLabel }}</span>
        {{-- ลิงก์เข้าหน้างานจริงโผล่เฉพาะเมื่อเข้าร่วมสมบูรณ์แล้วเท่านั้น
             ก่อนหน้านั้น WorkOrderPolicy::view() จะปฏิเสธและผู้ใช้จะเจอหน้า 403 --}}
        @if($shareRequest->status === 'approved' && $task)
            <a class="shares-btn shares-btn--ghost" href="{{ route('mytasks.index', ['open_task' => $task->job_id]) }}">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> เปิดงาน
            </a>
        @endif
    </div>
</article>
