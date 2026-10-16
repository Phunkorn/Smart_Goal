@php
    /*
     * การ์ดงานหนึ่งใบของมุมมอง kanban
     *
     * แยกออกมาจาก tasks/partials/table-kanban.blade.php เพื่อให้ server วาดการ์ดใบเดียวได้
     * มุมมองนี้ถูกสไลซ์ฝั่ง server (TodayWorkspace::workspaceTable ตัดงานที่ปิดไปก่อนวันนี้ออก)
     * งานที่ถูกเปิดอีกครั้งจึงไม่มีการ์ดอยู่ในหน้าให้ JavaScript ย้ายคอลัมน์
     * MyTaskController::kanbanCard() วาด partial นี้กลับไปให้ฝั่งหน้าจอแทรกเข้าคอลัมน์
     * โดยไม่ต้องโหลดหน้าใหม่ — markup จึงมีแหล่งเดียว ไม่ใช่สองชุดที่ต้องซิงก์กันเอง
     */
    $priorities = $priorities ?? [
        2 => 'สำคัญไม่ด่วน',
        3 => 'สำคัญด่วน',
        4 => 'ด่วนไม่ค่อยสำคัญ',
        5 => 'ไม่รีบ ไม่มีกำหนด',
    ];
    $unreadCommentCounts = $unreadCommentCounts ?? collect();
    $uniformAdminName = $uniformAdminName ?? null;
    $taskLinkMode = $taskLinkMode ?? false;
@endphp

@php
    $people = collect([$task->user])
        ->filter()
        ->concat(
            $task->collaborators->where('pivot.status', 'accepted')
        )
        ->unique('id');

    $kanbanChildren = $task->relationLoaded('children') ? $task->children : collect();
    $kanbanReviewableChildren = $kanbanChildren->filter(
        fn ($child) => (int) $child->job_status === 3
            && auth()->user()->can('review', $child)
    )->count();

    $taskAdminSenderName =
        ! $uniformAdminName && $task->creator?->role === 'admin'
            ? $task->creator->name
            : null;
@endphp

@include('tasks.partials.task-support-source', [
    'task' => $task,
    'adminSenderName' => $taskAdminSenderName,
    'taskLinkMode' => $taskLinkMode,
    'includeCollaborators' => true
])

<article
    class="mytasks-kanban__card priority-{{ $task->job_priority }}"
    data-kanban-card
    data-id="{{ $task->job_id }}"
    data-status="{{ $task->job_status }}"
    data-priority="{{ $task->job_priority }}"
    data-can-review="{{ auth()->user()->can('review', $task) ? 1 : 0 }}"
    data-reviewable-subtasks="{{ $kanbanReviewableChildren }}"
    data-late="{{ (int) $task->job_status === 6 ? 1 : 0 }}"
    data-cross-department="{{ \App\Support\CrossDepartmentWork::marker($task, $workspaceSubject ?? auth()->user()) ? 1 : 0 }}"
    {{-- ตัวกรองผู้รับผิดชอบ — ค่าเดียวกับแถวในมุมมองตาราง --}}
    data-assignee="{{ $task->user_id }}"
>
    <button
        type="button"
        class="mytasks-kanban__task-more"
        data-open-kanban-task="{{ $task->job_id }}"
        aria-label="เปิดรายการงาน {{ $task->job_topic }}"
    >
        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
    </button>

    <button
        type="button"
        class="mytasks-kanban__open"
        data-open-kanban-task="{{ $task->job_id }}"
    >

        <div class="mytasks-kanban__card-head">

            <strong data-kanban-title>
                {{ $task->job_topic }}
            </strong>

            <span class="mytasks-kanban__priority priority-tone-{{ $task->job_priority }}">
                {{ $priorities[(int) $task->job_priority] ?? $priorities[2] }}
            </span>

        </div>

        @include('tasks.partials.approval-state-marker', ['task' => $task])

        <span class="mytasks-kanban__project-name">
            โปรเจกต์: {{ $task->taskList?->name ?? 'งานทั่วไป' }}
        </span>

        <span class="mytasks-kanban__due {{ (int) $task->job_status === 6 ? 'is-late' : '' }}">
            <i class="bi bi-calendar3"></i>

            @if ((int) $task->job_status === 6)
                ล่าช้า {{ \App\Support\TodayWorkspace::overdueDays($task) }} วัน
            @elseif ((int) $task->job_status === 5)
                พักมา {{ $task->paused_at?->startOfDay()->diffInDays(now()->startOfDay()) ?? 0 }} วัน
            @elseif (in_array((int) $task->job_status, [2, 3], true) && ($timeProgress = \App\Support\TodayWorkspace::timeProgress($task)))
                <span class="mytasks-kanban__date-progress">
                    <span>{{ $timeProgress['range_label'] }}</span>
                    <small>{{ $timeProgress['progress_label'] }}</small>
                </span>
            @else
                กำหนดส่ง
                {{-- เวลาอ่านจาก dueTimeLabel() ที่เดียว งานที่เลือก "ไม่ระบุเวลา" จึงไม่ขึ้น 23:59 น. --}}
                {{ $task->job_due_at ? \App\Support\TodayWorkspace::businessMoment($task->job_due_at)->locale('th')->translatedFormat('j M Y').' '.\App\Support\TodayWorkspace::dueTimeLabel($task, '') : \App\Support\TodayWorkspace::NO_DUE_LABEL }}
            @endif
        </span>

    </button>

    <footer>

        <div class="mytasks-kanban__assignee">

            @if ($task->user)
                <span class="mytasks-kanban__avatar">
                    @include('components.user-avatar-content', ['user' => $task->user, 'avatarUpper' => true])
                </span>

                <span class="mytasks-kanban__assignee-name">
                    {{ $task->user->name }}
                </span>
            @else
                <span class="mytasks-kanban__unassigned">
                    ยังไม่มีผู้รับผิดชอบ
                </span>
            @endif

        </div>

        <div class="mytasks-kanban__footer-badges">
        <button
            type="button"
            class="mytasks-kanban__attachment"
            data-open-attachments="{{ $task->job_id }}"
            aria-label="ไฟล์แนบ"
        >
            <i class="bi bi-paperclip"></i>
            {{ $task->images_count ?? $task->images->count() }}
        </button>
        @if(($unreadCommentCounts[$task->job_id] ?? 0) > 0)
            <span class="mytasks-kanban__comments" data-unread-comments="{{ $task->job_id }}"><i class="bi bi-chat-left-text"></i><b>{{ $unreadCommentCounts[$task->job_id] }}</b></span>
        @endif

        {{--
            ชิปงานย่อย — มุมมองนี้ไม่ยกงานย่อยขึ้นมาเป็นการ์ดของตัวเอง
            เพราะการ์ดจะรกจนหางานจริงไม่เจอ แสดงแค่ความคืบหน้าให้เห็นก่อนกด
            ว่าทำไมลากไปคอลัมน์ "เสร็จแล้ว" ไม่ได้ กดแล้วเปิดโมดัลไปจัดการต่อ

            children ถูก eager-load มาแล้วทั้งใน MyTaskController::index()
            และ WorkBoardController::adminMember() การนับจึงอยู่ในหน่วยความจำ
            ไม่เพิ่มคิวรี แต่ต้องเช็ก relationLoaded() ก่อนเสมอเหมือน partial อื่น
        --}}
        @php
            $kanbanChildrenDone = $kanbanChildren->where('job_status', 4)->count();
            $kanbanChildrenLabel = 'งานย่อยเสร็จแล้ว '.$kanbanChildrenDone.' จาก '.$kanbanChildren->count().' งาน';
        @endphp
        @if($kanbanChildren->isNotEmpty())
            <button
                type="button"
                class="mytasks-kanban__subtasks {{ $kanbanChildrenDone === $kanbanChildren->count() ? 'is-complete' : 'is-pending' }}"
                data-kanban-subtasks="{{ $task->job_id }}"
                data-open-kanban-task="{{ $task->job_id }}"
                title="{{ $kanbanChildrenLabel }}"
                aria-label="{{ $kanbanChildrenLabel }}"
            >
                <i class="bi bi-check2-square" aria-hidden="true"></i>
                <b data-kanban-subtask-done>{{ $kanbanChildrenDone }}</b>/<span data-kanban-subtask-total>{{ $kanbanChildren->count() }}</span>
            </button>
        @endif
        </div>

    </footer>

</article>
