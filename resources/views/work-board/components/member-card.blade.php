<article class="wb-member-card" data-member-card>
    <div class="wb-member-card__portrait">
        @include('work-board.partials.avatar', ['user' => $person, 'size' => 'xl'])
    </div>

    <div class="wb-member-card__identity">
        <h3>{{ $person->name }}</h3>
        {{--
            บทบาทต้องมาจาก RoleLabel ไม่ใช่คำว่า "พนักงาน" ที่เขียนตายตัว
            หัวหน้าแผนกมี users.role = 'user' เหมือนกัน ต่างกันที่ธง is_department_head
            การเขียนคำนี้ตรง ๆ จึงแสดงหัวหน้าแผนกเป็นพนักงานทุกครั้ง
        --}}
        <p>{{ \App\Support\RoleLabel::for($person) }} <span aria-hidden="true">·</span> {{ $department->department_name }}</p>
    </div>

    <div class="wb-member-card__summary {{ $person->board_task_count > 0 ? 'has-work' : 'is-empty' }}">
        <i class="bi {{ $person->board_task_count > 0 ? 'bi-briefcase-fill' : 'bi-inbox' }}" aria-hidden="true"></i>
        <span>{{ $person->board_task_count > 0 ? 'มีงานที่กำลังดูแล' : 'ยังไม่มีงานที่ได้รับมอบหมาย' }}</span>
    </div>

    {{--
        งานประจำ/นอกสถานที่ของวันนี้ — มาจาก TodayOperationalStatus ตัวเดียวกับรายงานปฏิบัติงาน
        แสดงเฉพาะผู้ดูที่ผ่าน WorkLogPolicy::viewDay (ตัวเอง / หัวหน้าแผนกนั้น / admin) คำนวณที่ server
    --}}
    @if($person->board_operations)
        @php($routine = $person->board_operations['routine'])
        @php($field = $person->board_operations['field'])
        <div class="wb-member-card__ops" data-member-operations>
            <span class="wb-ops-chip wb-ops-chip--{{ $routine['tone'] }}"
                title="งานประจำวันนี้: {{ $routine['label'] }}">
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                @if($routine['total'] > 0)
                    งานประจำ {{ $routine['closed'] }}/{{ $routine['total'] }}
                @else
                    ไม่มีงานประจำ
                @endif
            </span>
            @if($field['count'] > 0)
                <span class="wb-ops-chip wb-ops-chip--field" title="{{ implode(', ', $field['locations']) ?: 'งานนอกสถานที่' }}">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                    นอกสถานที่{{ $field['locations'] !== [] ? ' · '.$field['locations'][0] : ' '.$field['count'].' งาน' }}
                </span>
            @endif
        </div>
    @endif

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
