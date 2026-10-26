{{--
    Timeline ของ Ticket — ทุกการกระทำสำคัญ เรียงจากเก่าไปใหม่ (ticket_activities เพิ่มอย่างเดียว)
    ป้ายและไอคอนมาจาก TicketDesign::activity() ที่เดียว รูปเปิดผ่าน media route ที่ตรวจสิทธิ์
--}}
@php
    use App\Support\ThaiDate;
    use App\Support\TicketDesign;
    use Carbon\CarbonImmutable;
@endphp
<section class="ticket-detail__timeline panel ticket-panel" aria-labelledby="ticket-timeline-title">
    <h2 class="ticket-panel__title" id="ticket-timeline-title">ประวัติการดำเนินงาน</h2>

    <ol class="ticket-timeline">
        @foreach ($ticket->activities as $activity)
            @php($design = TicketDesign::activity($activity))
            <li class="ticket-timeline__item">
                <span class="ticket-timeline__icon badge-soft {{ $design['tone'] }}" aria-hidden="true"><i class="bi {{ $design['icon'] }}"></i></span>
                <div class="ticket-timeline__content">
                    <p class="ticket-timeline__title">
                        <strong>{{ $design['title'] }}</strong>
                        <span>โดย {{ $activity->user_name }}</span>
                    </p>
                    <time class="ticket-timeline__time" datetime="{{ $activity->created_at->toIso8601String() }}">{{ TicketDesign::when($activity->created_at) }}</time>

                    @if (filled($activity->body))
                        <div class="ticket-timeline__body">
                            @if ($design['body_label'])
                                <span class="ticket-timeline__body-label">{{ $design['body_label'] }}</span>
                            @endif
                            <p>{{ $activity->body }}</p>
                        </div>
                    @endif

                    @if (filled($activity->data['follow_up_date'] ?? null))
                        <p class="ticket-timeline__meta">
                            <i class="bi bi-calendar-event" aria-hidden="true"></i>
                            ติดตามอีกครั้ง {{ ThaiDate::longDay(CarbonImmutable::parse($activity->data['follow_up_date'])) }}
                        </p>
                    @endif

                    @if ($activity->attachments->isNotEmpty())
                        @include('tickets.components.photo-gallery', ['photos' => $activity->attachments])
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</section>
