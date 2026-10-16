@php
    use App\Support\ProjectRoles;
    use App\Support\TodayWorkspace;
    use App\Support\WorkBoardDesign;

    /*
     * งานหนึ่งใบในแผง Preview สมาชิก — ใช้ทั้งแท็บ "วันนี้" ($mode = 'today'),
     * "สร้างวันนี้" ('created') และ "งานทั้งหมด" ('all')
     *
     * ลำดับข้อมูลบนการ์ด (จากเด่นไปรอง):
     *   1. ชื่องาน + สถานะ (สีตาม WorkBoardDesign เดิม)
     *   2. โปรเจกต์ · บทบาทของสมาชิกในงานนี้ · ความสำคัญ
     *   3. หัวหน้าโปรเจกต์ · จำนวนผู้เข้าร่วม | วันที่สำคัญ
     *
     * หัวหน้าโปรเจกต์และผู้เข้าร่วมอ่านจาก ProjectRoles ที่เดียว relation ทั้งหมดถูก eager-load
     * มาแล้วใน DepartmentWorkBoardQuery::previewCandidates() จึงไม่มีคิวรีเพิ่มต่อการ์ด
     *
     * บทบาทแสดงกับทุกคนที่ "มีสิทธิ์ดูงานใบนั้น" (viewer_can_view = WorkOrderPolicy::view())
     * ไม่ผูกกับ role หรือ visibility scope ของผู้ดู คนที่เห็นการ์ดในฐานะภาพรวมงานวันนี้เท่านั้น
     * (เพื่อนร่วมแผนกที่ไม่ได้อยู่ในงาน) ไม่เห็นรายชื่อทีม เหมือนที่เปิดรายละเอียดงานไม่ได้
     *
     * แต่ละแท็บใช้ marker ของตัวเอง (data-preview-task / -created-task / -all-task และ *-link)
     * ตัวนับและเทสต์ของรายการวันนี้จึงไม่นับการ์ดของแท็บอื่นปนเข้ามา
     */
    $itemMarker = match ($mode) {
        'created' => 'data-preview-created-task',
        'all' => 'data-preview-all-task',
        default => 'data-preview-task',
    };
    $linkMarker = $itemMarker.'-link';
    $showProjectRoles = (bool) ($task->viewer_can_view ?? false);
    $status = WorkBoardDesign::status($task);
    $priority = WorkBoardDesign::taskPriority((int) $task->job_priority);
    $leader = $showProjectRoles ? ProjectRoles::leader($task) : null;
    $participants = $showProjectRoles ? ProjectRoles::participants($task) : collect();
    $memberIsLeader = ProjectRoles::isLeader($task, $member);
    $participantNames = $participants
        ->map(fn ($entry) => $entry['user']->name.($entry['status'] !== 'accepted' ? ' (รอตอบรับ)' : ''))
        ->join(', ');

    /*
     * ป้ายวันที่ — เลือกจากกลุ่มที่ DepartmentWorkBoardQuery ติดมากับงานแล้ว (today_bucket / all_bucket)
     * ทุกวันที่อ่านเป็นเวลาไทยผ่าน TodayWorkspace
     */
    $dueLabel = $task->job_due_at
        ? (TodayWorkspace::calendarDate($task->job_due_at) === TodayWorkspace::calendarDate(now())
            ? 'กำหนดส่งวันนี้'
            : 'กำหนดส่ง '.TodayWorkspace::dateRangeLabel($task->job_due_at, $task->job_due_at))
        : TodayWorkspace::NO_DUE_LABEL;
    $startLabel = $task->job_start_at
        ? 'เริ่ม '.TodayWorkspace::dateRangeLabel($task->job_start_at, $task->job_start_at)
        : 'ยังไม่กำหนดวันเริ่ม';
    $bucket = match ($mode) {
        'created' => 'created',
        'all' => $task->all_bucket ?? 'open',
        default => $task->today_bucket ?? 'active',
    };
    $overdueDays = $bucket === 'late' ? TodayWorkspace::overdueDays($task) : 0;
    [$timingIcon, $timingLabel] = match ($bucket) {
        'late' => ['bi-exclamation-circle', $overdueDays > 0 ? 'ล่าช้า '.$overdueDays.' วัน' : 'เลยกำหนดแล้ว'],
        'due_today' => ['bi-calendar-event', 'กำหนดส่งวันนี้'],
        'starts_today' => ['bi-calendar-event', 'เริ่มวันนี้'],
        'created', 'not_started' => ['bi-hourglass-split', $startLabel],
        'done' => ['bi-check2-circle', $task->job_completed_at
            ? 'ปิดงาน '.TodayWorkspace::dateRangeLabel($task->job_completed_at, $task->job_completed_at)
            : 'ปิดงานแล้ว'],
        default => ['bi-calendar-event', $dueLabel],
    };
    $timingDate = match (true) {
        in_array($bucket, ['created', 'not_started', 'starts_today'], true) => $task->job_start_at,
        $bucket === 'done' => $task->job_completed_at,
        default => $task->job_due_at,
    };
@endphp

@if($canOpenWorkspace)
    <a class="wb-preview-task wb-preview-task--link"
        href="{{ route($workspaceRoute, [...$workspaceParameters, 'open_task' => $task->job_id]) }}"
        {{ $itemMarker }}
        {{ $linkMarker }}
        aria-label="เปิดงาน {{ $task->job_topic }} ในพื้นที่งานของ {{ $member->name }}">
@else
    <article class="wb-preview-task" {{ $itemMarker }}>
@endif
    <div class="wb-preview-task__icon wb-tone-{{ $status['tone'] }}">
        <i class="bi {{ $mode === 'created' ? 'bi-calendar-plus' : 'bi-clipboard' }}" aria-hidden="true"></i>
    </div>
    <div class="wb-preview-task__content">
        <div class="wb-preview-task__title-row">
            <h5 title="{{ $task->job_topic }}">{{ $task->job_topic }}</h5>
            <span class="wb-status wb-tone-{{ $status['tone'] }}">{{ $status['label'] }}</span>
        </div>

        <div class="wb-preview-task__context">
            <span class="wb-preview-task__project" title="โปรเจกต์: {{ $task->taskList?->name ?? 'งานทั่วไป' }}">{{ $task->taskList?->name ?? 'งานทั่วไป' }}</span>
            @if($showProjectRoles)
                {{-- บทบาทของ "สมาชิกที่ถูกดู" ในงานใบนี้ ไม่ใช่ป้ายของโปรเจกต์ --}}
                <span class="wb-preview-task__member-role {{ $memberIsLeader ? 'is-leader' : 'is-participant' }}" data-preview-member-role="{{ $memberIsLeader ? ProjectRoles::LEADER : ProjectRoles::PARTICIPANT }}">
                    {{ $memberIsLeader ? 'หัวหน้าโปรเจกต์' : 'ผู้เข้าร่วม' }}
                </span>
            @endif
            <span class="wb-priority wb-tone-{{ $priority['tone'] }}">
                <i class="bi bi-flag-fill" aria-hidden="true"></i>{{ $priority['label'] }}
            </span>
        </div>

        <div class="wb-preview-task__footer">
            @if($showProjectRoles)
                <div class="wb-preview-task__people" data-preview-task-people>
                    <span class="wb-preview-person wb-preview-person--leader" data-preview-leader title="หัวหน้าโปรเจกต์: {{ $leader?->name ?? 'ไม่ระบุ' }}">
                        <span class="visually-hidden">หัวหน้าโปรเจกต์</span>
                        <i class="bi bi-star-fill wb-preview-person__mark" aria-hidden="true"></i>
                        @if($leader)
                            @include('work-board.partials.avatar', ['user' => $leader, 'size' => 'xs'])
                            <strong>{{ $leader->name }}</strong>
                        @else
                            <strong class="is-empty">ไม่ระบุหัวหน้าโปรเจกต์</strong>
                        @endif
                    </span>
                    <span class="wb-preview-person" data-preview-participants="{{ $participants->count() }}" title="ผู้เข้าร่วม: {{ $participantNames !== '' ? $participantNames : 'ยังไม่มี' }}">
                        <i class="bi bi-people" aria-hidden="true"></i>
                        {{ $participants->isEmpty() ? 'ยังไม่มีผู้เข้าร่วม' : 'ผู้เข้าร่วม '.$participants->count().' คน' }}
                    </span>
                </div>
            @endif
            <time class="wb-preview-task__timing {{ $bucket === 'late' ? 'is-late' : '' }}"
                datetime="{{ $timingDate?->toIso8601String() }}" data-preview-task-timing="{{ $bucket }}">
                <i class="bi {{ $timingIcon }}" aria-hidden="true"></i>{{ $timingLabel }}
            </time>
        </div>
    </div>
    @if($canOpenWorkspace)
        <i class="bi bi-chevron-right wb-preview-task__arrow" aria-hidden="true"></i>
    @endif
@if($canOpenWorkspace)
    </a>
@else
    </article>
@endif
