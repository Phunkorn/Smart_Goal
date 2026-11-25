<article class="wb-member-card" data-member-card data-profile-user="{{ $person->id }}">
    <x-profile-cover :user="$person" :priority="$priorityCover" class="wb-member-card__cover" />
    <div class="wb-member-card__body">
        <div class="wb-member-card__portrait">
            <x-profile-portrait :frame="$person->avatarFrame">
                @include('work-board.partials.avatar', ['user' => $person, 'size' => 'xl'])
            </x-profile-portrait>
        </div>

        <div class="wb-member-card__identity">
            <h3 title="{{ $person->name }}">{{ $person->name }}</h3>
            {{-- บทบาทใช้ RoleLabel ร่วมกับหน้าอื่น เพื่อแยกหัวหน้าแผนกออกจากพนักงานตามข้อมูลจริง --}}
            <p>{{ \App\Support\RoleLabel::for($person) }} <span aria-hidden="true">·</span> {{ $department->department_name }}</p>
        </div>

        <p class="wb-member-card__activity">
            <i class="bi bi-clock" aria-hidden="true"></i>
            <span>
                @if($person->latest_activity_at)
                    อัปเดตล่าสุด {{ $person->latest_activity_at->locale('th')->diffForHumans() }}
                @else
                    ยังไม่มีการอัปเดตงาน
                @endif
            </span>
        </p>
    </div>

    <button type="button"
        class="wb-member-card__action"
        data-member-preview-trigger
        data-preview-url="{{ $previewUrl }}"
        data-member-name="{{ $person->name }}"
        data-bs-toggle="modal"
        data-bs-target="#workBoardMemberPreview"
        aria-haspopup="dialog"
        aria-controls="workBoardMemberPreview"
        title="ดูงานของสมาชิก {{ $person->name }}"
        aria-label="ดูงานของสมาชิก {{ $person->name }}">
        <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
    </button>
</article>
