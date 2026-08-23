@php
    $start = $meeting->starts_at->copy()->timezone('Asia/Bangkok');
    $end = $meeting->ends_at->copy()->timezone('Asia/Bangkok');
    $isPast = $end->lt($nowBangkok);
    $isOngoing = $start->lte($nowBangkok) && $end->gte($nowBangkok);
    $status = $isOngoing ? ['กำลังประชุม', 'green'] : ($isPast ? ['ที่ผ่านมา', 'gray'] : ['กำลังจะมาถึง', 'blue']);
    $inspectedRole = $inspectedEmployee ? ((int) $meeting->created_by === (int) $inspectedEmployee->id ? 'ผู้สร้าง' : 'ผู้เข้าร่วม') : null;
@endphp
<article class="meetings-page__card">
    <div class="meetings-page__card-date"><strong>{{ $start->format('d') }}</strong><span>{{ $start->locale('th')->isoFormat('MMM') }}</span></div>
    <div class="meetings-page__card-body">
        <div class="meetings-page__card-title"><div><span class="meetings-page__status meetings-page__status--{{ $status[1] }}">{{ $status[0] }}</span>@if($inspectedRole)<span class="meetings-page__role">{{ $inspectedRole }}</span>@endif<h2><a href="{{ route('meetings.show', ['meeting' => $meeting, 'employee' => $inspectedEmployee?->id]) }}">{{ $meeting->title }}</a></h2></div></div>
        <div class="meetings-page__meta"><span><i class="bi bi-clock" aria-hidden="true"></i>@if($start->isSameDay($end)){{ $start->locale('th')->isoFormat('D MMM YYYY') }} · {{ $start->format('H:i') }}–{{ $end->format('H:i') }}@else{{ $start->locale('th')->isoFormat('D MMM') }} {{ $start->format('H:i') }} – {{ $end->locale('th')->isoFormat('D MMM') }} {{ $end->format('H:i') }}@endif</span><span><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $meeting->location ?: 'ไม่ระบุสถานที่' }}</span><span><i class="bi bi-person" aria-hidden="true"></i>ผู้สร้าง: {{ $meeting->creator?->name ?? 'บัญชีที่ถูกลบ' }}</span></div>
        @include('meetings.components.attendee-list', ['meeting' => $meeting, 'compact' => 3])
    </div>
    <div class="meetings-page__card-actions">
        <a class="meetings-page__icon-button" href="{{ route('meetings.show', ['meeting' => $meeting, 'employee' => $inspectedEmployee?->id]) }}" aria-label="ดูรายละเอียด {{ $meeting->title }}"><i class="bi bi-eye" aria-hidden="true"></i></a>
        @can('update', $meeting)<a class="meetings-page__icon-button" href="{{ route('meetings.show', ['meeting' => $meeting, 'edit' => 1, 'employee' => $inspectedEmployee?->id]) }}" aria-label="แก้ไข {{ $meeting->title }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>@endcan
        @can('delete', $meeting)<form method="POST" action="{{ route('meetings.destroy', $meeting) }}" data-meeting-delete data-meeting-title="{{ $meeting->title }}">@csrf @method('DELETE')<button class="meetings-page__icon-button meetings-page__icon-button--danger" type="submit" aria-label="ลบ {{ $meeting->title }}"><i class="bi bi-trash3" aria-hidden="true"></i></button></form>@endcan
    </div>
</article>
