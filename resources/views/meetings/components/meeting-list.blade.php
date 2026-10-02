@php
    /**
     * เนื้อหาหน้า "การประชุม" ใช้ร่วมกันระหว่าง route `meetings.index` เดิม
     * และ view "ประชุม" ที่ฝังอยู่ในหน้า "งานของฉัน" — ห้ามคัดลอกโครงสร้างนี้ไปที่อื่น
     *
     * $meetingFormAction ปลายทางของฟอร์มกรองและลิงก์ล้างตัวกรอง
     * $meetingBaseQuery  query string ที่ต้องติดไปกับทุกการกรอง (เช่น ['view' => 'meeting'])
     * $meetingEmbedded   true เมื่อถูกฝังในหน้าอื่น ทำให้หัวเรื่องลดระดับเป็น h2 กัน h1 ซ้อน
     * $meetingCanCreate  false เพื่อซ่อนปุ่ม "นัดประชุม" และ modal สร้าง ในหน้าที่การสร้าง
     *                    จะพาผู้ใช้ออกจากบริบทเดิม (MeetingController::store() redirect ไป meetings.show)
     *                    เป็นการซ่อน UI เท่านั้น สิทธิ์จริงยังตัดสินที่ MeetingPolicy เหมือนเดิม
     */
    $meetingFormAction = $meetingFormAction ?? route('meetings.index');
    $meetingBaseQuery = $meetingBaseQuery ?? [];
    $meetingEmbedded = $meetingEmbedded ?? false;
    $meetingCanCreate = ($meetingCanCreate ?? true) && auth()->user()->can('create', App\Models\Meeting::class);
    $meetingClearUrl = $meetingFormAction . ($meetingBaseQuery ? '?' . http_build_query($meetingBaseQuery) : '');
    // ลิงก์เข้าหน้ารายละเอียดเป็นผู้บอกที่มา ปุ่ม "กลับ" จึงพากลับ Workspace ได้โดยไม่ต้องเดาจาก referrer
    $meetingFrom = ($meetingBaseQuery['view'] ?? null) === 'meeting' ? 'workspace' : null;
    $meetingFeedback = [
        'success' => session('meeting_success'),
        'error' => session('meeting_error') ?: $errors->first(),
        'open_modal' => session('meeting_open_modal') ?: ($errors->any() ? 'createMeetingModal' : null),
    ];
@endphp

@push('styles')
    @vite('resources/css/pages/meetings.css')
@endpush
@push('scripts')
    @vite('resources/js/pages/meetings/index.js')
@endpush

<div class="meetings-page {{ $meetingEmbedded ? 'meetings-page--embedded' : '' }}">
    <header class="meetings-page__header">
        <div>
            <span class="meetings-page__eyebrow"><i class="bi bi-calendar-event" aria-hidden="true"></i> Meetings</span>
            @if ($meetingEmbedded)
                <h2>การประชุม</h2>
            @else
                <h1>การประชุม</h1>
            @endif
            <p>{{ $inspectedEmployee ? 'การประชุมที่ ' . $inspectedEmployee->name . ' เป็นผู้สร้างหรือผู้เข้าร่วม' : 'นัดหมายและติดตามการประชุมที่เกี่ยวข้องกับคุณ' }}
            </p>
        </div>
        @if ($meetingCanCreate)
            <button class="meetings-page__button meetings-page__button--primary" type="button"
                data-meeting-modal-trigger="createMeetingModal" aria-controls="createMeetingModal" aria-haspopup="dialog"
                data-meeting-create><i class="bi bi-plus-lg" aria-hidden="true"></i> นัดประชุม</button>
        @endif
    </header>

    {{--
        ตัวกรองนี้ส่งผลทันทีไม่ต้องกดปุ่ม "แสดงผล" อีกต่อไป:
        - ค้นหา: ส่งหลังพิมพ์หยุดไปชั่วครู่ (data-auto-submit-debounce, resources/js/components/auto-submit-filter.js)
        - ช่วงเวลา/พนักงาน: ส่งทันทีที่เปลี่ยน (data-auto-submit) ยกเว้นเลือก "กำหนดช่วงวันที่เอง"
          ซึ่งแค่เปิดช่องวันที่ แล้วค่อยส่งเองเมื่อกรอกครบทั้งคู่ (data-period-auto-submit,
          resources/js/components/period-range.js — ตรรกะเดียวกับหัวรายงานโปรเจกต์)
        ปุ่ม "แสดงผล" ยังอยู่ใน <noscript> ให้ใช้เมื่อ JavaScript ไม่ทำงาน
    --}}
    <form class="meetings-page__filters" method="GET" action="{{ $meetingFormAction }}" data-auto-submit-form
        data-period-auto-submit>
        @foreach ($meetingBaseQuery as $queryKey => $queryValue)
            <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
        @endforeach
        <label class="meetings-page__search"><span>ค้นหา</span><span><input type="search" name="search"
                    value="{{ $filters['search'] }}" placeholder="ชื่อ รายละเอียด หรือสถานที่"
                    data-auto-submit-debounce></span></label>
        <div class="meetings-page__period">
            <label for="meetingsPeriod">ช่วงเวลา</label>
            <div data-sg-select>
                <select id="meetingsPeriod" name="period" data-auto-submit data-period-select>
                    @foreach ($periodOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['period'] === $value)
                            @if ($value === \App\Services\MeetingQueryService::CUSTOM_PERIOD) data-auto-submit-skip @endif>
                            {{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="meetings-page__range" data-period-range @unless ($filters['period'] === \App\Services\MeetingQueryService::CUSTOM_PERIOD) hidden @endunless>
                <label class="meetings-page__range-field"><span>ตั้งแต่</span>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] }}" data-date-picker
                        data-period-input="from" @unless ($filters['period'] === \App\Services\MeetingQueryService::CUSTOM_PERIOD) disabled @endunless></label>
                <label class="meetings-page__range-field"><span>ถึง</span>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] }}" data-date-picker
                        data-period-input="to" @unless ($filters['period'] === \App\Services\MeetingQueryService::CUSTOM_PERIOD) disabled @endunless></label>
            </div>
        </div>
        @if ($employeeOptions->isNotEmpty())
            <div class="meetings-page__employee">
                <label for="meetingsEmployee">พนักงาน</label>
                <div data-sg-select>
                    <select id="meetingsEmployee" name="employee" data-auto-submit>
                        <option value="">พนักงานทั้งหมด</option>
                        @foreach ($employeeOptions as $employee)
                            <option value="{{ $employee->id }}" @selected($filters['employee_id'] === $employee->id)>{{ $employee->name }}
                                · {{ $employee->department?->department_name ?? 'ไม่ระบุแผนก' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif
        <noscript><button class="meetings-page__button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i>
                แสดงผล</button></noscript>
    </form>

    <div class="meetings-page__summary"><span><strong>{{ $meetings->total() }}</strong>
            การประชุม</span><span>{{ $periodOptions[$filters['period']] }}</span>
        @if ($filters['search'] !== '' || $filters['employee_id'])
            <a href="{{ $meetingClearUrl }}">ล้างตัวกรอง</a>
        @endif
    </div>
    <section class="meetings-page__list" aria-label="รายการการประชุม">
        @forelse($meetings as $meeting)
            @include(
                'meetings.components.meeting-card',
                compact('meeting', 'nowBangkok', 'inspectedEmployee', 'meetingFrom'))
        @empty
            <div class="meetings-page__empty"><i class="bi bi-calendar2-x" aria-hidden="true"></i>
                <h2>{{ $inspectedEmployee ? $inspectedEmployee->name . ' ไม่มีการประชุมในช่วงเวลานี้' : 'ไม่พบการประชุมในช่วงเวลานี้' }}
                </h2>
                <p>ลองเปลี่ยนคำค้นหาหรือช่วงเวลา@if ($meetingCanCreate)
                        หรือสร้างนัดหมายใหม่จากปุ่ม “นัดประชุม” ด้านบน
                    @endif
                </p>
            </div>
        @endforelse
    </section>

    @if ($meetings->hasPages())
        <div class="meetings-page__pagination">{{ $meetings->links('pagination::bootstrap-5') }}</div>
    @endif
    @if ($meetingCanCreate)
        @include('meetings.components.form-modal', [
            'formMeeting' => null,
            'attendeeOptions' => $attendeeOptions,
            'attendeeDepartments' => $attendeeDepartments,
            'projectOptions' => $projectOptions,
        ])
    @endif
    {{-- ปุ่มแก้ไขบนการ์ดโหลดฟอร์มแก้ไขของการประชุมนั้นมาใส่ตรงนี้ด้วย AJAX แล้วค่อยเปิดเป็น modal
         ดู initializeMeetingEditTriggers ใน meetings/index.js --}}
    <div data-meeting-edit-modal-slot></div>
    <script type="application/json" data-meeting-feedback>@json($meetingFeedback)</script>
</div>
