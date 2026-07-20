@php
    $allListTasks = $listTasks->merge($listCompletedTasks);
    $dueDates = $allListTasks->pluck('job_due_at')->filter()->sort();
    $dueRange = $dueDates->isEmpty()
        ? 'ยังไม่มีกำหนดส่ง'
        : $dueDates->first()->format('d M') . ($dueDates->count() > 1 ? ' - ' . $dueDates->last()->format('d M') : '');
@endphp

<article class="task-group {{ $isVisible ? '' : 'is-hidden' }}" data-list-lane="{{ $listId }}">
    <div class="group-head">
        <div class="group-title">
            <button type="button" class="group-toggle" data-collapse-group aria-label="พับกลุ่ม">
                <i class="bi bi-chevron-down"></i>
            </button>
            <h2 class="group-name">{{ $listName }}</h2>
            <span class="group-count">{{ $listTasks->count() }}</span>
        </div>
        <div class="group-summary">{{ $dueRange }}</div>
    </div>

    <div class="group-body">
        <div class="task-table-wrap">
            <table class="task-table">
                <thead>
                    <tr>
                        <th class="check-col"><input type="checkbox" disabled></th>
                        <th class="name-col">Project</th>
                        <th>ความสำคัญ</th>
                        <th>กำหนดส่ง</th>
                        <th>Subitem</th>
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
                            <td colspan="9"><div class="empty-row-message">ยังไม่มีงานในรายการนี้</div></td>
                        </tr>
                    @endforelse
                    <tr class="add-row">
                        <td></td>
                        <td colspan="8">
                            <form class="add-task-inline" action="{{ route('mytasks.store') }}" method="POST">
                                @csrf
                                <input type="hidden" name="work_order_list_id" value="{{ $isVirtual ? '' : $listId }}">
                                <input type="text" name="job_topic" maxlength="255" required placeholder="+ Add project">
                                <button type="submit">เพิ่ม</button>
                            </form>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="completed-group">
            <details>
                <summary><i class="bi bi-check-circle"></i> Completed <span>{{ $listCompletedTasks->count() }}</span></summary>
                <div class="task-table-wrap">
                    <table class="task-table">
                        <tbody>
                            @forelse ($listCompletedTasks as $task)
                                @include('tasks.partials.google-task-item', ['task' => $task])
                            @empty
                                <tr class="empty-row">
                                    <td colspan="9"><div class="empty-row-message">ยังไม่มีงานที่เสร็จแล้ว</div></td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    </div>
</article>
