{{--
    ฟอร์มสร้าง/แก้ไขประกาศ — modal ใบเดียวใช้ทั้งสองโหมด

    resources/js/pages/announcements/index.js สลับโหมดโดยเปลี่ยน action, _method
    และค่าในช่อง ถ้า server ตีกลับด้วย validation error หน้าเว็บจะเปิดกล่องนี้อีกครั้ง
    ด้วยค่าเดิมที่ผู้ใช้กรอก (old()) ในโหมดเดิม (อ่านจาก _announcement_id)

    created_by และ department_id ไม่มีช่องในฟอร์มโดยตั้งใจ server กำหนดจากผู้ใช้เอง
--}}
@php
    $oldId = old('_announcement_id');
    $reopenEdit = $errors->any() && ctype_digit((string) $oldId);
    $reopen = $errors->any() && ! $errors->has('status');
    $formAction = $reopenEdit ? route('announcements.update', (int) $oldId) : route('announcements.store');
    $audienceValue = old('audience', \App\Models\Announcement::AUDIENCE_DEPARTMENT);
@endphp
<div class="modal fade announcement-form-modal" id="announcementFormModal" tabindex="-1"
    aria-labelledby="announcementFormTitle" aria-hidden="true"
    data-announcement-modal
    data-store-url="{{ route('announcements.store') }}"
    data-today="{{ $today }}"
    @if($reopen) data-open-on-load="true" @endif>
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="announcementFormTitle">
                    <i class="bi bi-megaphone" aria-hidden="true"></i>
                    <span data-announcement-modal-title>{{ $reopenEdit ? 'แก้ไขประกาศ' : 'สร้างประกาศ' }}</span>
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="POST" action="{{ $formAction }}" data-announcement-form novalidate>
                @csrf
                <input type="hidden" name="_method" value="PATCH" data-announcement-method @disabled(! $reopenEdit)>
                <input type="hidden" name="_announcement_id" value="{{ $reopenEdit ? (int) $oldId : '' }}" data-announcement-id>
                <div class="modal-body">
                    @if($reopen)
                        <div class="alert alert-danger announcement-form__errors" role="alert">
                            <ul>
                                @foreach($errors->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="announcement-form__grid">
                        <div class="announcement-form__main">
                            <label class="form-label" for="announcementTitle">หัวข้อประกาศ <span class="text-danger" aria-hidden="true">*</span></label>
                            <input class="form-control @error('title') is-invalid @enderror" id="announcementTitle" name="title"
                                maxlength="{{ \App\Support\AnnouncementDesign::TITLE_MAX }}" required
                                placeholder="ระบุหัวข้อประกาศ..." value="{{ old('title') }}" data-announcement-field="title">

                            <label class="form-label" for="announcementBody">รายละเอียด <span class="text-danger" aria-hidden="true">*</span></label>
                            <textarea class="form-control @error('body') is-invalid @enderror" id="announcementBody" name="body" rows="7"
                                maxlength="{{ \App\Support\AnnouncementDesign::BODY_MAX }}" required
                                placeholder="พิมพ์รายละเอียดประกาศ..." data-announcement-field="body">{{ old('body') }}</textarea>

                            <fieldset class="announcement-form__audience">
                                <legend class="form-label">กลุ่มผู้รับประกาศ <span class="text-danger" aria-hidden="true">*</span></legend>
                                <label class="announcement-form__choice">
                                    <input class="form-check-input" type="radio" name="audience" value="{{ \App\Models\Announcement::AUDIENCE_DEPARTMENT }}"
                                        data-announcement-field="audience" @checked($audienceValue === \App\Models\Announcement::AUDIENCE_DEPARTMENT)>
                                    <span>เฉพาะแผนกของฉัน{{ $departmentName ? ' ('.$departmentName.')' : '' }}</span>
                                </label>
                                <label class="announcement-form__choice">
                                    <input class="form-check-input" type="radio" name="audience" value="{{ \App\Models\Announcement::AUDIENCE_ALL }}"
                                        data-announcement-field="audience" @checked($audienceValue === \App\Models\Announcement::AUDIENCE_ALL)>
                                    <span>ทุกแผนก</span>
                                </label>
                                <p class="announcement-form__hint">
                                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                                    หากเลือก “ทุกแผนก” ประกาศจะแสดงให้พนักงานทุกแผนก โดยแสดงชื่อ รูปโปรไฟล์ และแผนกของคุณเป็นผู้ประกาศ
                                </p>
                            </fieldset>
                        </div>

                        <div class="announcement-form__side">
                            <label class="form-label" for="announcementStartsOn">วันที่แสดงประกาศ <span class="text-danger" aria-hidden="true">*</span></label>
                            <input class="form-control @error('starts_on') is-invalid @enderror" type="date" data-date-picker
                                id="announcementStartsOn" name="starts_on" required min="{{ $today }}"
                                value="{{ old('starts_on', $today) }}" data-announcement-field="starts_on">

                            <label class="form-label" for="announcementEndsOn">วันที่สิ้นสุดการแสดง</label>
                            <div class="announcement-form__end">
                                <input class="form-control @error('ends_on') is-invalid @enderror" type="date" data-date-picker
                                    id="announcementEndsOn" name="ends_on" min="{{ old('starts_on', $today) }}"
                                    value="{{ old('ends_on') }}" data-announcement-field="ends_on">
                                <button type="button" class="announcement-form__clear" data-announcement-clear-end aria-label="ล้างวันที่สิ้นสุด">
                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="announcement-form__hint">ถ้าไม่ระบุ จะแสดงไปจนกว่าจะลบประกาศ</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-line" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn-accent" data-announcement-submit>
                        <i class="bi bi-check2" aria-hidden="true"></i>
                        <span data-announcement-submit-label>{{ $reopenEdit ? 'บันทึกการแก้ไข' : 'สร้างประกาศ' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
