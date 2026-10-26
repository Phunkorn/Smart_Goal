{{--
    ปิดงานแบบขั้นตอน (เจ้าหน้าที่) — ฟอร์มจริงตัวเดียวส่ง PATCH ไป tickets.status.update (action=close) ตามเดิม
      1. ผลการแก้ไข (บังคับ — มีข้อความสำเร็จรูปให้แตะ)   2. รูปหลังแก้ไข (ไม่บังคับ)   3. ตรวจทานแล้วยืนยัน
    รูปหลังแก้ไขผูกกับ activity ของการปิดงาน จึงเห็นเฉพาะเจ้าหน้าที่ (TicketWorkflowService · MediaController viewInternal)
    ผู้แจ้งได้รับแจ้งเตือนพร้อม "ผลการแก้ไข" เหมือนเดิม

    เลย์เอาต์ modal แบบมีขั้นตอนชุดเดียวกับ modal แจ้งปัญหา (คลาส ticket-submit-modal* ใน tickets/submit-modal.css)
    มือถือเต็มจอ (modal-fullscreen-sm-down) ปุ่มเต็มความกว้างที่ท้าย modal
    พฤติกรรม: tickets/stepper.js (ขั้นตอน · #close-ticket สำหรับปุ่มย้อนกลับ · เปิดค้างเมื่อส่งไม่ผ่าน · สรุปขั้นสุดท้าย · ข้อความสำเร็จรูป)
              tickets/form.js (ตัวเลือกรูป · กันกดส่งซ้ำ) — โหลดผ่าน tickets/detail.js และ show.blade
    expected_status คือสถานะที่หน้าจอนี้เห็น ถ้ามีคนเปลี่ยนไปก่อน server ตอบ 409 แทนการทับ

    พารามิเตอร์: $ticket
--}}
@php
    use App\Http\Requests\Tickets\UpdateTicketStatusRequest;
    use App\Models\Ticket;

    $reopen = old('action') === Ticket::ACTION_CLOSE && $errors->hasAny(['note', 'action', 'photos', 'photos.*']);
    $steps = [1 => 'ผลการแก้ไข', 2 => 'รูปหลังแก้ไข', 3 => 'ตรวจทาน'];
    $quickNotes = ['แก้ไขเรียบร้อย ทดสอบใช้งานได้ปกติ', 'เปลี่ยนอุปกรณ์ใหม่แล้ว', 'ตั้งค่า/ติดตั้งโปรแกรมใหม่แล้ว', 'แนะนำวิธีใช้งานให้ผู้ใช้แล้ว'];
@endphp
<div class="modal fade ticket-modal ticket-submit-modal" id="ticket-modal-close" tabindex="-1"
    aria-labelledby="ticket-modal-close-title" aria-hidden="true"
    data-ticket-modal @if ($reopen) data-open-on-load="true" @endif>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down ticket-submit-modal__dialog">
        <form class="modal-content ticket-form ticket-submit-modal__content" method="POST" action="{{ route('tickets.status.update', $ticket) }}"
            enctype="multipart/form-data" data-ticket-form data-ticket-stepper data-ticket-stepper-hash="#close-ticket">
            @csrf
            @method('PATCH')
            <input type="hidden" name="action" value="{{ Ticket::ACTION_CLOSE }}">
            <input type="hidden" name="expected_status" value="{{ $ticket->status }}">

            <header class="modal-header ticket-submit-modal__header">
                <div class="ticket-submit-modal__heading">
                    <span class="ticket-submit-modal__eyebrow">{{ $ticket->ticket_number }}</span>
                    <h2 class="modal-title" id="ticket-modal-close-title">ปิดงาน</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิดหน้าต่างปิดงาน"></button>
            </header>

            <ol class="ticket-stepper" aria-label="ขั้นตอนการปิดงาน">
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

                <section class="ticket-step" data-ticket-step="1" aria-labelledby="ticket-close-step-1-title">
                    <div class="ticket-step__head">
                        <span class="ticket-step__count" aria-hidden="true">ขั้นที่ 1 จาก 3</span>
                        <h3 class="ticket-step__title" id="ticket-close-step-1-title" tabindex="-1">แก้ไขอะไรไปบ้าง</h3>
                        <p class="ticket-step__hint">ผู้แจ้งจะเห็นข้อความนี้ในแจ้งเตือนและในรายละเอียด Ticket</p>
                    </div>
                    <div class="ticket-form__field">
                        <label class="ticket-form__label" for="ticket-close-note">
                            ผลการแก้ไข <span class="ticket-form__required" aria-hidden="true">*</span>
                        </label>
                        <div class="ticket-quick-notes" role="group" aria-label="ข้อความสำเร็จรูป">
                            @foreach ($quickNotes as $quickNote)
                                <button type="button" class="ticket-quick-notes__chip" data-ticket-quick-note="{{ $quickNote }}">{{ $quickNote }}</button>
                            @endforeach
                        </div>
                        <textarea id="ticket-close-note" name="note" rows="4" class="form-control @if ($reopen && $errors->has('note')) is-invalid @endif"
                            required maxlength="{{ UpdateTicketStatusRequest::NOTE_MAX }}" data-ticket-quick-note-target
                            placeholder="เช่น เปลี่ยนตลับหมึกแล้ว พิมพ์ทดสอบผ่าน">{{ $reopen ? old('note') : '' }}</textarea>
                        @if ($reopen && $errors->has('note'))
                            <p class="ticket-form__error">{{ $errors->first('note') }}</p>
                        @endif
                    </div>
                </section>

                <section class="ticket-step" data-ticket-step="2" aria-labelledby="ticket-close-step-2-title" hidden>
                    <div class="ticket-step__head">
                        <span class="ticket-step__count" aria-hidden="true">ขั้นที่ 2 จาก 3</span>
                        <h3 class="ticket-step__title" id="ticket-close-step-2-title" tabindex="-1">มีรูปหลังแก้ไขไหม</h3>
                        <p class="ticket-step__hint">เช่น หน้าจอที่ใช้งานได้แล้ว หรืออุปกรณ์ที่เปลี่ยน — ข้ามได้ถ้าไม่มี</p>
                    </div>
                    @include('tickets.components.photo-picker', [
                        'pickerId' => 'ticket-close-photos', 'label' => 'รูปหลังแก้ไข', 'note' => 'เห็นเฉพาะเจ้าหน้าที่',
                        'showErrors' => $reopen, 'camera' => true,
                    ])
                </section>

                <section class="ticket-step" data-ticket-step="3" aria-labelledby="ticket-close-step-3-title" hidden>
                    <div class="ticket-step__head">
                        <span class="ticket-step__count" aria-hidden="true">ขั้นที่ 3 จาก 3</span>
                        <h3 class="ticket-step__title" id="ticket-close-step-3-title" tabindex="-1">ตรวจทานก่อนปิดงาน</h3>
                        <p class="ticket-step__hint">ปิดแล้ว Ticket จะย้ายไป "ปิดงาน" และผู้แจ้งได้รับแจ้งเตือนทันที</p>
                    </div>
                    <dl class="ticket-close-review">
                        <div><dt>ผลการแก้ไข</dt><dd data-ticket-review="note">—</dd></div>
                        <div><dt>รูปหลังแก้ไข</dt><dd data-ticket-review="photos[]">—</dd></div>
                    </dl>
                    @if ($reopen && $errors->has('action'))
                        <p class="ticket-form__error">{{ $errors->first('action') }}</p>
                    @endif
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
                    @include('tickets.components.submit-button', [
                        'label' => 'ยืนยันปิดงาน', 'icon' => 'bi-check2-circle', 'busyLabel' => 'กำลังปิดงาน...', 'tone' => 'success',
                    ])
                </div>
            </footer>
        </form>
    </div>
</div>
