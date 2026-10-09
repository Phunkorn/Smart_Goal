{{--
    ฟอร์มนัดหมาย — ใช้สร้างและแก้ไขใบเดียวกัน

    ถ้อยคำในฟอร์มทั้งหมดเป็นภาษาของนัดหมาย ไม่ยืมคำของอีก domain มาใช้
    นัดหมายเชิญผู้ร่วมได้เหมือนกัน แต่เป็นคนละ domain (ดู AppointmentAttendee)

    ใช้ data-date-picker กับ type="time" ชุดเดียวกับฟอร์มอื่นของระบบ ไม่ประกาศ
    ตัวเลือกวันที่/เวลาของตัวเอง และใช้เปลือก .meeting-form-modal เดียวกับฟอร์มประชุม
    เพื่อให้ resolveMeetingModal() เปิดได้ด้วยกลไกเดิม
--}}
@php
    $isAppointmentEdit = isset($formAppointment) && $formAppointment;
    $appointmentModalId = $isAppointmentEdit ? 'editAppointmentModal' : 'createAppointmentModal';
    $appointmentDateValue = old('appointment_date', $isAppointmentEdit ? $formAppointment->appointment_date->format('Y-m-d') : '');
    $appointmentStartValue = old('starts_at_time', $isAppointmentEdit ? substr((string) $formAppointment->starts_at_time, 0, 5) : '');
    $appointmentEndValue = old('ends_at_time', $isAppointmentEdit && $formAppointment->ends_at_time ? substr((string) $formAppointment->ends_at_time, 0, 5) : '');
    $selectedAppointmentAttendees = collect(old('attendees', $isAppointmentEdit ? $formAppointment->attendees->pluck('id')->all() : []))
        ->map(fn($id) => (int) $id)
        ->unique()
        ->values();
@endphp
<div class="modal fade meeting-form-modal" id="{{ $appointmentModalId }}" tabindex="-1"
    aria-labelledby="{{ $appointmentModalId }}Title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="meetings-page__modal-kicker">APPOINTMENT</span>
                    <h2 class="modal-title" id="{{ $appointmentModalId }}Title">
                        {{ $isAppointmentEdit ? 'แก้ไขนัดหมาย' : 'เพิ่มนัดหมาย' }}</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="POST" id="{{ $appointmentModalId }}Form"
                action="{{ $isAppointmentEdit ? route('appointments.update', $formAppointment) : route('appointments.store') }}">
                @csrf
                @if ($isAppointmentEdit)
                    @method('PATCH')
                @endif
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label" for="{{ $appointmentModalId }}Title">หัวข้อนัดหมาย
                                <span aria-hidden="true">*</span></label>
                            <input class="form-control @error('title') is-invalid @enderror"
                                id="{{ $appointmentModalId }}Title" name="title" maxlength="160" required
                                value="{{ old('title', $formAppointment->title ?? '') }}">
                            @error('title')
                                <p class="meeting-rsvp__error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="{{ $appointmentModalId }}Date">วันที่
                                <span aria-hidden="true">*</span></label>
                            <input class="form-control @error('appointment_date') is-invalid @enderror" type="date"
                                data-date-picker id="{{ $appointmentModalId }}Date" name="appointment_date" required
                                value="{{ $appointmentDateValue }}">
                            @error('appointment_date')
                                <p class="meeting-rsvp__error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="{{ $appointmentModalId }}Start">เวลาเริ่ม
                                <span aria-hidden="true">*</span></label>
                            <input class="form-control @error('starts_at_time') is-invalid @enderror" type="time"
                                id="{{ $appointmentModalId }}Start" name="starts_at_time" required
                                value="{{ $appointmentStartValue }}">
                            @error('starts_at_time')
                                <p class="meeting-rsvp__error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="{{ $appointmentModalId }}End">เวลาสิ้นสุด</label>
                            <input class="form-control @error('ends_at_time') is-invalid @enderror" type="time"
                                id="{{ $appointmentModalId }}End" name="ends_at_time"
                                value="{{ $appointmentEndValue }}">
                            @error('ends_at_time')
                                <p class="meeting-rsvp__error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="{{ $appointmentModalId }}Location">สถานที่</label>
                            <input class="form-control" id="{{ $appointmentModalId }}Location" name="location"
                                maxlength="255" placeholder="เช่น โรงพยาบาล หรือ ออนไลน์"
                                value="{{ old('location', $formAppointment->location ?? '') }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="{{ $appointmentModalId }}MapUrl">ลิงก์แผนที่ (Google
                                Maps)</label>
                            <input class="form-control @error('map_url') is-invalid @enderror" type="url"
                                id="{{ $appointmentModalId }}MapUrl" name="map_url" maxlength="2048"
                                placeholder="วางลิงก์จากปุ่ม แชร์ ใน Google Maps"
                                value="{{ old('map_url', $formAppointment->map_url ?? '') }}">
                            @error('map_url')
                                <p class="meeting-rsvp__error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="{{ $appointmentModalId }}Description">รายละเอียด</label>
                            <textarea class="form-control" id="{{ $appointmentModalId }}Description" name="description"
                                rows="3" maxlength="5000">{{ old('description', $formAppointment->description ?? '') }}</textarea>
                        </div>
                        <div class="col-12">
                            {{--
                                ขอบเขตการมองเห็น — ตัวเดียวที่ตัดสินว่าใครเห็นนัดหมายใบนี้

                                ปฏิทินเป็นปฏิทินระดับแผนก แต่ "นัดหมาย" เคยเป็นข้อมูลส่วนตัว
                                ทั้งหมด จึงต้องให้ผู้สร้างเลือกเองทุกครั้ง ไม่ใช่เปิดให้แผนก
                                โดยอัตโนมัติ (ดู AppointmentPolicy และ migration ที่เพิ่มคอลัมน์นี้)

                                ของใหม่ตั้งต้นที่ "แผนก" เพราะปฏิทินแผนกคือจุดประสงค์ของหน้านี้
                                ส่วนการแก้ไขของเดิมต้องคงค่าที่บันทึกไว้ ห้ามเปลี่ยนให้เอง
                            --}}
                            @php
                                $appointmentVisibilityValue = old(
                                    'visibility',
                                    $isAppointmentEdit
                                        ? $formAppointment->visibility
                                        : \App\Models\Appointment::VISIBILITY_DEPARTMENT
                                );
                                $appointmentVisibilityHints = [
                                    \App\Models\Appointment::VISIBILITY_PERSONAL => 'เห็นได้เฉพาะคุณและผู้ที่คุณเชิญ',
                                    \App\Models\Appointment::VISIBILITY_DEPARTMENT => 'ขึ้นบนปฏิทินให้ทุกคนในแผนกของคุณเห็น',
                                ];
                            @endphp
                            <fieldset class="meeting-form-modal__visibility">
                                <legend class="form-label">ใครเห็นนัดหมายนี้ <span aria-hidden="true">*</span></legend>
                                @foreach (\App\Models\Appointment::VISIBILITY_LABELS as $visibilityValue => $visibilityLabel)
                                    <label class="meeting-form-modal__visibility-option">
                                        <input type="radio" name="visibility" value="{{ $visibilityValue }}"
                                            @checked($appointmentVisibilityValue === $visibilityValue)>
                                        <span>
                                            <strong>{{ $visibilityLabel }}</strong>
                                            <small>{{ $appointmentVisibilityHints[$visibilityValue] }}</small>
                                        </span>
                                    </label>
                                @endforeach
                                @error('visibility')
                                    <p class="meeting-rsvp__error">{{ $message }}</p>
                                @enderror
                            </fieldset>
                        </div>
                        <div class="col-12">
                            {{-- ผู้ร่วมถูกเลือกใน modal แยก เปิดจากปุ่มนี้ เหมือนฟอร์มประชุม --}}
                            <div class="meeting-form-modal__attendees"
                                data-meeting-attendees-field="{{ $appointmentModalId }}Attendees">
                                <div class="meeting-form-modal__attendees-head">
                                    <strong>ผู้ร่วมนัดหมาย</strong>
                                    <span
                                        class="meeting-form-modal__attendees-count">{{ $selectedAppointmentAttendees->isNotEmpty() ? 'เลือกแล้ว '.$selectedAppointmentAttendees->count().' คน' : 'ยังไม่ได้เลือกผู้ร่วมนัดหมาย' }}</span>
                                </div>
                                <button type="button"
                                    class="meetings-page__button meeting-form-modal__attendees-trigger"><i
                                        class="bi bi-person-plus" aria-hidden="true"></i> เพิ่มผู้ร่วมนัดหมาย</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="meetings-page__button" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="meetings-page__button meetings-page__button--primary">
                        <i class="bi bi-check2" aria-hidden="true"></i>
                        {{ $isAppointmentEdit ? 'บันทึกการแก้ไข' : 'บันทึกนัดหมาย' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{--
    ตัวเลือกผู้ร่วมนัดหมาย — อยู่นอก modal หลักจริง ๆ ไม่ใช่ซ่อนไว้ข้างใน
    (เหตุผลเดียวกับฟอร์มประชุม: ลูกของ modal ที่ถูก display:none มองไม่เห็นไปด้วย)
    checkbox ผูกกลับฟอร์มหลักด้วย attribute form="..." เพื่อให้ attendees[] ส่งไปพร้อมกัน
--}}
<div class="modal fade meeting-form-modal meeting-attendees-modal" id="{{ $appointmentModalId }}Attendees"
    tabindex="-1" aria-labelledby="{{ $appointmentModalId }}AttendeesTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="meetings-page__modal-kicker">APPOINTMENT</span>
                    <h2 class="modal-title" id="{{ $appointmentModalId }}AttendeesTitle">เพิ่มผู้ร่วมนัดหมาย</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                @include('components.people-selector', [
                    'instanceId' => $appointmentModalId,
                    'inputName' => 'attendees[]',
                    'formId' => $appointmentModalId.'Form',
                    'people' => $attendeeOptions ?? collect(),
                    'departments' => $attendeeDepartments ?? collect(),
                    'selectedIds' => $selectedAppointmentAttendees,
                    'showAvatar' => true,
                    'labels' => [
                        'title' => 'ผู้ร่วมนัดหมาย',
                        'search' => 'ค้นหาชื่อ แผนก หรือสิทธิ์',
                        'emptyOptions' => 'ไม่พบรายชื่อที่ตรงกับตัวกรอง',
                        'emptySelected' => 'ยังไม่ได้เลือกผู้ร่วมนัดหมาย',
                    ],
                ])
            </div>
            <div class="modal-footer"><button type="button"
                    class="meetings-page__button meetings-page__button--primary" data-bs-dismiss="modal"><i
                        class="bi bi-check2" aria-hidden="true"></i> เสร็จสิ้น</button></div>
        </div>
    </div>
</div>
