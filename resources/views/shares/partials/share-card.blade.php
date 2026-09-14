{{--
    การ์ดงานที่ถูกแชร์หนึ่งใบ

    สิ่งที่แชร์ได้คือ "งานย่อย" หนึ่งรายการ ไม่ใช่งานทั้งใบ (WorkOrderPolicy::share())
    การ์ดจึงเรียงจากบริบทกว้างไปแคบ: ใครชวน → โปรเจกต์ → งานแม่ → งานย่อยที่จะเข้าไปทำ
    เพราะชื่องานย่อยอย่างเดียว (เช่น "เดินสาย") ไม่พอให้ตัดสินใจว่าจะกดร่วมหรือไม่

    ผู้แชร์ขึ้นก่อนเพราะคำถามแรกของคนอ่านคือ "ใครชวน" ไม่ใช่ "งานชื่ออะไร" — คนที่ชวน
    เป็นตัวตัดสินว่าจะกดร่วมหรือไม่มากกว่าชื่องาน จึงแสดงเป็นรูปประจำตัวคู่ชื่อและแผนก
    ด้วยคอมโพเนนต์ avatar ตัวเดียวกับบอร์ดงาน ไม่ใช่วาดวงกลมชุดใหม่

    ข้อจำกัดสำคัญ: ผู้ที่ยังไม่เข้าร่วมเปิดงานในบอร์ดไม่ได้ (WorkOrderPolicy::view()
    ปฏิเสธ) การ์ดจึงแสดงได้เฉพาะข้อมูลที่ประกาศเปิดเผยเท่านั้น ห้ามลิงก์ไฟล์แนบหรือ
    คอมเมนต์ ลิงก์เข้าหน้างานจริงจะโผล่หลังเข้าร่วมสำเร็จแล้ว
--}}
@php
    $task = $share->workOrder;
    $viewerRequest = $share->viewer_request ?? null;
    $dueLabel = $task?->job_due_at
        ? \App\Support\TodayWorkspace::businessMoment($task->job_due_at)->format('j M Y')
        : 'ไม่มีกำหนด';
    $parent = $task?->parent;
@endphp
<article class="shares-card" data-share-card="{{ $share->id }}">
    <header class="shares-card__sharer">
        @if($share->sharer)
            @include('work-board.partials.avatar', ['user' => $share->sharer, 'size' => 'md'])
        @endif
        <div class="shares-card__sharer-text">
            <strong>{{ $share->sharer?->name ?? 'ไม่ทราบผู้แชร์' }}</strong>
            <small>
                {{ $share->sharer?->department?->department_name ?? 'ไม่ระบุแผนก' }}
                · แชร์งานนี้ให้ร่วมทำ
            </small>
        </div>
        <span class="shares-chip {{ $share->scope === 'department' ? 'shares-chip--dept' : 'shares-chip--org' }}">
            {{ $share->scope === 'department' ? 'เฉพาะแผนก'.($share->department ? ' '.$share->department->department_name : '') : 'ข้ามแผนก' }}
        </span>
    </header>

    <div class="shares-card__body">
        <span class="shares-card__project">
            <i class="bi bi-folder2" aria-hidden="true"></i>
            {{ $task?->taskList?->name ?? 'ไม่มีโปรเจกต์' }}
        </span>

        {{-- งานแม่เป็นบริบท ไม่ใช่หัวข้อของการ์ด สิ่งที่ถูกแชร์คือรายการงานย่อยด้านล่าง --}}
        @if($parent)
            <span class="shares-card__parent">
                <i class="bi bi-diagram-3" aria-hidden="true"></i>
                {{ $parent->job_topic }}
            </span>
        @endif

        <h3 class="shares-card__title">{{ $task?->job_topic ?? 'งานถูกลบแล้ว' }}</h3>

        @if($share->note)
            <p class="shares-card__note">“{{ $share->note }}”</p>
        @endif

        <p class="shares-card__due">
            <i class="bi bi-calendar-event" aria-hidden="true"></i>
            กำหนดส่ง {{ $dueLabel }}
        </p>
    </div>

    <p class="shares-card__joining">
        <i class="bi bi-person-workspace" aria-hidden="true"></i>
        เข้าร่วมเพื่อทำงานย่อยรายการนี้
    </p>

    <div class="shares-card__actions">
        <button type="button" class="shares-btn shares-btn--ghost"
            data-share-detail
            data-topic="{{ $task?->job_topic }}"
            data-project="{{ $task?->taskList?->name ?? 'ไม่มีโปรเจกต์' }}"
            data-sharer="{{ $share->sharer?->name }}"
            data-owner="{{ $task?->user?->name ?? '-' }}"
            data-due="{{ $dueLabel }}"
            data-parent="{{ $parent?->job_topic }}"
            data-details="{{ $task?->job_details }}">
            <i class="bi bi-card-text" aria-hidden="true"></i> รายละเอียดงาน
        </button>

        @if($viewerRequest)
            <span class="shares-chip shares-chip--muted">
                {{ $viewerRequest->status === 'pending' ? 'ส่งคำขอแล้ว รอพิจารณา' : 'พิจารณาแล้ว' }}
            </span>
        @elseif(auth()->user()->can('requestJoin', $share))
            <form method="POST" action="{{ route('shares.requests.store', $share) }}" data-share-join>
                @csrf
                <button type="submit" class="shares-btn shares-btn--primary">
                    <i class="bi bi-person-plus" aria-hidden="true"></i> ร่วมงาน
                </button>
            </form>
        @endif
    </div>
</article>
