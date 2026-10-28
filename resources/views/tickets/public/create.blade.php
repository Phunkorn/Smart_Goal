@extends('tickets.public.layout')

@section('title', 'แจ้งปัญหา IT')

@push('scripts')
    @vite('resources/js/pages/tickets/public.js')
@endpush

@section('content')
@php
    use App\Services\TicketSubmissionService;
    use App\Support\TicketSubmissionGuard;

    // ตัวแปรของ loop ต้องไม่ชื่อ $label — partial ที่ include ต่อ (photo-picker, submit-button) รับ $label เป็นพารามิเตอร์
    $steps = [1 => 'ข้อมูลและปัญหา', 2 => 'รายละเอียดเพิ่มเติม'];
@endphp
{{--
    ฟอร์มแจ้งปัญหาแบบ 2 ขั้น — ฟอร์มจริงตัวเดียว ส่งไป support.store ตามเดิม (กดถัดไปไม่ส่งอะไร)
      1. ข้อมูลและปัญหา (ชื่อผู้แจ้ง แผนก สาขา รายละเอียดปัญหา)
      2. รายละเอียดเพิ่มเติม (เบอร์โทร 10 หลัก — บังคับ · รหัส AnyDesk/รีโมท และรูปภาพ — ไม่บังคับ)
    ยืนยันตัวด้วย LINE แล้ว ($lineName ไม่เป็น null): ชื่อผู้แจ้งตั้งต้นจากชื่อใน LINE
    ไม่มีแถบบอกว่าเข้าสู่ระบบด้วยบัญชีใด — ผู้แจ้งเข้ามาจาก Rich Menu ในแอป LINE จึงเป็นบัญชีตัวเองเสมอ ไม่ต้องย้ำ
    ช่องแผนก เบอร์โทร และ AnyDesk เป็น partial ร่วมกับ modal แจ้งปัญหาภายใน (components/department-field, contact-fields)
    พฤติกรรมขั้นตอน: tickets/stepper.js · สาขา/รูป/กันกดซ้ำ: tickets/form.js (โหลดผ่าน tickets/public.js)
    สถานะเริ่มต้นใน HTML ตรงกับขั้นที่ 1 (ขั้นที่ 2 ปุ่มย้อนกลับ และปุ่มส่งซ่อนอยู่) เพื่อไม่ให้หน้ากระพริบก่อน JavaScript ทำงาน
    ต้องใช้ JavaScript กรอกฟอร์มนี้ — ไม่มี JavaScript จะเห็นข้อความแจ้งใน <noscript>
--}}
<section class="ticket-public__card ticket-public__card--form">
    @include('tickets.public.brand', ['asHeading' => true])

    <form class="ticket-form ticket-public__form" method="POST" action="{{ route('support.store') }}"
        enctype="multipart/form-data" data-ticket-form data-ticket-stepper>
        @csrf
        <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
        <input type="hidden" name="{{ TicketSubmissionGuard::TOKEN_FIELD }}" value="{{ $formToken }}">

        {{--
            Honeypot — มนุษย์มองไม่เห็นและไม่ถูกโฟกัสด้วยแป้น Tab ส่วนบอทที่กรอกทุกช่องจะกรอกช่องนี้
            (TicketSubmissionGuard) ซ่อนจาก screen reader ด้วย aria-hidden
        --}}
        <div class="ticket-public__trap" aria-hidden="true">
            <label for="ticket-website">เว็บไซต์</label>
            <input id="ticket-website" type="text" name="{{ TicketSubmissionGuard::HONEYPOT_FIELD }}" tabindex="-1" autocomplete="off">
        </div>

        <ol class="ticket-stepper ticket-public__stepper" aria-label="ขั้นตอนการแจ้งปัญหา">
            @foreach ($steps as $number => $stepLabel)
                <li class="ticket-stepper__step" data-ticket-step-indicator="{{ $number }}" @if ($number === 1) aria-current="step" @endif>
                    <span class="ticket-stepper__index" aria-hidden="true">
                        <span class="ticket-stepper__number">{{ $number }}</span>
                        <i class="bi bi-check-lg ticket-stepper__done"></i>
                    </span>
                    <span class="ticket-stepper__label">{{ $stepLabel }}</span>
                </li>
            @endforeach
        </ol>

        <p class="visually-hidden" aria-live="polite" data-ticket-step-status>ขั้นที่ 1 จาก 2: {{ $steps[1] }}</p>

        <noscript>
            <div class="ticket-form__alert" role="alert">
                <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                <span>ฟอร์มนี้ต้องเปิดใช้ JavaScript ในเบราว์เซอร์ จึงจะกรอกและส่งได้</span>
            </div>
        </noscript>

        @include('tickets.components.form-alert')

        <section class="ticket-step" data-ticket-step="1" aria-labelledby="ticket-step-1-title">
            <h2 class="ticket-step__title visually-hidden" id="ticket-step-1-title" tabindex="-1">ขั้นที่ 1 ข้อมูลและปัญหา</h2>

            <div class="ticket-form__field">
                <label class="ticket-form__label ticket-form__label--icon" for="ticket-requester-name">
                    <span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                    <span>ชื่อผู้แจ้ง <span class="ticket-form__required" aria-hidden="true">*</span></span>
                </label>
                <input id="ticket-requester-name" type="text" name="requester_name"
                    class="form-control @error('requester_name') is-invalid @enderror"
                    value="{{ old('requester_name', $lineName) }}" required autocomplete="name"
                    maxlength="{{ TicketSubmissionService::NAME_MAX }}"
                    placeholder="กรอกชื่อผู้แจ้ง"
                    @error('requester_name') aria-describedby="ticket-requester-name-error" @enderror>
                @error('requester_name')
                    <p class="ticket-form__error" id="ticket-requester-name-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- แผนกที่ผู้แจ้งเลือกเอง — บันทึกเป็นชื่อแผนกของ Ticket ไม่ผูกกับแผนกในระบบ (PublicTicketDepartments) --}}
            @include('tickets.components.department-field', ['fieldIcons' => true])

            @include('tickets.components.location-field', ['locations' => $locations, 'fieldIcons' => true])

            {{-- รายละเอียดปัญหาอยู่ขั้นที่ 1 ต่อจากชื่อและสาขา (รูปภาพอยู่ขั้นที่ 2) --}}
            @include('tickets.components.problem-fields', ['fieldIcons' => true, 'withPhotos' => false])
        </section>

        <section class="ticket-step" data-ticket-step="2" aria-labelledby="ticket-step-2-title" hidden>
            <h2 class="ticket-step__title visually-hidden" id="ticket-step-2-title" tabindex="-1">ขั้นที่ 2 รายละเอียดเพิ่มเติม</h2>

            @include('tickets.components.contact-fields', ['fieldIcons' => true])

            @include('tickets.components.photo-picker', ['fieldIcons' => true])
        </section>

        <div class="ticket-public__actions">
            <button type="button" class="ticket-button ticket-button--outline ticket-public__back" data-ticket-step-back hidden>
                <i class="bi bi-arrow-left" aria-hidden="true"></i> ย้อนกลับ
            </button>
            <button type="button" class="ticket-button ticket-button--primary ticket-public__next" data-ticket-step-next>
                ถัดไป <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </button>
            <div class="ticket-public__submit" data-ticket-step-submit hidden>
                @include('tickets.components.submit-button', ['icon' => 'bi-send'])
            </div>
        </div>
    </form>

    {{-- อยู่นอก <form> ของการแจ้งปัญหา ปุ่มในนี้จึงไม่มีทางส่งฟอร์ม --}}
    @include('tickets.components.anydesk-help-dialog')
</section>
@endsection
