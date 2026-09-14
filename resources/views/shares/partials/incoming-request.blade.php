{{--
    คำขอเข้าร่วมที่รอฉันตัดสินในฐานะผู้แชร์

    ปุ่มอนุมัติ/ปฏิเสธเป็นฟอร์ม POST สองชุดแบบเดียวกับคิวคำขออนุมัติเดิม
    (admin/approvals/components/collaborator-queue.blade.php) เพื่อให้ทำงานได้
    แม้ JavaScript ไม่ทำงาน

    ต้องบอกให้ชัดว่าการกดอนุมัติครั้งนี้จบในตัวเองหรือไม่ หัวหน้าแผนกที่ดูแลแผนกของงาน
    กดแล้วจบเลย ส่วนพนักงานธรรมดาที่รับคนต่างแผนกเข้ามา คำขอจะถูกส่งต่อให้หัวหน้าแผนก
    ของตัวเองอีกขั้น
--}}
@php
    $requester = $shareRequest->requester;
    $share = $shareRequest->share;
    $task = $share?->workOrder;

    /*
     * "กดแล้วจบเลยไหม" ต้องถามจาก service ตัวเดียวกับที่ตัดสินจริง ไม่ใช่คำนวณซ้ำที่นี่
     * เคยเขียนข้อความตายตัวไว้แล้วมันโกหกผู้ใช้ — บอกว่าจะส่งต่อหัวหน้าแผนก ทั้งที่คนกด
     * เป็นหัวหน้าแผนกเสียเอง
     */
    $decidesAlone = $task && $requester
        && app(\App\Services\WorkOrderShareService::class)
            ->decidesAlone(auth()->user(), $task, $requester);
@endphp
<article class="shares-request">
    <div class="shares-request__body">
        <h3>{{ $requester?->name ?? 'ผู้ใช้ถูกลบแล้ว' }}</h3>
        <p class="shares-request__meta">
            {{ $requester?->department?->department_name ?? 'ไม่ระบุแผนก' }}
            · ขอเข้าร่วมงาน <strong>{{ $task?->job_topic ?? 'งานถูกลบแล้ว' }}</strong>
            ({{ $task?->taskList?->name ?? 'ไม่มีโปรเจกต์' }})
        </p>
        @unless($decidesAlone)
            <p class="shares-request__warning">
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                ผู้ขออยู่คนละแผนกกับงานนี้ — เมื่อคุณอนุมัติ คำขอจะถูกส่งต่อให้หัวหน้าแผนกของคุณพิจารณาอีกขั้น
            </p>
        @endunless
    </div>

    <div class="shares-request__actions">
        <form method="POST" action="{{ route('shares.requests.approve', $shareRequest) }}"
            data-share-decide data-decision="approve" data-final="{{ $decidesAlone ? 1 : 0 }}">
            @csrf
            @method('PATCH')
            <button type="submit" class="shares-btn shares-btn--primary">
                <i class="bi bi-check-lg" aria-hidden="true"></i> อนุมัติ
            </button>
        </form>
        <form method="POST" action="{{ route('shares.requests.reject', $shareRequest) }}" data-share-decide data-decision="reject">
            @csrf
            @method('PATCH')
            <button type="submit" class="shares-btn shares-btn--danger">
                <i class="bi bi-x-lg" aria-hidden="true"></i> ปฏิเสธ
            </button>
        </form>
    </div>
</article>
