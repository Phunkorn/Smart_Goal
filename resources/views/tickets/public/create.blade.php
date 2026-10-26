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
      1. ข้อมูลและปัญหา (ชื่อผู้แจ้ง สาขา รายละเอียดปัญหา)
      2. รายละเอียดเพิ่มเติม (เบอร์โทร 10 หลัก — บังคับ · รหัส AnyDesk/รีโมท และรูปภาพ — ไม่บังคับ)
    ยืนยันตัวด้วย LINE แล้ว ($lineName ไม่เป็น null): ชื่อผู้แจ้งตั้งต้นจากชื่อใน LINE
    ไม่มีแถบบอกว่าเข้าสู่ระบบด้วยบัญชีใด — ผู้แจ้งเข้ามาจาก Rich Menu ในแอป LINE จึงเป็นบัญชีตัวเองเสมอ ไม่ต้องย้ำ
    พฤติกรรมขั้นตอน: tickets/stepper.js · สาขา/รูป/กันกดซ้ำ: tickets/form.js (โหลดผ่าน tickets/public.js)
    สถานะเริ่มต้นใน HTML ตรงกับขั้นที่ 1 (ขั้นที่ 2 ปุ่มย้อนกลับ และปุ่มส่งซ่อนอยู่) เพื่อไม่ให้หน้ากระพริบก่อน JavaScript ทำงาน
    ต้องใช้ JavaScript กรอกฟอร์มนี้ — ไม่มี JavaScript จะเห็นข้อความแจ้งใน <noscript>
--}}
<section class="ticket-public__card">
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

            @include('tickets.components.location-field', ['locations' => $locations, 'fieldIcons' => true])

            {{-- รายละเอียดปัญหาอยู่ขั้นที่ 1 ต่อจากชื่อและสาขา (รูปภาพอยู่ขั้นที่ 2) --}}
            @include('tickets.components.problem-fields', ['fieldIcons' => true, 'withPhotos' => false])
        </section>

        <section class="ticket-step" data-ticket-step="2" aria-labelledby="ticket-step-2-title" hidden>
            <h2 class="ticket-step__title visually-hidden" id="ticket-step-2-title" tabindex="-1">ขั้นที่ 2 รายละเอียดเพิ่มเติม</h2>

            {{--
                เบอร์โทร: ตัวเลข 10 หลักเท่านั้น — tickets/form.js ตัดอักขระที่ไม่ใช่ตัวเลขแล้วจำกัด 10 หลัก (data-ticket-digits)
                ไม่ใช้ maxlength เพราะเบราว์เซอร์ตัดข้อความที่วางก่อนตัดขีด ("081-234-5678" จะเหลือเลขไม่ครบ) · server ตัดซ้ำและตรวจรูปแบบ
            --}}
            <div class="ticket-form__field">
                <label class="ticket-form__label ticket-form__label--icon" for="ticket-contact">
                    <span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-telephone-fill"></i></span>
                    <span>เบอร์โทรติดต่อกลับ <span class="ticket-form__required" aria-hidden="true">*</span></span>
                </label>
                <input id="ticket-contact" type="tel" name="contact" inputmode="numeric"
                    class="form-control @error('contact') is-invalid @enderror"
                    value="{{ old('contact') }}" required autocomplete="tel"
                    pattern="0[0-9]{9}" data-ticket-digits="10"
                    data-pattern-message="เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0"
                    placeholder="0812345678"
                    aria-describedby="ticket-contact-hint @error('contact') ticket-contact-error @enderror">
                <p class="ticket-form__hint" id="ticket-contact-hint">ตัวเลข 10 หลัก ไม่ต้องใส่ขีด</p>
                @error('contact')
                    <p class="ticket-form__error" id="ticket-contact-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- ปุ่ม ? อยู่ข้างป้าย (ไม่อยู่ใน <label> — ปุ่มในป้ายทำให้การคลิกกำกวม) เปิด dialog วิธีหาเลข AnyDesk --}}
            <div class="ticket-form__field">
                <div class="ticket-form__label-row">
                    <label class="ticket-form__label ticket-form__label--icon" for="ticket-remote">
                        <span class="ticket-form__icon" aria-hidden="true"><i class="bi bi-display"></i></span>
                        <span>AnyDesk / รหัสรีโมท <span class="ticket-form__optional">(ไม่บังคับ)</span></span>
                    </label>
                    <button type="button" class="ticket-help-trigger" aria-haspopup="dialog" aria-controls="ticket-anydesk-help"
                        data-help-dialog-open="ticket-anydesk-help" aria-label="วิธีหาเลข AnyDesk" title="วิธีหาเลข AnyDesk">
                        <i class="bi bi-question-circle" aria-hidden="true"></i>
                    </button>
                </div>
                {{-- เลขเครื่องเท่านั้น (ตัวเลข เว้นวรรค ขีด) ตรงกับ TicketSubmissionService::REMOTE_ACCESS_PATTERN — ไม่รับรหัสผ่าน --}}
                <input id="ticket-remote" type="text" name="remote_access" inputmode="numeric"
                    class="form-control @error('remote_access') is-invalid @enderror"
                    value="{{ old('remote_access') }}" autocomplete="off"
                    maxlength="20" pattern="[0-9][0-9 \-]{5,19}"
                    data-pattern-message="กรอกเฉพาะเลข AnyDesk / รหัสรีโมท (ตัวเลข 6–20 หลัก) — ห้ามใส่รหัสผ่าน"
                    placeholder="เช่น 123 456 789"
                    aria-describedby="ticket-remote-hint @error('remote_access') ticket-remote-error @enderror">
                <p class="ticket-form__hint" id="ticket-remote-hint">กรอกเฉพาะเลขเครื่อง ห้ามใส่รหัสผ่าน</p>
                @error('remote_access')
                    <p class="ticket-form__error" id="ticket-remote-error">{{ $message }}</p>
                @enderror
            </div>

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

    {{--
        วิธีหาเลข AnyDesk — modal แบบ <dialog> ของเบราว์เซอร์ (หน้าสาธารณะไม่ได้โหลด Bootstrap JS)
        showModal() ให้ backdrop, กันโฟกัสหลุด และปิดด้วย Escape เอง · พฤติกรรมเปิด/ปิด/คืนโฟกัส: components/help-dialog.js
        อยู่นอก <form> ของการแจ้งปัญหา ปุ่มในนี้จึงไม่มีทางส่งฟอร์ม
    --}}
    <dialog class="ticket-help-dialog" id="ticket-anydesk-help" aria-labelledby="ticket-anydesk-help-title" data-help-dialog>
        <div class="ticket-help-dialog__head">
            {{-- โฟกัสแรกอยู่ที่หัวเรื่อง (autofocus) ไม่ใช่ปุ่มปิด — ผู้ใช้ screen reader ได้ยินชื่อ dialog ก่อน --}}
            <h2 class="ticket-help-dialog__title" id="ticket-anydesk-help-title" tabindex="-1" autofocus>วิธีหาเลข AnyDesk</h2>
            <button type="button" class="ticket-help-dialog__close" data-help-dialog-close aria-label="ปิดหน้าต่างวิธีหาเลข AnyDesk">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <ol class="ticket-help-dialog__steps">
            <li>เปิดโปรแกรม <strong>AnyDesk</strong> บนเครื่องที่มีปัญหา</li>
            <li>ดูตัวเลขสีแดงที่ช่อง <strong>Your Address</strong> ด้านบนของหน้าต่าง</li>
            <li>กรอกตัวเลขนั้นในช่องนี้ เช่น <strong class="text-nowrap">1 051 536 333</strong></li>
        </ol>
        <img class="ticket-help-dialog__image" src="{{ asset('images/'.rawurlencode('Anydesk - Example.png')) }}"
            width="1918" height="707" loading="lazy"
            alt="ตัวอย่างหน้าต่าง AnyDesk: เลขที่ต้องกรอกอยู่ในกรอบสีแดงที่ช่อง Your Address ด้านบน">
        <p class="ticket-help-dialog__note">ไม่มีโปรแกรม AnyDesk เว้นช่องนี้ว่างได้ — ทีม IT จะติดต่อกลับทางเบอร์โทร</p>
    </dialog>
</section>
@endsection
