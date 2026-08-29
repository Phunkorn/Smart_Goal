@php
    use App\Support\TodayWorkspace;
    use App\Support\WorkBoardDesign;

    /**
     * Preview แสดงเฉพาะ "งานที่ต้องจัดการวันนี้" ที่ DepartmentWorkBoardQuery::previewTasks() คัดมาแล้ว
     * ประวัติงานทั้งหมดและงานที่เสร็จแล้วอยู่ที่ Member Workspace เต็ม ไม่ใช่ที่นี่
     */
@endphp

<section class="wb-member-preview" data-member-preview-content data-preview-member-id="{{ $member->id }}">
    <header class="wb-member-preview__person">
        @include('work-board.partials.avatar', ['user' => $member, 'size' => 'lg'])
        <div>
            <h3>{{ $member->name }}</h3>
            <p>พนักงาน <span aria-hidden="true">·</span> {{ $department->department_name }}</p>
        </div>
    </header>

    @if($isAdmin)
        {{-- Admin เข้าพื้นที่งานได้เสมอ แม้สมาชิกยังไม่มีงาน route ยังคงตรวจสิทธิ์ฝั่ง Server ตามเดิม --}}
        <a class="wb-member-preview__workspace-link"
            href="{{ route('admin.work-board.member', [$department, $member]) }}"
            data-preview-workspace-link>
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
            <span>เปิดพื้นที่งานของสมาชิก</span>
        </a>
    @else
        <div class="wb-member-preview__readonly" data-preview-readonly>
            <i class="bi bi-lock" aria-hidden="true"></i>
            <div>
                <strong>ดูแบบอ่านอย่างเดียว</strong>
                <span>ข้อมูลนี้เป็นภาพรวมงาน ไม่สามารถแก้ไขหรือเปิดพื้นที่งานของผู้อื่นได้</span>
            </div>
        </div>
    @endif

    <div class="wb-member-preview__section-heading">
        <h4>งานที่ต้องจัดการวันนี้</h4>
        @if($tasks->isNotEmpty())
            <span class="wb-count-chip" data-preview-task-count>{{ $tasks->count() }} งาน</span>
        @endif
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
                        @php($bucket = $task->today_bucket ?? 'active')
                        @php($overdueDays = $bucket === 'late' ? TodayWorkspace::overdueDays($task) : 0)
                        <time datetime="{{ $task->job_due_at?->toIso8601String() }}" data-preview-task-timing="{{ $bucket }}">
                            <i class="bi bi-calendar-event" aria-hidden="true"></i>
                            @switch($bucket)
                                @case('late'){{ $overdueDays > 0 ? 'ล่าช้า '.$overdueDays.' วัน' : 'เลยกำหนดแล้ว' }}@break
                                @case('due_today')กำหนดส่งวันนี้@break
                                @case('starts_today')เริ่มวันนี้@break
                                @default{{ 'กำหนดส่ง '.(TodayWorkspace::dateRangeLabel($task->job_due_at, $task->job_due_at) ?? 'ไม่ระบุ') }}
                            @endswitch
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
                <span><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                <h5>วันนี้ไม่มีงานที่ต้องติดตาม</h5>
                @if($isAdmin)
                    <p>เปิดพื้นที่งานของสมาชิกเพื่อดูงานทั้งหมด หรือสร้างโปรเจกต์และมอบหมายงานใหม่</p>
                @else
                    <p>เมื่อมีงานที่ต้องจัดการในวันนี้ รายการจะแสดงที่นี่</p>
                @endif
            </div>
        @endforelse
    </div>
</section>
