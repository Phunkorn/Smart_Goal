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
    $meetingClearUrl = $meetingFormAction.($meetingBaseQuery ? '?'.http_build_query($meetingBaseQuery) : '');
    $meetingFeedback = [
        'success' => session('meeting_success'),
        'error' => session('meeting_error') ?: $errors->first(),
        'open_modal' => session('meeting_open_modal') ?: ($errors->any() ? 'createMeetingModal' : null),
    ];
@endphp

@push('styles') @vite('resources/css/pages/meetings.css') @endpush
@push('scripts') @vite('resources/js/pages/meetings/index.js') @endpush

<div class="meetings-page {{ $meetingEmbedded ? 'meetings-page--embedded' : '' }}">
    <header class="meetings-page__header">
        <div>
            <span class="meetings-page__eyebrow"><i class="bi bi-calendar-event" aria-hidden="true"></i> Meetings</span>
            @if($meetingEmbedded)
                <h2>การประชุม</h2>
            @else
                <h1>การประชุม</h1>
            @endif
            <p>{{ $inspectedEmployee ? 'การประชุมที่ '.$inspectedEmployee->name.' เป็นผู้สร้างหรือผู้เข้าร่วม' : 'นัดหมายและติดตามการประชุมที่เกี่ยวข้องกับคุณ' }}</p>
        </div>
        @if($meetingCanCreate)<button class="meetings-page__button meetings-page__button--primary" type="button" data-meeting-modal-trigger="createMeetingModal" aria-controls="createMeetingModal" aria-haspopup="dialog" data-meeting-create><i class="bi bi-plus-lg" aria-hidden="true"></i> นัดประชุม</button>@endif
    </header>

    <form class="meetings-page__filters" method="GET" action="{{ $meetingFormAction }}">
        @foreach($meetingBaseQuery as $queryKey => $queryValue)
            <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
        @endforeach
        <label class="meetings-page__search"><span>ค้นหา</span><span><i class="bi bi-search" aria-hidden="true"></i><input type="search" name="search" value="{{ $filters['search'] }}" placeholder="ชื่อ รายละเอียด หรือสถานที่"></span></label>
        <label><span>ช่วงเวลา</span><select name="period">@foreach($periodOptions as $value => $label)<option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>@endforeach</select></label>
        @if($employeeOptions->isNotEmpty())<label><span>พนักงาน</span><select name="employee"><option value="">พนักงานทั้งหมด</option>@foreach($employeeOptions as $employee)<option value="{{ $employee->id }}" @selected($filters['employee_id'] === $employee->id)>{{ $employee->name }} · {{ $employee->department?->department_name ?? 'ไม่ระบุแผนก' }}</option>@endforeach</select></label>@endif
        <button class="meetings-page__button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> แสดงผล</button>
    </form>

    <div class="meetings-page__summary"><span><strong>{{ $meetings->total() }}</strong> การประชุม</span><span>{{ $periodOptions[$filters['period']] }}</span>@if($filters['search'] !== '' || $filters['employee_id'])<a href="{{ $meetingClearUrl }}">ล้างตัวกรอง</a>@endif</div>

    <section class="meetings-page__list" aria-label="รายการการประชุม">
        @forelse($meetings as $meeting)
            @include('meetings.components.meeting-card', compact('meeting', 'nowBangkok', 'inspectedEmployee'))
        @empty
            <div class="meetings-page__empty"><i class="bi bi-calendar2-x" aria-hidden="true"></i><h2>{{ $inspectedEmployee ? $inspectedEmployee->name.' ไม่มีการประชุมในช่วงเวลานี้' : 'ไม่พบการประชุมในช่วงเวลานี้' }}</h2><p>ลองเปลี่ยนคำค้นหาหรือช่วงเวลา@if($meetingCanCreate) หรือสร้างนัดหมายใหม่จากปุ่ม “นัดประชุม” ด้านบน@endif</p></div>
        @endforelse
    </section>

    @if($meetings->hasPages())<div class="meetings-page__pagination">{{ $meetings->links('pagination::bootstrap-5') }}</div>@endif
    @if($meetingCanCreate) @include('meetings.components.form-modal', ['formMeeting' => null, 'attendeeOptions' => $attendeeOptions, 'attendeeDepartments' => $attendeeDepartments]) @endif
    <script type="application/json" data-meeting-feedback>@json($meetingFeedback)</script>
</div>
