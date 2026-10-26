{{--
    ช่องสาขา/สถานที่ของฟอร์มแจ้งปัญหา — ใช้ใน form-fields (หน้าเดียว) และขั้นที่ 2 ของ submit-modal
    พารามิเตอร์: $locations — สาขาที่เปิดใช้งาน (id, name)
                $fieldIcons — (ไม่บังคับ) true = มีไอคอนวงกลมหน้าป้ายช่อง ใช้ที่หน้าสาธารณะ /support

    ไม่มีรายการสาขา = มีเพียงช่องพิมพ์เอง · มีรายการ = select + "อื่น ๆ" ที่เปิดช่องพิมพ์ (tickets/form.js)
    ช่องพิมพ์ถูก render ไว้เสมอ ถ้า JavaScript ใช้ไม่ได้ ผู้ใช้ยังกรอกได้ตามปกติ
--}}
@php
    use App\Http\Requests\Tickets\TicketSubmissionRequest;
    use App\Services\TicketSubmissionService;

    $hasLocations = $locations->isNotEmpty();
    $oldLocation = (string) old('location_id', '');
    // หลัง validate "other" ถูกแปลงเป็น null — ถ้ามีชื่อที่พิมพ์ไว้แต่ไม่มี id แปลว่าเลือก "อื่น ๆ"
    $otherSelected = $oldLocation === TicketSubmissionRequest::LOCATION_OTHER
        || ($oldLocation === '' && filled(old('location_name')));
    $locationError = $errors->first('location_id') ?: $errors->first('location_name');
@endphp

<div class="ticket-form__field">
    @if ($hasLocations)
        <label class="ticket-form__label{{ ($fieldIcons ?? false) ? ' ticket-form__label--icon' : '' }}" for="ticket-location">
            @if ($fieldIcons ?? false)<span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-geo-alt-fill"></i></span>@endif
            <span>สาขา/สถานที่ <span class="ticket-form__required" aria-hidden="true">*</span></span>
        </label>
        <select id="ticket-location" name="location_id" class="form-select @if($locationError) is-invalid @endif"
            required data-ticket-location
            @if($locationError) aria-describedby="ticket-location-error" @endif>
            <option value="" disabled @selected($oldLocation === '' && ! $otherSelected)>เลือกสาขา/สถานที่</option>
            @foreach ($locations as $location)
                <option value="{{ $location->id }}" @selected($oldLocation === (string) $location->id)>{{ $location->name }}</option>
            @endforeach
            <option value="{{ TicketSubmissionRequest::LOCATION_OTHER }}" @selected($otherSelected)>อื่น ๆ (ระบุเอง)</option>
        </select>
    @endif

    <div class="ticket-form__other" data-ticket-location-other>
        <label class="ticket-form__label{{ $hasLocations ? ' ticket-form__label--sub' : (($fieldIcons ?? false) ? ' ticket-form__label--icon' : '') }}" for="ticket-location-name">
            @if ($hasLocations)
                ระบุสาขา/สถานที่
            @else
                @if ($fieldIcons ?? false)<span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-geo-alt-fill"></i></span>@endif
                <span>สาขา/สถานที่ <span class="ticket-form__required" aria-hidden="true">*</span></span>
            @endif
        </label>
        <input id="ticket-location-name" type="text" name="location_name" class="form-control @if($locationError) is-invalid @endif"
            value="{{ old('location_name') }}"
            maxlength="{{ TicketSubmissionService::NAME_MAX }}"
            placeholder="เช่น สาขาบางนา ชั้น 2 ห้องบัญชี"
            @if (! $hasLocations || $otherSelected) required @endif
            @if($locationError) aria-describedby="ticket-location-error" @endif>
    </div>

    @if ($locationError)
        <p class="ticket-form__error" id="ticket-location-error">{{ $locationError }}</p>
    @endif
</div>
