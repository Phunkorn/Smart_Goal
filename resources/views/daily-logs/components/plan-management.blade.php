@if($isOwnDay && ($routineTemplates->isNotEmpty() || $sharedRoutines->isNotEmpty()))
<section class="daily-plan-management" aria-labelledby="dailyPlanManagementTitle">
    <div class="daily-plan-management__head">
        <h2 id="dailyPlanManagementTitle">แผนงานประจำของฉัน</h2>
        <small>แก้ไขแผนได้เฉพาะเจ้าของ รายการที่บันทึกแล้วจะยังอยู่</small>
    </div>
    @foreach($routineTemplates as $template)
        <article class="daily-plan-management__row" data-routine-row
            data-routine-edit-url="{{ route('daily-logs.routines.update', $template) }}"
            data-routine-values="{{ json_encode([
                'title' => $template->title,
                'weekdays' => collect(range(0, 6))->filter(fn ($day) => ((int) $template->weekday_mask & (1 << $day)) !== 0)->values(),
                'plan_date' => $template->starts_on && $template->ends_on && $template->starts_on->isSameDay($template->ends_on) ? $template->starts_on->format('Y-m-d') : null,
                'start' => $template->default_start_time ? substr($template->default_start_time, 0, 5) : null,
                'end' => $template->default_start_time && $template->default_duration_minutes ? \Carbon\Carbon::createFromFormat('H:i:s', $template->normalizedStartTime())->addMinutes($template->default_duration_minutes)->format('H:i') : null,
                'category' => $template->work_log_category_id,
                'project' => $template->work_order_list_id,
                'task' => $template->job_id,
                'details' => $template->details,
                'participants' => $template->participants->pluck('id'),
            ]) }}">
            <span class="daily-plan-management__body">
                <strong data-routine-title>{{ $template->title }}</strong>
                <small>
                    @if($template->starts_on && $template->ends_on && $template->starts_on->isSameDay($template->ends_on))
                        {{ $template->starts_on->locale('th')->translatedFormat('j M') }} {{ $template->starts_on->year + 543 }}
                    @else
                        {{ \App\Support\WorkLogWeekdays::label((int) $template->weekday_mask) }}
                    @endif
                    @if($template->category) · {{ $template->category->name }} @endif
                    @if($template->plannedWindowLabel()) · {{ $template->plannedWindowLabel() }} @endif
                    @unless($template->is_active) · หยุดสร้างรายการใหม่ @endunless
                </small>
            </span>
            <div class="daily-plan-management__actions">
                <button type="button" data-routine-edit data-open-entry-modal="routine" aria-label="แก้ไขแผน {{ $template->title }}">แก้ไข</button>
                <form method="POST" action="{{ route('daily-logs.routines.destroy', $template) }}" data-routine-delete>
                    @csrf
                    @method('DELETE')
                    <button type="submit" aria-label="ลบแผน {{ $template->title }}">ลบ</button>
                </form>
            </div>
        </article>
    @endforeach
    @if($sharedRoutines->isNotEmpty())
        <h3>แผนที่ร่วมกับผู้อื่น</h3>
        @foreach($sharedRoutines as $template)
            <div class="daily-plan-management__row daily-plan-management__row--shared">
                <span class="daily-plan-management__body">
                    <strong>{{ $template->title }}</strong>
                    <small>จาก {{ $template->user?->name }} · {{ \App\Support\WorkLogWeekdays::label((int) $template->weekday_mask) }}</small>
                </span>
            </div>
        @endforeach
    @endif
</section>
@endif
