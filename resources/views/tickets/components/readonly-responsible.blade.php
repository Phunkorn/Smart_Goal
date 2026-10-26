{{--
    "ผู้รับผิดชอบ" ใน modal อ่านอย่างเดียวของผู้แจ้ง — เจ้าหน้าที่ที่กำลังดูแล Ticket นี้ (ผู้เข้าร่วมปัจจุบัน)
    ผู้ประสานงานขึ้นก่อนพร้อมป้าย · แสดงเฉพาะรูปโปรไฟล์ ชื่อ และแผนก ไม่แสดงเวลาที่เข้าร่วม ช่องทางติดต่อ หรือผู้ที่ออกไปแล้ว

    รูปโปรไฟล์ผ่าน media.profile (ต้องล็อกอิน) ตัวย่อชื่อเรนเดอร์ไว้ข้างล่างเสมอเป็นเนื้อหาสำรอง
    ถ้าไม่มีรูปหรือรูปโหลดไม่ขึ้น components/avatar-fallback.js ถอด <img> ออกแล้วตัวย่อโผล่แทน (เหมือน work-board/partials/avatar)

    พารามิเตอร์: $ticket (โหลด activeParticipants.user.department แล้ว — TicketController::loadReadonly), $titleId
--}}
@php
    use App\Support\TicketDesign;

    $responsible = $ticket->activeParticipants
        ->sortBy([
            fn ($a, $b) => ((int) ($b->user_id !== null && (int) $ticket->coordinator_id === (int) $b->user_id))
                <=> ((int) ($a->user_id !== null && (int) $ticket->coordinator_id === (int) $a->user_id)),
        ])
        ->values();
@endphp
<section class="ticket-detail__people panel ticket-panel" aria-labelledby="{{ $titleId }}-responsible">
    <h3 class="ticket-panel__title" id="{{ $titleId }}-responsible">ผู้รับผิดชอบ</h3>

    @if ($responsible->isEmpty())
        <p class="ticket-panel__empty">ยังไม่มีเจ้าหน้าที่รับผิดชอบ</p>
    @else
        <ul class="ticket-people">
            @foreach ($responsible as $participant)
                @php
                    $person = $participant->user;
                    $isCoordinator = $participant->user_id !== null && (int) $ticket->coordinator_id === (int) $participant->user_id;
                @endphp
                <li class="ticket-people__item">
                    <span class="ticket-people__avatar">
                        <span aria-hidden="true">{{ TicketDesign::initial($participant->user_name) }}</span>
                        @if ($person && ! $person->trashed() && $person->profile_image)
                            <img src="{{ route('media.profile', $person) }}" alt="" data-avatar-image>
                        @endif
                    </span>
                    <span class="ticket-people__identity">
                        <strong>{{ $participant->user_name }}</strong>
                        <small>{{ $person?->department?->department_name ?? '—' }}</small>
                    </span>
                    @if ($isCoordinator)
                        <span class="ticket-people__badges">
                            <span class="badge-soft accent"><i class="bi bi-star-fill" aria-hidden="true"></i> ผู้ประสานงาน</span>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
