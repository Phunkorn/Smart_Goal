@php
    /*
     * งานย่อยหนึ่งแถว
     *
     * งานย่อยเป็น WorkOrder จริง แถวนี้จึงใช้ปุ่มควบคุมชุดเดียวกับแถวงานแม่ทุกตัว
     * (เมนูสถานะ เมนูความสำคัญ ช่องวันที่ ไฟล์แนบ คอมเมนต์) และมี data-board-task
     * ของตัวเอง ตัวจัดการเหตุการณ์ใน mytasks-project-board.js จึงยิงไปที่งานย่อยใบนั้น
     * ไม่ใช่งานแม่ ส่วน data-board-subtask มีไว้ให้โค้ดที่ "ไล่รายการงาน" ข้ามแถวนี้ไป
     */
    $detailIsLate = (int) $detail->job_status !== 4 && $detail->job_due_at?->isPast();
    $detailIsSoon = ! $detailIsLate && (int) $detail->job_status !== 4 && $detail->job_due_at && now()->diffInDays($detail->job_due_at, false) <= 3;
    $detailStatus = [2=>['กำลังทำ','progress'],3=>['รอตรวจสอบ','review'],4=>['เสร็จแล้ว','done'],5=>['พักงาน','paused'],6=>['ล่าช้า','late']][(int) $detail->job_status] ?? ['สถานะไม่รองรับ','unsupported'];
    $detailPriority = [1=>['routine','routine'],2=>['สำคัญไม่ด่วน','important'],3=>['สำคัญด่วน','urgent'],4=>['ด่วนไม่ค่อยสำคัญ','quick'],5=>['ไม่รีบ ไม่มีกำหนด','flexible']][(int) $detail->job_priority] ?? ['สำคัญไม่ด่วน','important'];
    $detailAccepted = $detail->collaborators->filter(fn ($person) => $person->pivot?->status === 'accepted')->values();
    $detailPending = $detail->collaborators->filter(fn ($person) => $person->pivot?->status !== 'accepted')->values();
    $detailTeam = $detailAccepted->concat($detailPending);
    $detailFiles = (int) ($detail->images_count ?? $detail->images->count());
    $detailComments = $detail->updates->where('is_comment', true)->count();
    $detailCommentLabel = $detailComments ? 'ดูคอมเมนต์ '.$detailComments.' รายการ' : 'ยังไม่มีคอมเมนต์';
    $detailAssigneeName = $detail->user?->name ?? auth()->user()->name;
    $detailCanWork = auth()->user()->can('work', $detail);
    $detailCanManageTeam = auth()->user()->can('manageTeam', $detail);
    $detailCanEditSchedule = $detailCanWork && ((int) $detail->job_status !== 4 || auth()->user()->role === 'admin');
    $detailShowsReviewStage = \App\Support\TaskReviewStage::appliesTo($detail, auth()->user());
    $detailThaiMonths = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
    // รูปแบบวันที่ต้องเหมือนแถวงานแม่ทุกตัวอักษร คอลัมน์นี้จัดกึ่งกลาง
    // ข้อความที่สั้นกว่าจะถูกวางคนละตำแหน่งจนดูเหมือนคอลัมน์ไม่ตรงกัน
    // อ่านจากเวลาไทยเสมอ ค่าที่เก็บเป็น UTC ข้ามวันได้เมื่อกำหนดการมีเวลาจริง
    $detailDateLabel = function ($value) use ($detailThaiMonths) {
        if (! $value) {
            return null;
        }

        $moment = \App\Support\TodayWorkspace::businessMoment($value);

        return $moment->day.' '.$detailThaiMonths[$moment->month].' '.($moment->year + 543);
    };
    $detailStartLabel = $detailDateLabel($detail->job_start_at) ?? '-';
    $detailDueLabel = $detailDateLabel($detail->job_due_at) ?? 'ไม่มีกำหนด';
    $detailDueTimeLabel = \App\Support\TodayWorkspace::timeLabel($detail->job_due_at) ?? '-';
@endphp

<li class="board-task-detail task-priority-{{ $detailPriority[1] }} {{ $detailIsLate ? 'is-late' : '' }}"
    data-task-detail
    data-board-task
    data-board-subtask="1"
    data-detail-target="0"
    data-detail-id="{{ $detail->job_id }}"
    data-task-id="{{ $detail->job_id }}"
    data-work-order-id="{{ $task->job_id }}"
    data-project-key="{{ $projectKey ?? '' }}"
    data-project-name="{{ $detail->taskList?->name ?? 'งานทั่วไป' }}"
    data-topic="{{ $detail->job_topic }}"
    data-status="{{ $detail->job_status }}"
    data-priority="{{ $detail->job_priority }}"
    data-late="{{ $detailIsLate ? 1 : 0 }}"
    data-due-time="{{ \App\Support\TodayWorkspace::clockTime($detail->job_due_at) }}"
    data-start="{{ \App\Support\TodayWorkspace::calendarDate($detail->job_start_at) }}"
    data-due="{{ \App\Support\TodayWorkspace::calendarDate($detail->job_due_at) }}"
    data-update-url="{{ route('mytasks.details.update', $detail) }}"
    data-delete-url="{{ route('mytasks.details.destroy', $detail) }}"
    data-move-url="{{ route('mytasks.details.move', $detail) }}"
    @if($canManageTaskDetails) draggable="true" @endif>
    <span class="board-task-detail__name">
        @if($canManageTaskDetails)
            <button type="button" class="board-task-detail__drag" data-task-detail-drag aria-label="ลากเพื่อย้ายงานย่อย {{ $detail->job_topic }}" title="ลากไปวางที่งานหรือโปรเจกต์อื่น"><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>
        @else
            <i class="board-task-detail__bullet bi bi-dash" aria-hidden="true"></i>
        @endif
        {{-- ชื่องานย่อยเปิดโมดัลรายละเอียดงานใบเดียวกับงานปกติ ไม่มีหน้าจอแก้ไขคู่ขนาน --}}
        <button type="button" class="board-task-detail__title" data-open-task-modal data-task-id="{{ $detail->job_id }}" title="เปิดรายละเอียดงานย่อย {{ $detail->job_topic }}">
            <span class="board-reference-task__title" data-task-detail-title>{{ $detail->job_topic }}</span>
        </button>
    </span>

    @if($detailCanWork)
        <details class="board-status-menu" data-board-status-menu>
            <summary class="board-status-pill status-{{ $detailIsLate ? 'late' : $detailStatus[1] }}"><span data-board-status-label>{{ $detailIsLate ? 'ล่าช้า' : $detailStatus[0] }}</span><i class="bi bi-chevron-down"></i></summary>
            <div>
                @foreach([5=>['พักงาน','paused'],2=>['กำลังทำ','progress'],3=>['รอตรวจสอบ','review'],4=>['เสร็จแล้ว','done']] as $value=>$meta)
                    @continue($value === 3 && ! $detailShowsReviewStage)
                    <button type="button" class="status-{{ $meta[1] }}" data-board-status-value="{{ $value }}">{{ $meta[0] }}@if((int)$detail->job_status === $value)<span class="bi bi-check2"></span>@endif</button>
                @endforeach
            </div>
        </details>
        <details class="board-status-menu board-priority-menu" data-board-priority-menu>
            <summary class="board-priority priority-{{ $detailPriority[1] }}"><span data-board-priority-label>{{ $detailPriority[0] }}</span><i class="bi bi-chevron-down"></i></summary>
            <div>@foreach([3=>['สำคัญด่วน','urgent'],4=>['ด่วนไม่ค่อยสำคัญ','quick'],2=>['สำคัญไม่ด่วน','important'],5=>['ไม่รีบ ไม่มีกำหนด','flexible'],1=>['routine','routine']] as $value=>$meta)<button type="button" class="priority-{{ $meta[1] }}" data-board-priority-value="{{ $value }}"><i class="bi bi-flag-fill"></i>{{ $meta[0] }}@if((int)$detail->job_priority === $value)<span class="bi bi-check2"></span>@endif</button>@endforeach</div>
        </details>
    @else
        <span class="board-status-pill status-{{ $detailIsLate ? 'late' : $detailStatus[1] }}">{{ $detailIsLate ? 'ล่าช้า' : $detailStatus[0] }}</span>
        <span class="board-priority priority-{{ $detailPriority[1] }}"><i class="bi bi-flag-fill" aria-hidden="true"></i>{{ $detailPriority[0] }}</span>
    @endif

    @if($detailCanEditSchedule)
        <label class="board-start board-start-editable"><i class="bi bi-calendar-plus"></i><span data-board-start-label>{{ $detailStartLabel }}</span><input type="datetime-local" data-date-picker data-default-time="{{ \App\Support\TodayWorkspace::DEFAULT_START_TIME }}" data-board-field="start" value="{{ \App\Support\TodayWorkspace::calendarDateTime($detail->job_start_at) }}" data-range-partner="due" aria-label="เลือกวันที่และเวลาเริ่มของงานย่อย {{ $detail->job_topic }}"></label>
        <label class="board-due board-due-editable {{ $detailIsLate ? 'is-late' : ($detailIsSoon ? 'is-soon' : '') }}"><i class="bi {{ $detailIsLate ? 'bi-exclamation-triangle' : 'bi-calendar3' }}"></i><span data-board-due-label>{{ $detailDueLabel }}</span><input type="datetime-local" data-date-picker data-default-time="{{ \App\Support\TodayWorkspace::DEFAULT_DUE_TIME }}" data-board-field="due" value="{{ \App\Support\TodayWorkspace::calendarDateTime($detail->job_due_at) }}" data-range-partner="start" aria-label="เลือกวันที่และเวลากำหนดส่งของงานย่อย {{ $detail->job_topic }}"></label>
    @else
        <span class="board-start"><i class="bi bi-calendar-plus"></i>{{ $detailStartLabel }}</span>
        <span class="board-due {{ $detailIsLate ? 'is-late' : ($detailIsSoon ? 'is-soon' : '') }}"><i class="bi {{ $detailIsLate ? 'bi-exclamation-triangle' : 'bi-calendar3' }}"></i>{{ $detailDueLabel }}</span>
    @endif

    {{-- คอลัมน์เวลากำหนดส่ง กดแก้ได้เหมือนแถวงานแม่ และผูกกับค่าเดียวกันคือ job_due_at --}}
    @if($detailCanEditSchedule)
        <label class="board-due-time board-due-time-editable {{ $detailIsLate ? 'is-late' : ($detailIsSoon ? 'is-soon' : '') }}"><i class="bi bi-clock" aria-hidden="true"></i><span data-board-due-time>{{ $detailDueTimeLabel }}</span><input type="datetime-local" data-date-picker data-default-time="{{ \App\Support\TodayWorkspace::DEFAULT_DUE_TIME }}" data-board-field="due" value="{{ \App\Support\TodayWorkspace::calendarDateTime($detail->job_due_at) }}" data-range-partner="start" aria-label="เลือกเวลากำหนดส่งของงานย่อย {{ $detail->job_topic }}"></label>
    @else
        <span class="board-due-time {{ $detailIsLate ? 'is-late' : ($detailIsSoon ? 'is-soon' : '') }}"><i class="bi bi-clock" aria-hidden="true"></i><span data-board-due-time>{{ $detailDueTimeLabel }}</span></span>
    @endif

    <button type="button" class="board-owner" data-open-owner="{{ $detail->job_id }}" title="ผู้รับผิดชอบ: {{ $detailAssigneeName }}" aria-label="ดูข้อมูลผู้รับผิดชอบ {{ $detailAssigneeName }}"><i>@include('components.user-avatar-content', ['user' => $detail->user ?? auth()->user()])</i></button>

    <span class="board-collaborators"><button type="button" data-manage-team="{{ $detail->job_id }}" aria-label="{{ $detailCanManageTeam ? 'จัดการ' : 'ดู' }}ผู้ร่วมงานของงานย่อย {{ $detail->job_topic }}">@foreach($detailTeam->take(2) as $person)<i class="{{ $person->pivot?->status === 'accepted' ? '' : 'is-pending' }}" title="{{ $person->name }}">@include('components.user-avatar-content', ['user' => $person])</i>@endforeach @if($detailTeam->count() > 2)<b>+{{ $detailTeam->count() - 2 }}</b>@endif<span class="board-team-add" title="{{ $detailCanManageTeam ? 'เพิ่มผู้ร่วมงาน' : 'ดูผู้ร่วมงาน' }}"><i class="bi {{ $detailCanManageTeam ? 'bi-person-plus-fill' : 'bi-people-fill' }}"></i></span></button></span>

    <button type="button" class="board-attachments {{ $detailFiles ? 'has-files' : '' }}" data-board-open-attachments="{{ $detail->job_id }}" title="{{ $detailFiles ? 'ดูไฟล์แนบ '.$detailFiles.' ไฟล์' : 'แนบไฟล์ให้งานย่อยนี้' }}"><i class="bi bi-paperclip"></i><strong>{{ $detailFiles ?: '-' }}</strong></button>

    <button type="button" class="board-comments{{ $detailComments ? ' has-comments' : '' }}" data-open-task-modal data-task-id="{{ $detail->job_id }}" data-task-tab="updates" data-unread-comments="{{ $detail->job_id }}" data-unread-persistent data-comment-label="{{ $detailCommentLabel }}" title="{{ $detailCommentLabel }}" aria-label="{{ $detailCommentLabel }} ของงานย่อย {{ $detail->job_topic }}"><i class="bi bi-chat-left-text" aria-hidden="true"></i><strong>{{ $detailComments ?: '-' }}</strong></button>

    @if($canManageTaskDetails)
        <details class="task-more-menu board-reference-menu board-task-detail__menu">
            <summary aria-label="เมนูจัดการงานย่อย {{ $detail->job_topic }}"><i class="bi bi-three-dots-vertical"></i></summary>
            <div class="board-task-menu">
                <button type="button" data-task-detail-move><i class="bi bi-arrow-left-right"></i><span><strong>ย้ายไปงานอื่น</strong><small>ย้ายงานย่อยนี้ไปอยู่ใต้งานอื่น</small></span></button>
                <button type="button" data-task-detail-edit><i class="bi bi-pencil-square"></i><span><strong>แก้ไขชื่องานย่อย</strong><small>เปลี่ยนเฉพาะชื่อ ฟิลด์อื่นแก้ในหน้ารายละเอียดงาน</small></span></button>
                <button type="button" class="danger" data-task-detail-delete><i class="bi bi-trash3"></i><span><strong>ลบงานย่อย</strong><small>นำงานย่อยนี้ไปไว้ในถังขยะ</small></span></button>
            </div>
        </details>
    @else
        <span aria-hidden="true"></span>
    @endif
</li>
