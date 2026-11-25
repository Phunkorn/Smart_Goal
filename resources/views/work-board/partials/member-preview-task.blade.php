@php
    use App\Support\TodayWorkspace;
    use App\Support\WorkBoardDesign;

    $itemMarker = match ($mode) {
        'created' => 'data-preview-created-task',
        'all' => 'data-preview-all-task',
        default => 'data-preview-task',
    };
    $linkMarker = $itemMarker.'-link';
    $status = WorkBoardDesign::status($task);
    $priority = WorkBoardDesign::taskPriority((int) $task->job_priority);
    $projectName = $task->taskList?->name ?? 'งานทั่วไป';

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
    $timingLabel = match ($bucket) {
        'late' => 'เลยกำหนดแล้ว',
        'due_today' => 'กำหนดส่งวันนี้',
        'starts_today' => 'เริ่มวันนี้',
        'created', 'not_started' => $startLabel,
        'done' => $task->job_completed_at
            ? 'ปิดงาน '.TodayWorkspace::dateRangeLabel($task->job_completed_at, $task->job_completed_at)
            : 'ปิดงานแล้ว',
        default => $dueLabel,
    };
    $timingDate = match (true) {
        in_array($bucket, ['created', 'not_started', 'starts_today'], true) => $task->job_start_at,
        $bucket === 'done' => $task->job_completed_at,
        default => $task->job_due_at,
    };
    $timingElement = $timingDate ? 'time' : 'span';
@endphp

@if($canOpenWorkspace)
    <a class="wb-spotlight-row"
        href="{{ route($workspaceRoute, [...$workspaceParameters, 'open_task' => $task->job_id]) }}"
        {{ $itemMarker }} {{ $linkMarker }}>
@else
    <article class="wb-spotlight-row" {{ $itemMarker }}>
@endif
    <i class="bi {{ $status['icon'] }} wb-spotlight-row__icon" aria-hidden="true"></i>
    <div class="wb-spotlight-row__content">
        <h4 class="wb-spotlight-row__title">{{ $task->job_topic }}</h4>
        <div class="wb-spotlight-row__detail">
            <span>{{ $projectName }}</span>
            <span class="wb-spotlight-row__priority wb-tone-{{ $priority['tone'] }}" title="{{ $priority['label'] }}">
                <i class="bi bi-flag" aria-hidden="true"></i>
                <span class="visually-hidden">{{ $priority['label'] }}</span>
            </span>
        </div>
    </div>
    <div class="wb-spotlight-row__meta">
        @if($bucket !== 'late')
            <span class="wb-spotlight-row__status">{{ $status['label'] }}</span>
        @endif
        <{{ $timingElement }} class="{{ $bucket === 'late' ? 'wb-spotlight-row__late' : '' }}"
            @if($timingDate) datetime="{{ $timingDate->toIso8601String() }}" @endif
            data-preview-task-timing="{{ $bucket }}">
            @if($overdueDays > 0)
                <span class="visually-hidden">ล่าช้า </span><span aria-hidden="true">+</span>{{ $overdueDays }} วัน
            @else
                {{ $timingLabel }}
            @endif
        </{{ $timingElement }}>
    </div>
    @if($canOpenWorkspace)
        <i class="bi bi-chevron-right wb-spotlight-row__chevron" aria-hidden="true"></i>
    @endif
@if($canOpenWorkspace)
    </a>
@else
    </article>
@endif
