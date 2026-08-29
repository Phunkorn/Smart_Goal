@php
    use App\Support\WorkBoardDesign;
@endphp

<section class="wb-member-preview" data-member-preview-content data-preview-member-id="{{ $member->id }}">
    <header class="wb-member-preview__person">
        @include('work-board.partials.avatar', ['user' => $member, 'size' => 'lg'])
        <div>
            <h3>{{ $member->name }}</h3>
            <p>พนักงาน <span aria-hidden="true">·</span> {{ $department->department_name }}</p>
        </div>
    </header>

    @unless($isAdmin)
        <div class="wb-member-preview__readonly" data-preview-readonly>
            <i class="bi bi-lock" aria-hidden="true"></i>
            <div>
                <strong>ดูแบบอ่านอย่างเดียว</strong>
                <span>ข้อมูลนี้เป็นภาพรวมงาน ไม่สามารถแก้ไขหรือเปิดพื้นที่งานของผู้อื่นได้</span>
            </div>
        </div>
    @endunless

    <div class="wb-member-preview__section-heading">
        <h4>รายการงานที่ได้รับมอบหมาย</h4>
    </div>

    <div class="wb-preview-task-list" data-preview-task-list>
        @forelse($tasks as $task)
            @php($status = WorkBoardDesign::status($task))
            @php($priority = WorkBoardDesign::taskPriority((int) $task->job_priority))
            @if($isAdmin)
                <a class="wb-preview-task wb-preview-task--link"
                    href="{{ route('admin.work-board.member', [$department, $member, 'open_task' => $task->job_id]) }}"
                    data-preview-task
                    data-preview-task-link
                    aria-label="เปิดงาน {{ $task->job_topic }} ในพื้นที่งานของ {{ $member->name }}">
            @else
                <article class="wb-preview-task" data-preview-task>
            @endif
                <div class="wb-preview-task__icon wb-tone-{{ $status['tone'] }}">
                    <i class="bi bi-clipboard" aria-hidden="true"></i>
                </div>
                <div class="wb-preview-task__content">
                    <div class="wb-preview-task__title-row">
                        <h5>{{ $task->job_topic }}</h5>
                        <span class="wb-status wb-tone-{{ $status['tone'] }}"><i></i>{{ $status['label'] }}</span>
                    </div>
                    <p>{{ $task->taskList?->name ?? 'งานทั่วไป' }}</p>
                    <div class="wb-preview-task__meta">
                        <span class="wb-priority wb-tone-{{ $priority['tone'] }}">
                            <i class="bi bi-flag-fill" aria-hidden="true"></i>{{ $priority['label'] }}
                        </span>
                        <time datetime="{{ $task->latest_activity_at?->toIso8601String() }}">
                            <i class="bi bi-clock-history" aria-hidden="true"></i>
                            {{ $task->latest_activity_at?->locale('th')->translatedFormat('j M Y H:i') ?? 'ยังไม่มีการอัปเดต' }}
                        </time>
                    </div>
                </div>
                @if($isAdmin)
                    <i class="bi bi-chevron-right wb-preview-task__arrow" aria-hidden="true"></i>
                @endif
            @if($isAdmin)
                </a>
            @else
                </article>
            @endif
        @empty
            <div class="wb-preview-empty" data-preview-empty>
                <span><i class="bi bi-inbox" aria-hidden="true"></i></span>
                <h5>ยังไม่มีงานที่ได้รับมอบหมาย</h5>
                <p>เมื่อมีงานของสมาชิก รายการจะแสดงที่นี่</p>
            </div>
        @endforelse
    </div>
</section>
