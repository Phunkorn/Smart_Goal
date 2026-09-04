@php
    $isEdit = $formMeeting instanceof \App\Models\Meeting;
    $modalId = $isEdit ? 'editMeetingModal' : 'createMeetingModal';
    $selectedAttendees = collect(old('attendees', $isEdit ? $formMeeting->attendees->pluck('id')->all() : []))->map(fn ($id) => (int) $id)->unique()->values();
    $startValue = old('starts_at', $isEdit ? $formMeeting->starts_at?->timezone('Asia/Bangkok')->format('Y-m-d\TH:i') : '');
    $endValue = old('ends_at', $isEdit ? $formMeeting->ends_at?->timezone('Asia/Bangkok')->format('Y-m-d\TH:i') : '');
@endphp

<div class="modal fade meeting-form-modal" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="meetings-page__modal-kicker">MEETING</span><h2 class="modal-title" id="{{ $modalId }}Title">{{ $isEdit ? 'แก้ไขการประชุม' : 'นัดประชุม' }}</h2></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="POST" action="{{ $isEdit ? route('meetings.update', $formMeeting) : route('meetings.store') }}" data-meeting-form>
                @csrf
                @if($isEdit) @method('PATCH') @endif
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12"><label class="form-label" for="{{ $modalId }}TitleInput">ชื่อการประชุม <span aria-hidden="true">*</span></label><input class="form-control" id="{{ $modalId }}TitleInput" name="title" maxlength="255" required value="{{ old('title', $formMeeting?->title ?? '') }}"></div>
                        <div class="col-12"><label class="form-label" for="{{ $modalId }}Description">รายละเอียด</label><textarea class="form-control" id="{{ $modalId }}Description" name="description" rows="2" maxlength="5000">{{ old('description', $formMeeting?->description ?? '') }}</textarea></div>
                        <div class="col-md-6"><label class="form-label" for="{{ $modalId }}Start">เริ่มประชุม <span aria-hidden="true">*</span></label><input class="form-control" type="datetime-local" data-date-picker id="{{ $modalId }}Start" name="starts_at" required value="{{ $startValue }}"></div>
                        <div class="col-md-6"><label class="form-label" for="{{ $modalId }}End">สิ้นสุด <span aria-hidden="true">*</span></label><input class="form-control" type="datetime-local" data-date-picker id="{{ $modalId }}End" name="ends_at" required value="{{ $endValue }}"></div>
                        <div class="col-12"><label class="form-label" for="{{ $modalId }}Location">สถานที่</label><input class="form-control" id="{{ $modalId }}Location" name="location" maxlength="255" placeholder="เช่น ห้องประชุมชั้น 2 หรือ ออนไลน์" value="{{ old('location', $formMeeting?->location ?? '') }}"></div>
                        <div class="col-12">
                    @include('components.people-selector', [
                        'instanceId' => $modalId,
                        'inputName' => 'attendees[]',
                        'people' => $attendeeOptions,
                        'departments' => $attendeeDepartments,
                        'selectedIds' => $selectedAttendees,
                        'labels' => [
                            'title' => 'ผู้เข้าร่วม',
                            'search' => 'ค้นหาชื่อ แผนก หรือสิทธิ์',
                            'emptyOptions' => 'ไม่พบผู้เข้าร่วมที่ตรงกับตัวกรอง',
                            'emptySelected' => 'ยังไม่ได้เลือกผู้เข้าร่วม',
                        ],
                    ])
                </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="meetings-page__button" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="meetings-page__button meetings-page__button--primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ $isEdit ? 'บันทึกการแก้ไข' : 'นัดประชุม' }}</button></div>
            </form>
        </div>
    </div>
</div>
