{{--
    Modal แจ้งปัญหา IT แบบ 3 ขั้น — ฟอร์มจริงตัวเดียวส่งไป tickets.store (TicketController::store) ตามเดิม
      1. ข้อมูลผู้แจ้ง (จากบัญชี อ่านอย่างเดียว)   2. สาขา/สถานที่   3. รายละเอียดและรูปภาพ
    ช่องกรอกมาจาก partial ชุดเดียวกับฟอร์มหน้าเดียว (/tickets/create) และหน้าสาธารณะ

    พฤติกรรมอยู่ใน tickets/stepper.js (ขั้นตอน, เปิดค้างหลังส่งไม่ผ่าน, ปุ่มย้อนกลับของเบราว์เซอร์)
    และ tickets/form.js (สาขา "อื่น ๆ", รูปภาพ, กันกดส่งซ้ำ) — โหลดผ่าน tickets/index.js
    ไม่มี JavaScript: ปุ่มเปิดเป็นลิงก์ไป /tickets/create ซึ่งเป็นฟอร์มเดียวกันแบบหน้าเดียว

    พารามิเตอร์: $requester, $locations, $submissionKey
    $errors ไม่ว่างหลังส่งไม่ผ่าน → data-open-on-load ให้ stepper เปิด modal ที่ขั้นแรกที่มีข้อผิดพลาด
--}}
@php
    // ตัวแปรของ loop ต้องไม่ชื่อ $label — partial ที่ include ต่อ (photo-picker, submit-button) รับ $label เป็นพารามิเตอร์
    // และ Blade ส่งตัวแปรของหน้าแม่ลงไปให้ partial ทุกตัว
    $steps = [1 => 'ข้อมูลผู้แจ้ง', 2 => 'สาขา/สถานที่', 3 => 'รายละเอียดปัญหา'];
@endphp
<div class="modal fade ticket-submit-modal" id="ticket-submit-modal" tabindex="-1"
    aria-labelledby="ticket-submit-title" aria-hidden="true"
    data-ticket-submit-modal @if ($errors->any()) data-open-on-load @endif>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down ticket-submit-modal__dialog">
        <form class="modal-content ticket-form ticket-submit-modal__content" method="POST" action="{{ route('tickets.store') }}"
            enctype="multipart/form-data" data-ticket-form data-ticket-stepper>
            @csrf
            <input type="hidden" name="submission_key" value="{{ $submissionKey }}">

            <header class="modal-header ticket-submit-modal__header">
                <div class="ticket-submit-modal__heading">
                    <span class="ticket-submit-modal__eyebrow">Smart Ticket</span>
                    <h2 class="modal-title" id="ticket-submit-title">แจ้งปัญหา IT</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดหน้าต่างแจ้งปัญหา"></button>
            </header>

            <ol class="ticket-stepper" aria-label="ขั้นตอนการแจ้งปัญหา">
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

            <div class="modal-body ticket-submit-modal__body">
                <p class="visually-hidden" aria-live="polite" data-ticket-step-status>ขั้นที่ 1 จาก 3: {{ $steps[1] }}</p>

                @include('tickets.components.form-alert')

                {{--
                    หัวของแต่ละขั้นเป็นคำถามสั้น ๆ ไม่ซ้ำกับชื่อขั้นในแถบด้านบน · "ขั้นที่ N จาก 3" ซ่อนจาก screen reader
                    เพราะ data-ticket-step-status ประกาศให้แล้ว
                --}}
                <section class="ticket-step" data-ticket-step="1" aria-labelledby="ticket-step-1-title">
                    <div class="ticket-step__head">
                        <span class="ticket-step__count" aria-hidden="true">ขั้นที่ 1 จาก 3</span>
                        <h3 class="ticket-step__title" id="ticket-step-1-title" tabindex="-1">ตรวจสอบข้อมูลผู้แจ้ง</h3>
                        <p class="ticket-step__hint">ข้อมูลจากบัญชีของคุณ ทีม IT ใช้ติดต่อกลับและแจ้งความคืบหน้า</p>
                    </div>
                    @include('tickets.components.requester-summary')
                </section>

                <section class="ticket-step" data-ticket-step="2" aria-labelledby="ticket-step-2-title" hidden>
                    <div class="ticket-step__head">
                        <span class="ticket-step__count" aria-hidden="true">ขั้นที่ 2 จาก 3</span>
                        <h3 class="ticket-step__title" id="ticket-step-2-title" tabindex="-1">ปัญหาเกิดที่ไหน</h3>
                        <p class="ticket-step__hint">ทีม IT จะได้ไปถูกจุด</p>
                    </div>
                    @include('tickets.components.location-field', ['locations' => $locations])
                </section>

                <section class="ticket-step" data-ticket-step="3" aria-labelledby="ticket-step-3-title" hidden>
                    <div class="ticket-step__head">
                        <span class="ticket-step__count" aria-hidden="true">ขั้นที่ 3 จาก 3</span>
                        <h3 class="ticket-step__title" id="ticket-step-3-title" tabindex="-1">เกิดปัญหาอะไร</h3>
                    </div>
                    @include('tickets.components.problem-fields')
                </section>
            </div>

            <footer class="modal-footer ticket-submit-modal__footer">
                <button type="button" class="ticket-button ticket-button--ghost" data-ticket-step-back hidden>
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> ย้อนกลับ
                </button>
                <button type="button" class="ticket-button ticket-button--primary" data-ticket-step-next>
                    ถัดไป <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </button>
                <div class="ticket-submit-modal__submit" data-ticket-step-submit hidden>
                    @include('tickets.components.submit-button', ['busyLabel' => 'กำลังส่งคำขอ...'])
                </div>
            </footer>
        </form>
    </div>
</div>
