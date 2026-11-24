<article class="wb-member-card" data-member-card data-profile-user="{{ $person->id }}">
    <x-profile-cover :user="$person" class="wb-member-card__cover" />
    <div class="wb-member-card__body">
        <div class="wb-member-card__portrait">
            <x-profile-portrait :user="$person">
                @include('work-board.partials.avatar', ['user' => $person, 'size' => 'xl'])
            </x-profile-portrait>
        </div>

        <div class="wb-member-card__identity">
            <h3 title="{{ $person->name }}">{{ $person->name }}</h3>
            {{-- บทบาทใช้ RoleLabel ร่วมกับหน้าอื่น เพื่อแยกหัวหน้าแผนกออกจากพนักงานตามข้อมูลจริง --}}
            <p>{{ \App\Support\RoleLabel::for($person) }} <span aria-hidden="true">·</span> {{ $department->department_name }}</p>
        </div>

        {{--
            งานประจำ/นอกสถานที่ของวันนี้ — ใช้ TodayOperationalStatus ตามเดิม
            แสดงเฉพาะผู้ดูที่ผ่าน WorkLogPolicy::viewDay ซึ่งคำนวณที่ server
        --}}
        @if($person->board_operations)
            @php($routine = $person->board_operations['routine'])
            @php($field = $person->board_operations['field'])
            <div class="wb-member-card__ops" data-member-operations>
                <span class="wb-ops-chip wb-ops-chip--{{ $routine['tone'] }}"
                    title="งานประจำวันนี้: {{ $routine['label'] }}">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    <span>
                        @if($routine['total'] > 0)
                            งานประจำ {{ $routine['closed'] }}/{{ $routine['total'] }}
                        @else
                            ไม่มีงานประจำ
                        @endif
                    </span>
                </span>
                @if($field['count'] > 0)
                    <span class="wb-ops-chip wb-ops-chip--field" title="{{ implode(', ', $field['locations']) ?: 'งานนอกสถานที่' }}">
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <span>นอกสถานที่{{ $field['locations'] !== [] ? ' · '.$field['locations'][0] : ' '.$field['count'].' งาน' }}</span>
                    </span>
                @endif
            </div>
        @endif

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
        data-bs-toggle="offcanvas"
        data-bs-target="#workBoardMemberPreview"
        aria-controls="workBoardMemberPreview"
        title="ดูงานของสมาชิก {{ $person->name }}"
        aria-label="ดูงานของสมาชิก {{ $person->name }}">
        <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
    </button>
</article>
