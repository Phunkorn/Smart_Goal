{{--
    ฟอร์มเปลี่ยนสถานะที่ต้องกรอกข้อความ (ติดตาม / ปิดงาน / เปิดงานใหม่) ใน Bootstrap modal
    ส่งแบบ form ปกติไปที่ TicketStatusController — ข้อความบังคับตรวจซ้ำใน TicketWorkflowService
    expected_status คือสถานะที่หน้าจอนี้เห็น ถ้ามีคนเปลี่ยนไปก่อน server ตอบ 409 แทนการทับ

    พารามิเตอร์: $ticket, $action, $title, $noteLabel, $placeholder, $submitLabel, $tone, $withDate (bool)
--}}
@php
    use App\Http\Requests\Tickets\UpdateTicketStatusRequest;
    use App\Support\TodayWorkspace;

    $modalId ??= 'ticket-modal-'.str_replace('_', '-', $action);
    $reopen = old('action') === $action
        && (! isset($queueTicketId) || (string) old('ticket_id') === (string) $queueTicketId)
        && ($errors->has('note') || $errors->has('follow_up_date') || $errors->has('action'));
@endphp
<div class="modal fade ticket-modal" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}-title" aria-hidden="true"
    data-ticket-modal @if ($reopen) data-open-on-load="true" @endif>
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('tickets.status.update', $ticket) }}" data-ticket-action-form>
            @csrf
            @method('PATCH')
            <input type="hidden" name="action" value="{{ $action }}">
            <input type="hidden" name="expected_status" value="{{ $ticket->status }}">
            @if (isset($queueTicketId))
                <input type="hidden" name="ticket_id" value="{{ $queueTicketId }}">
            @endif

            <div class="modal-header">
                <h2 class="modal-title" id="{{ $modalId }}-title">{{ $title }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>

            <div class="modal-body ticket-form">
                <div class="ticket-form__field">
                    <label class="ticket-form__label" for="{{ $modalId }}-note">
                        {{ $noteLabel }} <span class="ticket-form__required" aria-hidden="true">*</span>
                    </label>
                    <textarea id="{{ $modalId }}-note" name="note" rows="4" class="form-control @if($reopen && $errors->has('note')) is-invalid @endif"
                        required maxlength="{{ UpdateTicketStatusRequest::NOTE_MAX }}" placeholder="{{ $placeholder }}">{{ $reopen ? old('note') : '' }}</textarea>
                    @if ($reopen && $errors->has('note'))
                        <p class="ticket-form__error">{{ $errors->first('note') }}</p>
                    @endif
                </div>

                @if ($withDate)
                    <div class="ticket-form__field">
                        <label class="ticket-form__label" for="{{ $modalId }}-date">
                            วันติดตามถัดไป <span class="ticket-form__optional">(ไม่บังคับ)</span>
                        </label>
                        <input id="{{ $modalId }}-date" type="date" name="follow_up_date" class="form-control @if($reopen && $errors->has('follow_up_date')) is-invalid @endif"
                            min="{{ TodayWorkspace::businessNow()->toDateString() }}" value="{{ $reopen ? old('follow_up_date') : '' }}">
                        @if ($reopen && $errors->has('follow_up_date'))
                            <p class="ticket-form__error">{{ $errors->first('follow_up_date') }}</p>
                        @endif
                    </div>
                @endif

                @if ($reopen && $errors->has('action'))
                    <p class="ticket-form__error">{{ $errors->first('action') }}</p>
                @endif
            </div>

            <div class="modal-footer">
                <button type="button" class="ticket-button ticket-button--ghost" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="ticket-button ticket-button--{{ $tone }}">{{ $submitLabel }}</button>
            </div>
        </form>
    </div>
</div>
