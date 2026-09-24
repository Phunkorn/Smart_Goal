@php
    /* หลายวันที่เลือกครั้งเดียวถูกเก็บแยกรายวัน จึงรวมเฉพาะชั้นแสดงผล */
    $routineGroups = $routineTemplates->groupBy(function ($template) {
        $singleDate = $template->starts_on && $template->ends_on
            && $template->starts_on->isSameDay($template->ends_on);

        if (! $singleDate) return 'recurring:'.$template->id;

        return 'dated:'.sha1(json_encode([
            $template->title,
            $template->details,
            $template->kind,
            $template->work_log_category_id,
            $template->work_order_list_id,
            $template->job_id,
            $template->normalizedStartTime(),
            $template->default_duration_minutes,
            $template->is_active,
            $template->participants->pluck('id')->sort()->values()->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    })->map(fn ($templates) => $templates->sortBy('starts_on')->values());

    $sharedRoutineGroups = $sharedRoutines->groupBy(function ($template) {
        $singleDate = $template->starts_on && $template->ends_on
            && $template->starts_on->isSameDay($template->ends_on);

        if (! $singleDate) return 'recurring:'.$template->id;

        return 'dated:'.sha1(json_encode([
            $template->user_id,
            $template->title,
            $template->details,
            $template->kind,
            $template->work_log_category_id,
            $template->work_order_list_id,
            $template->job_id,
            $template->normalizedStartTime(),
            $template->default_duration_minutes,
            $template->is_active,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    })->map(fn ($templates) => $templates->sortBy('starts_on')->values());
@endphp

@if($isOwnDay && ($routineTemplates->isNotEmpty() || $sharedRoutines->isNotEmpty()))
<section class="daily-plan-management" aria-labelledby="dailyPlanManagementTitle">
    <div class="daily-plan-management__head">
        <h2 id="dailyPlanManagementTitle">แผนงานประจำของฉัน</h2>
        <small>แก้ไขแผนได้เฉพาะเจ้าของ รายการที่บันทึกแล้วจะยังอยู่</small>
    </div>

    @foreach($routineGroups as $templates)
        @php
            $dateCount = $templates->count();
            $firstTemplate = $templates->first();
        @endphp

        @if($dateCount === 1)
            @include('daily-logs.components.routine-management-item', ['template' => $firstTemplate])
        @else
            <article class="daily-plan-management__group">
                <div class="daily-plan-management__group-head">
                    <span class="daily-plan-management__body">
                        <span class="daily-plan-management__title-line">
                            <strong>{{ $firstTemplate->title }}</strong>
                            <span class="daily-plan-management__day-count">{{ $dateCount }} วัน</span>
                        </span>
                        <small>
                            เลือกไว้ {{ $dateCount }} วัน
                            @if($firstTemplate->category) · {{ $firstTemplate->category->name }} @endif
                            @if($firstTemplate->plannedWindowLabel()) · {{ $firstTemplate->plannedWindowLabel() }} @endif
                            @unless($firstTemplate->is_active) · หยุดสร้างรายการใหม่ @endunless
                        </small>
                    </span>
                </div>
                <details class="daily-plan-management__days">
                    <summary>
                        <span><i class="bi bi-calendar3" aria-hidden="true"></i> ดูวันที่และจัดการรายวัน</span>
                        <i class="bi bi-chevron-down daily-plan-management__chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="daily-plan-management__day-list">
                        @foreach($templates as $template)
                            @include('daily-logs.components.routine-management-item', [
                                'template' => $template,
                                'compact' => true,
                            ])
                        @endforeach
                    </div>
                </details>
            </article>
        @endif
    @endforeach

    @if($sharedRoutines->isNotEmpty())
        <h3>แผนที่ร่วมกับผู้อื่น</h3>
        @foreach($sharedRoutineGroups as $templates)
            @php
                $firstTemplate = $templates->first();
                $lastTemplate = $templates->last();
                $dateCount = $templates->count();
                $isDatedGroup = $firstTemplate->starts_on && $firstTemplate->ends_on
                    && $firstTemplate->starts_on->isSameDay($firstTemplate->ends_on);
                $firstDateLabel = $isDatedGroup
                    ? $firstTemplate->starts_on->locale('th')->translatedFormat('j M').' '.($firstTemplate->starts_on->year + 543)
                    : null;
                $lastDateLabel = $isDatedGroup
                    ? $lastTemplate->starts_on->locale('th')->translatedFormat('j M').' '.($lastTemplate->starts_on->year + 543)
                    : null;
            @endphp

            <article class="daily-plan-management__group daily-plan-management__group--shared">
                <div class="daily-plan-management__group-head">
                    <span class="daily-plan-management__body">
                        <span class="daily-plan-management__title-line">
                            <strong>{{ $firstTemplate->title }}</strong>
                            @if($dateCount > 1)
                                <span class="daily-plan-management__day-count">{{ $dateCount }} วัน</span>
                            @endif
                        </span>
                        <small>
                            จาก {{ $firstTemplate->user?->name }} ·
                            @if($isDatedGroup)
                                {{ $firstDateLabel }}@if($dateCount > 1) – {{ $lastDateLabel }}@endif
                            @else
                                {{ \App\Support\WorkLogWeekdays::label((int) $firstTemplate->weekday_mask) }}
                            @endif
                        </small>
                    </span>
                </div>

                @if($isDatedGroup && $dateCount > 1)
                    <details class="daily-plan-management__days">
                        <summary>
                            <span><i class="bi bi-calendar3" aria-hidden="true"></i> ดูรายละเอียดวันที่</span>
                            <i class="bi bi-chevron-down daily-plan-management__chevron" aria-hidden="true"></i>
                        </summary>
                        <div class="daily-plan-management__day-list">
                            @foreach($templates as $template)
                                <div class="daily-plan-management__shared-day">
                                    <i class="bi bi-calendar-event" aria-hidden="true"></i>
                                    <span>{{ $template->starts_on->locale('th')->translatedFormat('j M') }} {{ $template->starts_on->year + 543 }}</span>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </article>
        @endforeach
    @endif
</section>
@endif
