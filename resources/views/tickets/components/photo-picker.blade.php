{{--
    ตัวเลือกรูปประกอบของ Smart Ticket (ไม่บังคับ) — ใช้ทั้งฟอร์มแจ้งปัญหาและฟอร์มบันทึกการดำเนินงาน
    พฤติกรรม (ตัวอย่าง ลบทีละรูป เพดาน) อยู่ที่ resources/js/pages/tickets/form.js
    เพดานจำนวนและขนาดมาจาก TicketPhotoStore / AttachmentPolicy ที่ server ใช้ตรวจจริง
    $pickerId (ไม่บังคับ ค่าเริ่มต้น ticket-photos) = คำนำหน้า id — หน้าที่มีสองตัว (บันทึก + ปิดงาน) ตั้งให้ต่างกัน
    $fieldIcons (ไม่บังคับ) = มีไอคอนวงกลมหน้าป้ายช่อง · $note (ไม่บังคับ) = ข้อความกำกับเพิ่มต่อท้ายเพดานรูป
    $showErrors (ไม่บังคับ ค่าเริ่มต้น true) = แสดงข้อผิดพลาด photos จาก server — หน้าที่มีสองฟอร์มให้เฉพาะฟอร์มที่เพิ่งส่งแสดง
    $camera (ไม่บังคับ) = มีปุ่ม "ถ่ายรูป" (input capture เปิดกล้องหลัง ไม่มี name — form.js รวมรูปเข้า input หลัก)
              ปุ่มนี้แสดงเฉพาะจอแคบ (tickets/responsive.css) และปุ่มหลักเปลี่ยนชื่อเป็น "เลือกจากคลังรูป" บนจอแคบ
--}}
@php
    use App\Services\TicketPhotoStore;
    use App\Support\AttachmentPolicy;

    $photoError = ($showErrors ?? true) ? ($errors->first('photos') ?: $errors->first('photos.*')) : null;
    $pickerId ??= 'ticket-photos';
@endphp
<div class="ticket-form__field">
    <span class="ticket-form__label{{ ($fieldIcons ?? false) ? ' ticket-form__label--icon' : '' }}" id="{{ $pickerId }}-label">
        @if ($fieldIcons ?? false)<span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-image-fill"></i></span>@endif
        <span>{{ $label ?? 'รูปภาพประกอบ' }} <span class="ticket-form__optional">(ไม่บังคับ)</span></span>
    </span>
    <div class="ticket-photos" data-ticket-photos
        data-max-files="{{ TicketPhotoStore::MAX_PHOTOS }}"
        data-max-bytes="{{ AttachmentPolicy::IMAGE_MAX_KILOBYTES * 1024 }}"
        data-accept="{{ AttachmentPolicy::imageAcceptAttribute() }}">
        <input id="{{ $pickerId }}-input" type="file" name="photos[]" multiple
            class="ticket-photos__input visually-hidden"
            accept="{{ AttachmentPolicy::imageAcceptAttribute() }}"
            aria-labelledby="{{ $pickerId }}-label"
            aria-describedby="{{ $pickerId }}-hint{{ $photoError ? ' '.$pickerId.'-error' : '' }}"
            data-ticket-photos-input>
        @if ($camera ?? false)
            <input id="{{ $pickerId }}-camera" type="file" accept="image/*" capture="environment"
                class="ticket-photos__input visually-hidden" aria-label="ถ่ายรูปด้วยกล้อง" data-ticket-photos-camera>
            <div class="ticket-photos__sources">
                <label class="ticket-photos__picker ticket-photos__picker--camera" for="{{ $pickerId }}-camera">
                    <i class="bi bi-camera" aria-hidden="true"></i>
                    <span>ถ่ายรูป</span>
                </label>
                <label class="ticket-photos__picker" for="{{ $pickerId }}-input">
                    <i class="bi bi-images" aria-hidden="true"></i>
                    <span class="ticket-photos__picker-label--wide">เพิ่มรูปภาพ</span>
                    <span class="ticket-photos__picker-label--narrow">เลือกจากคลังรูป</span>
                </label>
            </div>
        @else
            <label class="ticket-photos__picker" for="{{ $pickerId }}-input">
                <i class="bi bi-camera" aria-hidden="true"></i>
                <span>เพิ่มรูปภาพ</span>
            </label>
        @endif
        <p class="ticket-form__hint" id="{{ $pickerId }}-hint">
            สูงสุด {{ TicketPhotoStore::MAX_PHOTOS }} รูป · {{ AttachmentPolicy::imageLimitsLabel() }}@isset($note) · {{ $note }}@endisset
        </p>
        <ul class="ticket-photos__list" data-ticket-photos-list aria-live="polite"></ul>
        <p class="ticket-form__error" data-ticket-photos-error role="alert" hidden></p>
    </div>
    @if ($photoError)
        <p class="ticket-form__error" id="{{ $pickerId }}-error">{{ $photoError }} กรุณาเลือกรูปอีกครั้ง</p>
    @endif
</div>
