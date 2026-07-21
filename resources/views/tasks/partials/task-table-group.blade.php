@php
    $isCompletedBoard = $isCompletedBoard ?? false;
    $allListTasks = $listTasks;
    $dueDates = $allListTasks->pluck('job_due_at')->filter()->sort();
    $projectTask = $allListTasks->sortByDesc('job_priority')->first();
    $priorityClass = match ((int) ($projectTask?->job_priority ?? 1)) {
        3 => 'project-priority-high',
        2 => 'project-priority-medium',
        default => 'project-priority-low',
    };
    $thaiMonths = [
        1 => 'ม.ค.',
        2 => 'ก.พ.',
        3 => 'มี.ค.',
        4 => 'เม.ย.',
        5 => 'พ.ค.',
        6 => 'มิ.ย.',
        7 => 'ก.ค.',
        8 => 'ส.ค.',
        9 => 'ก.ย.',
        10 => 'ต.ค.',
        11 => 'พ.ย.',
        12 => 'ธ.ค.',
    ];
    $formatThaiDate = fn($date) => $date->format('d') .
        ' ' .
        $thaiMonths[(int) $date->format('n')] .
        ' ' .
        ((int) $date->format('Y') + 543);
    $dueRange = $dueDates->isEmpty()
        ? 'ยังไม่มีกำหนดส่ง'
        : $formatThaiDate($dueDates->first()) .
            ($dueDates->count() > 1 ? ' – ' . $formatThaiDate($dueDates->last()) : '');
    $lastDueDate = $dueDates->last();
    $remainingDays = $lastDueDate
        ? now()
            ->startOfDay()
            ->diffInDays($lastDueDate->copy()->startOfDay(), false)
        : null;
    $remainingLabel = match (true) {
        $remainingDays === null => null,
        $remainingDays < 0 => 'เกินกำหนด ' . abs($remainingDays) . ' วัน',
        $remainingDays === 0 => 'ครบกำหนดวันนี้',
        default => 'เหลืออีก ' . $remainingDays . ' วัน',
    };
    $assigneeName = $projectTask?->user?->name;
    $adminSenderName = $projectTask?->creator?->role === 'admin' ? $projectTask->creator->name : null;
@endphp

<article class="task-group {{ $isVisible ? '' : 'is-hidden' }}" data-list-lane="{{ $listId }}">
    <div class="group-head {{ $priorityClass }}">
        <div class="group-title">
            <button type="button" class="group-toggle" data-collapse-group aria-label="พับกลุ่ม">
                <i class="bi bi-chevron-down"></i>
            </button>
            <h2 class="group-name">{{ $listName }}</h2>
            <span class="group-count">{{ $listTasks->count() }}</span>
        </div>
        <div class="group-summary">
            <span>{{ $dueRange }}</span>
            @if ($remainingLabel)
                <span class="group-meta-days">{{ $remainingLabel }}</span>
            @endif
            @if ($assigneeName)
                <span class="group-meta-owner">ผู้รับผิดชอบ: {{ $assigneeName }}</span>
            @endif
            @if ($adminSenderName)
                <span class="group-meta-admin">มอบหมายโดย Admin: {{ $adminSenderName }}</span>
            @endif
        </div>
    </div>

    <div class="group-body">
        <div class="task-table-wrap">
            <table class="task-table">
                <thead>
                    <tr>
                        <th class="check-col"><input type="checkbox" disabled></th>
                        <th class="name-col">
                            รายการงาน
                        </th>
                        <th>ความสำคัญ</th>
                        <th>กำหนดส่ง</th>
                        <th></th>
                        <th>ผู้ร่วมงาน</th>
                        <th>Files</th>
                        <th>สถานะ</th>
                        <th class="row-actions"></th>
                    </tr>
                </thead>
                <tbody data-group-body="{{ $listId }}">
                    @forelse ($listTasks as $task)
                        @include('tasks.partials.google-task-item', ['task' => $task])
                    @empty
                        <tr class="empty-row">
                            <td colspan="9">
                                <div class="empty-row-message">ยังไม่มีงานในรายการนี้</div>
                            </td>
                        </tr>
                    @endforelse
                    @unless ($isCompletedBoard)
                        <tr class="add-row">
                            <td></td>
                            <td colspan="8">
                                <form class="add-task-inline" action="{{ route('mytasks.store') }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="work_order_list_id"
                                        value="{{ $isVirtual ? '' : $listId }}">
                                    <input type="text" name="job_topic" maxlength="255" required
                                        placeholder="+ เพิ่มรายการงาน">
                                    <button type="submit">เพิ่ม</button>
                                </form>
                            </td>
                        </tr>
                    @endunless
                </tbody>
            </table>
        </div>
    </div>
</article>
