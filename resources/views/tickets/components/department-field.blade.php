{{--
    ช่องแผนกของผู้แจ้งบนฟอร์มสาธารณะ /support (ขั้นที่ 1) — ที่เดียวของ dropdown นี้
    พารามิเตอร์: $fieldIcons — (ไม่บังคับ) true = มีไอคอนวงกลมหน้าป้ายช่อง ใช้ที่หน้าสาธารณะ

    รายการแผนกมาจาก PublicTicketDepartments::OPTIONS ที่เดียว (กติกา Rule::in ใช้ชุดเดียวกัน)
    ไม่มีตัวเลือก "อื่น ๆ" และไม่มีช่องพิมพ์เอง — ค่าที่รับได้มีเท่าที่อยู่ในรายการ
    ฟอร์มภายในไม่ใช้ช่องนี้ แผนกของผู้ใช้ภายในมาจากบัญชีที่ login (requester-summary)

    ไอคอน bi-building ตัวเดียวกับช่อง "แผนก" ในหน้ารายละเอียด (components/problem-facts)
--}}
@php
    use App\Support\PublicTicketDepartments;

    $departmentError = $errors->first('requester_department');
@endphp

<div class="ticket-form__field">
    <label class="ticket-form__label{{ ($fieldIcons ?? false) ? ' ticket-form__label--icon' : '' }}" for="ticket-department">
        @if ($fieldIcons ?? false)<span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-building"></i></span>@endif
        <span>แผนก <span class="ticket-form__required" aria-hidden="true">*</span></span>
    </label>
    <select id="ticket-department" name="requester_department" class="form-select @if($departmentError) is-invalid @endif"
        required
        @if($departmentError) aria-describedby="ticket-department-error" @endif>
        <option value="" disabled @selected(old('requester_department', '') === '')>เลือกแผนก</option>
        @foreach (PublicTicketDepartments::OPTIONS as $departmentName)
            <option value="{{ $departmentName }}" @selected(old('requester_department') === $departmentName)>{{ $departmentName }}</option>
        @endforeach
    </select>

    @if ($departmentError)
        <p class="ticket-form__error" id="ticket-department-error">{{ $departmentError }}</p>
    @endif
</div>
