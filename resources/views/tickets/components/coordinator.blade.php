{{--
    ผู้ประสานงานหลักของ Ticket — เลือกได้เฉพาะผู้เข้าร่วมปัจจุบันที่ยังเป็น agent ($coordinatorCandidates จาก TicketController::show)
    ผู้เรียกตรวจเงื่อนไขแล้ว ($can['setCoordinator'] และมีผู้ถูกเลือกได้) ส่งผ่าน TicketParticipantController::coordinator ตามเดิม
--}}
<section class="ticket-detail__coordinator panel ticket-panel" aria-labelledby="ticket-coordinator-title">
    <h2 class="ticket-panel__title" id="ticket-coordinator-title">ผู้ประสานงานหลัก</h2>

    <form class="ticket-coordinator" method="POST" action="{{ route('tickets.coordinator.update', $ticket) }}" data-ticket-action-form>
        @csrf
        @method('PATCH')
        <label class="visually-hidden" for="ticket-coordinator">เลือกผู้ประสานงานหลัก (ไม่บังคับ)</label>
        <div class="ticket-coordinator__row">
            <select id="ticket-coordinator" name="coordinator_id" class="form-select @error('coordinator_id') is-invalid @enderror">
                <option value="">ไม่กำหนด</option>
                @foreach ($coordinatorCandidates as $candidate)
                    <option value="{{ $candidate->user_id }}" @selected((int) $ticket->coordinator_id === (int) $candidate->user_id)>{{ $candidate->user_name }}</option>
                @endforeach
            </select>
            <button type="submit" class="ticket-button ticket-button--outline">บันทึก</button>
        </div>
        @error('coordinator_id')
            <p class="ticket-form__error">{{ $message }}</p>
        @enderror
    </form>
</section>
