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
                        <div class="col-md-6"><label class="form-label" for="{{ $modalId }}Start">เริ่มประชุม <span aria-hidden="true">*</span></label><input class="form-control" type="datetime-local" id="{{ $modalId }}Start" name="starts_at" required value="{{ $startValue }}"></div>
                        <div class="col-md-6"><label class="form-label" for="{{ $modalId }}End">สิ้นสุด <span aria-hidden="true">*</span></label><input class="form-control" type="datetime-local" id="{{ $modalId }}End" name="ends_at" required value="{{ $endValue }}"></div>
                        <div class="col-12"><label class="form-label" for="{{ $modalId }}Location">สถานที่</label><input class="form-control" id="{{ $modalId }}Location" name="location" maxlength="255" placeholder="เช่น ห้องประชุมชั้น 2 หรือ ออนไลน์" value="{{ old('location', $formMeeting?->location ?? '') }}"></div>
                        <div class="col-12 meeting-attendee-field">
                            <div class="meeting-attendee-field__head"><span class="form-label" id="{{ $modalId }}AttendeesLabel">ผู้เข้าร่วม</span><span>คลิกเลือกได้หลายคน</span></div>
                            <div class="meeting-attendee-selector">
                                <div class="meeting-attendee-selector__browser">
                                    <label class="meeting-attendee-search"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">ค้นหาผู้เข้าร่วม</span><input type="search" placeholder="ค้นหาชื่อ แผนก หรือสิทธิ์" aria-label="ค้นหาผู้เข้าร่วม" data-meeting-attendee-search></label>
                                    <div class="meeting-attendee-departments" role="group" aria-label="กรองผู้เข้าร่วมตามแผนก">
                                        <button class="meeting-attendee-department is-active" type="button" data-meeting-department-filter data-department-id="" aria-pressed="true">ทั้งหมด</button>
                                        @foreach($attendeeDepartments as $department)
                                            <button class="meeting-attendee-department" type="button" data-meeting-department-filter data-department-id="{{ $department->id }}" aria-pressed="false">{{ $department->department_name }}</button>
                                        @endforeach
                                    </div>
                                    <div class="meeting-attendee-options" role="group" aria-labelledby="{{ $modalId }}AttendeesLabel" data-meeting-attendee-options>
                                        @foreach($attendeeOptions as $person)
                                            @php($isSelected = $selectedAttendees->contains($person->id))
                                            <label @class(['meeting-attendee-option', 'is-selected' => $isSelected]) data-meeting-attendee-option data-attendee-id="{{ $person->id }}" data-department-id="{{ $person->department_id ?? '' }}" data-search="{{ Str::lower($person->name.' '.($person->department?->department_name ?? '').' '.$person->role) }}">
                                                <input class="form-check-input" type="checkbox" id="{{ $modalId }}Attendee{{ $person->id }}" name="attendees[]" value="{{ $person->id }}" data-meeting-attendee-checkbox data-attendee-name="{{ $person->name }}" @checked($isSelected)>
                                                <span><strong>{{ $person->name }}</strong><small>{{ $person->department?->department_name ?? $person->role }}</small></span>
                                            </label>
                                        @endforeach
                                        <p class="meeting-attendee-options__empty" data-meeting-attendee-empty @if($attendeeOptions->isNotEmpty()) hidden @endif>ไม่พบผู้เข้าร่วมที่ตรงกับตัวกรอง</p>
                                    </div>
                                </div>
                                <div class="meeting-attendee-selected">
                                    <div class="meeting-attendee-selected__head"><strong data-meeting-selected-count aria-live="polite">เลือกแล้ว {{ $selectedAttendees->count() }} คน</strong><span>กด × เพื่อนำออก</span></div>
                                    <div class="meeting-attendee-selected__list" data-meeting-selected-attendees aria-live="polite">
                                        @foreach($attendeeOptions->whereIn('id', $selectedAttendees) as $person)
                                            <span class="meeting-attendee-chip" data-meeting-selected-chip data-attendee-id="{{ $person->id }}"><span>{{ $person->name }}</span><button type="button" data-meeting-remove-attendee data-attendee-id="{{ $person->id }}" aria-label="นำ {{ $person->name }} ออกจากผู้เข้าร่วม"><i class="bi bi-x" aria-hidden="true"></i></button></span>
                                        @endforeach
                                        <p class="meeting-attendee-selected__empty" data-meeting-selected-empty @if($selectedAttendees->isNotEmpty()) hidden @endif>ยังไม่ได้เลือกผู้เข้าร่วม</p>
                                    </div>
                                </div>
                            </div>
                            <div class="meeting-attendee-field__help"><span>แสดงเฉพาะบัญชีที่เปิดใช้งาน</span><span>การเปลี่ยนแผนกไม่ยกเลิกคนที่เลือกไว้</span></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="meetings-page__button" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="meetings-page__button meetings-page__button--primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ $isEdit ? 'บันทึกการแก้ไข' : 'นัดประชุม' }}</button></div>
            </form>
        </div>
    </div>
</div>
