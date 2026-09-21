@php
    $compact = $compact ?? false;
    $singleDate = $template->starts_on && $template->ends_on
        && $template->starts_on->isSameDay($template->ends_on);
    $routineValues = [
        'title' => $template->title,
        'weekdays' => collect(range(0, 6))->filter(fn ($day) => ((int) $template->weekday_mask & (1 << $day)) !== 0)->values(),
        'plan_date' => $singleDate ? $template->starts_on->format('Y-m-d') : null,
        'start' => $template->default_start_time ? substr($template->default_start_time, 0, 5) : null,
        'end' => $template->default_start_time && $template->default_duration_minutes
            ? \Carbon\Carbon::createFromFormat('H:i:s', $template->normalizedStartTime())->addMinutes($template->default_duration_minutes)->format('H:i')
            : null,
        'category' => $template->work_log_category_id,
        'project' => $template->work_order_list_id,
        'task' => $template->job_id,
        'details' => $template->details,
        'participants' => $template->participants->pluck('id'),
    ];
@endphp

<div class="daily-plan-management__row{{ $compact ? ' daily-plan-management__row--day' : '' }}" data-routine-row
    data-routine-edit-url="{{ route('daily-logs.routines.update', $template) }}"
    data-routine-values="{{ json_encode($routineValues) }}">
    <span class="daily-plan-management__body">
        @if($compact)
            <strong data-routine-title class="visually-hidden">{{ $template->title }}</strong>
            <span class="daily-plan-management__date">
                <i class="bi bi-calendar-event" aria-hidden="true"></i>
                {{ $template->starts_on->locale('th')->translatedFormat('j M') }} {{ $template->starts_on->year + 543 }}
            </span>
        @else
            <strong data-routine-title>{{ $template->title }}</strong>
            <small>
                @if($singleDate)
                    {{ $template->starts_on->locale('th')->translatedFormat('j M') }} {{ $template->starts_on->year + 543 }}
                @else
                    {{ \App\Support\WorkLogWeekdays::label((int) $template->weekday_mask) }}
                @endif
                @if($template->category) · {{ $template->category->name }} @endif
                @if($template->plannedWindowLabel()) · {{ $template->plannedWindowLabel() }} @endif
                @unless($template->is_active) · หยุดสร้างรายการใหม่ @endunless
            </small>
        @endif
    </span>
    <div class="daily-plan-management__actions">
        <button type="button" data-routine-edit data-open-entry-modal="routine" aria-label="แก้ไขแผน {{ $template->title }}">แก้ไข</button>
        <form method="POST" action="{{ route('daily-logs.routines.destroy', $template) }}" data-routine-delete>
            @csrf
            @method('DELETE')
            <button type="submit" aria-label="ลบแผน {{ $template->title }}">ลบ</button>
        </form>
    </div>
</div>
