@if ($meeting->attendees->isEmpty())
    <span class="meetings-page__muted">ยังไม่มีผู้เข้าร่วม</span>
@else
    @php
        // ไม่สร้างปุ่มกรองให้ "ไม่ระบุแผนก" — เป็นค่า fallback ของคนที่ไม่มีแผนกจริง (เช่น admin/viewer)
        // ไม่ใช่แผนกที่กรองดูแล้วมีความหมาย คนกลุ่มนี้ยังแสดงปกติใต้ "ทั้งหมด" แค่ไม่มีปุ่มเฉพาะให้กด
        $attendeeDepartmentNames = isset($compact)
            ? collect()
            : $meeting->attendees
                ->map(fn ($person) => $person->department?->department_name)
                ->filter()
                ->unique()
                ->sort()
                ->values();
    @endphp
    {{--
        กรองมุมมองผู้เข้าร่วมตามแผนก — มีประโยชน์เฉพาะเมื่อมีมากกว่าหนึ่งแผนกจริง (การประชุมข้ามแผนก)
        เปิดหน้ามาต้องเห็น "ทั้งหมด" เสมอ กรองแค่ซ่อน/แสดงฝั่ง client ไม่กระทบรายชื่อหรือจำนวนจริง
        (ดู initializeAttendeeDepartmentFilter ใน meetings/index.js)
    --}}
    @if ($attendeeDepartmentNames->count() > 1)
        <div class="meetings-page__attendee-filter" role="group" aria-label="กรองผู้เข้าร่วมตามแผนก"
            data-attendee-department-filter>
            <button type="button" class="is-active" data-attendee-department="" aria-pressed="true">ทั้งหมด</button>
            @foreach ($attendeeDepartmentNames as $departmentName)
                <button type="button" data-attendee-department="{{ $departmentName }}"
                    aria-pressed="false">{{ $departmentName }}</button>
            @endforeach
        </div>
    @endif
    <div class="meetings-page__attendees" aria-label="ผู้เข้าร่วม {{ $meeting->attendees->count() }} คน"
        @unless (isset($compact)) data-attendee-list @endunless>
        @foreach ($meeting->attendees->take($compact ?? 1000) as $person)
            <span class="meetings-page__attendee"
                @unless (isset($compact)) data-attendee-department="{{ $person->department?->department_name ?? 'ไม่ระบุแผนก' }}" @endunless>
                <i aria-hidden="true">@include('components.user-avatar-content', ['user' => $person])</i>
                @if (isset($compact))
                    {{ $person->name }}
                @else
                    {{-- แผงรายละเอียดเต็มมีที่พอให้บอกตำแหน่ง/แผนกด้วย ป้ายการ์ดแบบย่อไม่มีที่พอจึงยังโชว์แค่ชื่อ --}}
                    <span class="meetings-page__attendee-copy">
                        <strong>{{ $person->name }}</strong>
                        <small>{{ \App\Support\RoleLabel::withDepartment($person) }}</small>
                    </span>
                @endif
            </span>
        @endforeach
        @if (isset($compact) && $meeting->attendees->count() > $compact)
            <span class="meetings-page__attendee-more">+{{ $meeting->attendees->count() - $compact }}</span>
        @endif
    </div>
@endif
