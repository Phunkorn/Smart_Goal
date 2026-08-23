@if($meeting->attendees->isEmpty())
    <span class="meetings-page__muted">ยังไม่มีผู้เข้าร่วม</span>
@else
    <div class="meetings-page__attendees" aria-label="ผู้เข้าร่วม {{ $meeting->attendees->count() }} คน">
        @foreach($meeting->attendees->take($compact ?? 1000) as $person)
            <span class="meetings-page__attendee"><i aria-hidden="true">{{ Str::substr($person->name, 0, 1) }}</i>{{ $person->name }}</span>
        @endforeach
        @if(isset($compact) && $meeting->attendees->count() > $compact)<span class="meetings-page__attendee-more">+{{ $meeting->attendees->count() - $compact }}</span>@endif
    </div>
@endif
