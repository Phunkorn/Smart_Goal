{{--
    เจ้าหน้าที่ที่เข้าร่วม — แสดงทุกคน ไม่มีผู้ถือ Ticket แต่เพียงผู้เดียว
    ผู้ที่ออกไปแล้วแสดงแยกใน "เคยเข้าร่วม" เพราะยังเป็นส่วนหนึ่งของงานนี้ (KPI รายคนนับด้วย)
    การเลือกผู้ประสานงานอยู่ในการ์ดของตัวเอง (components/coordinator.blade.php) — ที่นี่แสดงเพียงป้ายผู้ประสานงานและ "คุณ"
    ชื่ออ่านจาก snapshot (user_name) จึงยังแสดงได้แม้บัญชีถูกลบถาวร
--}}
@php
    use App\Support\TicketDesign;

    $active = $ticket->participants->filter->isActive();
    $former = $ticket->participants->reject->isActive();
@endphp
<section class="ticket-detail__people panel ticket-panel" aria-labelledby="ticket-people-title">
    <h2 class="ticket-panel__title" id="ticket-people-title">
        เจ้าหน้าที่ที่เข้าร่วม <span class="ticket-panel__count">{{ $active->count() }}</span>
    </h2>

    @if ($active->isEmpty())
        <p class="ticket-panel__empty">ยังไม่มีเจ้าหน้าที่รับงาน</p>
    @else
        <ul class="ticket-people">
            @foreach ($active as $participant)
                <li class="ticket-people__item">
                    <span class="ticket-people__avatar" aria-hidden="true">{{ TicketDesign::initial($participant->user_name) }}</span>
                    <span class="ticket-people__identity">
                        <strong>{{ $participant->user_name }}</strong>
                        <small>
                            {{ $participant->user?->department?->department_name ?? '—' }}
                            · เข้าร่วม {{ TicketDesign::when($participant->joined_at) }}
                        </small>
                    </span>
                    @php
                        $isCoordinator = $participant->user_id !== null && (int) $ticket->coordinator_id === (int) $participant->user_id;
                        $isMe = (int) $participant->user_id === (int) auth()->id();
                    @endphp
                    @if ($isCoordinator || $isMe)
                        <span class="ticket-people__badges">
                            @if ($isCoordinator)
                                <span class="badge-soft accent"><i class="bi bi-star-fill" aria-hidden="true"></i> ผู้ประสานงาน</span>
                            @endif
                            @if ($isMe)
                                <span class="badge-soft gray">คุณ</span>
                            @endif
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($former->isNotEmpty())
        <div class="ticket-people__former">
            <h3>เคยเข้าร่วม</h3>
            <ul>
                @foreach ($former as $participant)
                    <li>{{ $participant->user_name }} <small>· ออกเมื่อ {{ TicketDesign::when($participant->left_at) }}</small></li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
