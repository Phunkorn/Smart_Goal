@php
    $statuses = [1 => ['ยังไม่เริ่ม', 'todo'], 2 => ['กำลังทำ', 'progress'], 3 => ['รอตรวจสอบ', 'review'], 4 => ['เสร็จแล้ว', 'done'], 5 => ['พักงาน', 'paused']];
    $priorities = [1 => 'routine', 2 => 'สำคัญไม่ด่วน', 3 => 'สำคัญด่วน', 4 => 'ด่วนไม่ค่อยสำคัญ', 5 => 'ไม่รีบ ไม่มีกำหนด'];
@endphp
<section class="mytasks-kanban" data-kanban>
    <header class="mytasks-kanban__toolbar"><label>โปรเจกต์ <select data-kanban-project aria-label="เลือกโปรเจกต์">@foreach($taskLists as $list)<option value="{{ $list->id }}">{{ $list->name }}</option>@endforeach</select></label><span data-kanban-project-count></span></header>
    @forelse($taskLists as $list)
        <div class="mytasks-kanban__project" data-kanban-panel="{{ $list->id }}" {{ $loop->first ? '' : 'hidden' }}>
            <div class="mytasks-kanban__columns">
                @foreach($statuses as $status => [$label, $tone])
                    <section class="mytasks-kanban__column status-{{ $tone }}" data-kanban-column="{{ $status }}">
                        <header><span><i></i>{{ $label }}</span><b data-kanban-count>0</b></header>
                        <div class="mytasks-kanban__cards">
                            @foreach($allTasks->where('work_order_list_id', $list->id)->where('job_status', $status) as $task)
                                @php($people = collect([$task->user])->filter()->concat($task->collaborators->where('pivot.status', 'accepted'))->unique('id'))
                                <article class="mytasks-kanban__card priority-{{ $task->job_priority }}" data-kanban-card data-id="{{ $task->job_id }}" data-status="{{ $status }}" data-priority="{{ $task->job_priority }}">
                                    <button type="button" class="mytasks-kanban__open" data-open-kanban-task="{{ $task->job_id }}"><strong data-kanban-title>{{ $task->job_topic }}</strong></button>
                                    <span class="mytasks-kanban__priority"><i class="bi bi-flag-fill"></i><span data-kanban-priority>{{ $priorities[(int) $task->job_priority] ?? $priorities[2] }}</span></span>
                                    <span class="mytasks-kanban__due {{ (int) $task->job_status !== 4 && $task->job_due_at?->isPast() ? 'is-late' : '' }}"><i class="bi bi-calendar3"></i>{{ $task->job_due_at ? $task->job_due_at->translatedFormat('j M Y') : 'ไม่มีกำหนด' }}</span>
                                    <footer><span class="mytasks-kanban__people">@foreach($people->take(3) as $person)<i title="{{ $person->name }}">{{ Str::substr($person->name, 0, 1) }}</i>@endforeach</span><button type="button" data-open-attachments="{{ $task->job_id }}" aria-label="ไฟล์แนบ"><i class="bi bi-paperclip"></i>{{ $task->images_count ?? $task->images->count() }}</button></footer>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    @empty
        <div class="mytasks-kanban__empty"><i class="bi bi-kanban"></i><p>ยังไม่มีโปรเจกต์สำหรับแสดงบอร์ดงาน</p></div>
    @endforelse
</section>
