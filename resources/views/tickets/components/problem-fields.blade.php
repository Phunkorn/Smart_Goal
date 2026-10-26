{{--
    รายละเอียดปัญหาและรูปภาพของฟอร์มแจ้งปัญหา — ใช้ใน form-fields (หน้าเดียว) และขั้นที่ 3 ของ submit-modal
    หน้าสาธารณะ /support ใช้เฉพาะช่องรายละเอียด (ขั้นที่ 1) แล้ว include photo-picker เองในขั้นที่ 2 — ส่ง $withPhotos = false
    $fieldIcons (ไม่บังคับ) ส่งต่อไปถึง photo-picker
--}}
@php
    use App\Http\Requests\Tickets\TicketSubmissionRequest;
@endphp

<div class="ticket-form__field">
    <label class="ticket-form__label{{ ($fieldIcons ?? false) ? ' ticket-form__label--icon' : '' }}" for="ticket-description">
        @if ($fieldIcons ?? false)<span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-file-earmark-text-fill"></i></span>@endif
        <span>รายละเอียดปัญหา <span class="ticket-form__required" aria-hidden="true">*</span></span>
    </label>
    <textarea id="ticket-description" name="description" rows="5"
        class="form-control @error('description') is-invalid @enderror"
        required
        minlength="{{ TicketSubmissionRequest::DESCRIPTION_MIN }}"
        maxlength="{{ TicketSubmissionRequest::DESCRIPTION_MAX }}"
        placeholder="เช่น เครื่องพิมพ์ชั้น 2 พิมพ์ไม่ออก มีไฟสีแดงกะพริบ"
        aria-describedby="ticket-description-hint{{ $errors->has('description') ? ' ticket-description-error' : '' }}">{{ old('description') }}</textarea>
    <p class="ticket-form__hint" id="ticket-description-hint">บอกอาการ ตำแหน่งเครื่อง และสิ่งที่ลองทำไปแล้ว</p>
    @error('description')
        <p class="ticket-form__error" id="ticket-description-error">{{ $message }}</p>
    @enderror
</div>

@if ($withPhotos ?? true)
    @include('tickets.components.photo-picker')
@endif
