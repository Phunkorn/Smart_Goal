@php
    /**
     * Quick View ของการประชุม — ใช้ shell และ visual language เดียวกับ Task (ดูอย่างเดียว)
     * เวลาแสดงตาม Asia/Bangkok เหมือนหน้าประชุมเดิม ไม่คัดลอกหน้าประชุมทั้งหน้ามาไว้ที่นี่
     */
    use App\Services\MeetingQueryService;
    use App\Support\WorkBoardDesign;

    $startsAt = $meeting->starts_at?->copy()->timezone(MeetingQueryService::BUSINESS_TIMEZONE);
    $endsAt = $meeting->ends_at?->copy()->timezone(MeetingQueryService::BUSINESS_TIMEZONE);
    $isPast = $endsAt && $endsAt->lt($nowBangkok);
    $attendees = $meeting->attendees;
@endphp

<article class="qv" data-quick-view-type="meeting" data-quick-view-title-text="{{ $meeting->title }}" data-quick-view-kicker-text="การประชุม">
    <p class="qv-summary">
        <span class="qv-summary__item qv-tone-{{ $isPast ? 'green' : 'blue' }}">
            <i class="bi {{ $isPast ? 'bi-check-circle' : 'bi-play-circle' }}" aria-hidden="true"></i>
            {{ $isPast ? 'ผ่านไปแล้ว' : 'กำลังจะมาถึง' }}
        </span>
    </p>

    <p class="qv-dates">
        <i class="bi bi-calendar3" aria-hidden="true"></i>
        <span>{{ $startsAt?->translatedFormat('j M Y') ?? 'ไม่ระบุ' }} &middot; {{ $startsAt?->format('H:i') }}&ndash;{{ $endsAt?->format('H:i') }} น.</span>
    </p>

    <p class="qv-secondary qv-secondary--single">
        <span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $meeting->location ?: 'ไม่ระบุสถานที่' }}</span>
    </p>

    <section class="qv-people">
        <div class="qv-avatar-row">
            @if($meeting->creator)
                <span class="qv-avatar" title="{{ $meeting->creator->name }}">
                    @if($meeting->creator->profile_image)
                        <img src="{{ route('media.profile', $meeting->creator) }}" alt="">
                    @else
                        {{ WorkBoardDesign::initials($meeting->creator->name) }}
                    @endif
                </span>
                <span class="qv-people__name">{{ $meeting->creator->name }} <small class="qv-muted">ผู้จัดประชุม</small></span>
            @else
                <span class="qv-people__name qv-muted">ไม่ระบุผู้จัด</span>
            @endif
        </div>

        @if($attendees->isNotEmpty())
            <div class="qv-avatar-row">
                <span class="qv-avatar-stack">
                    @foreach($attendees->take(4) as $person)
                        <span class="qv-avatar qv-avatar--sm" title="{{ $person->name }}">
                            @if($person->profile_image)
                                <img src="{{ route('media.profile', $person) }}" alt="">
                            @else
                                {{ WorkBoardDesign::initials($person->name) }}
                            @endif
                        </span>
                    @endforeach
                </span>
                <span class="qv-people__meta">ผู้เข้าร่วม {{ $attendees->count() }} คน</span>
            </div>
        @else
            <p class="qv-people__meta qv-muted">ยังไม่มีผู้เข้าร่วม</p>
        @endif
    </section>

    @if(filled($meeting->description))
        <p class="qv-secondary qv-secondary--single qv-secondary--note">{{ $meeting->description }}</p>
    @endif
</article>
