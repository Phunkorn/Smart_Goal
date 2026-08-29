<article class="wb-member-card" data-member-card>
    <div class="wb-member-card__portrait">
        @include('work-board.partials.avatar', ['user' => $person, 'size' => 'xl'])
    </div>

    <div class="wb-member-card__identity">
        <h3>{{ $person->name }}</h3>
        <p>พนักงาน <span aria-hidden="true">·</span> {{ $department->department_name }}</p>
    </div>

    <div class="wb-member-card__summary {{ $person->board_task_count > 0 ? 'has-work' : 'is-empty' }}">
        <i class="bi {{ $person->board_task_count > 0 ? 'bi-briefcase-fill' : 'bi-inbox' }}" aria-hidden="true"></i>
        <span>{{ $person->board_task_count > 0 ? 'มีงานที่กำลังดูแล' : 'ยังไม่มีงานที่ได้รับมอบหมาย' }}</span>
    </div>

    <p class="wb-member-card__activity">
        <i class="bi bi-clock" aria-hidden="true"></i>
        @if($person->latest_activity_at)
            อัปเดตล่าสุด {{ $person->latest_activity_at->locale('th')->diffForHumans() }}
        @else
            ยังไม่มีการอัปเดตงาน
        @endif
    </p>

    <button type="button"
        class="wb-member-card__action"
        data-member-preview-trigger
        data-preview-url="{{ $previewUrl }}"
        data-member-name="{{ $person->name }}"
        data-bs-toggle="offcanvas"
        data-bs-target="#workBoardMemberPreview"
        aria-controls="workBoardMemberPreview"
        aria-label="ดูงานของสมาชิก {{ $person->name }}">
        <span>ดูงานของสมาชิก</span>
        <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </button>
</article>
