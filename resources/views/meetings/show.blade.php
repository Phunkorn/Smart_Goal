@extends('layouts.app')
@section('title', $meeting->title)
@push('styles') @vite('resources/css/pages/meetings.css') @endpush
@push('scripts') @vite('resources/js/pages/meetings/index.js') @endpush

@section('content')
@php
    $start = $meeting->starts_at->copy()->timezone('Asia/Bangkok');
    $end = $meeting->ends_at->copy()->timezone('Asia/Bangkok');
    $isPast = $end->lt($nowBangkok);
    $isOngoing = $start->lte($nowBangkok) && $end->gte($nowBangkok);
    $status = $isOngoing ? ['กำลังประชุม', 'green'] : ($isPast ? ['ที่ผ่านมา', 'gray'] : ['กำลังจะมาถึง', 'blue']);
    $inspectedRole = $inspectedEmployee ? ((int) $meeting->created_by === (int) $inspectedEmployee->id ? 'ผู้สร้าง' : 'ผู้เข้าร่วม') : null;
    $meetingFeedback = [
        'success' => session('meeting_success'),
        'error' => session('meeting_error') ?: $errors->first(),
        'open_modal' => (session('meeting_open_modal') || $errors->any() || request()->boolean('edit')) && auth()->user()->can('update', $meeting)
            ? 'editMeetingModal'
            : null,
    ];
@endphp
<div class="meetings-page meetings-page--detail">
    <header class="meetings-page__header">
        <div><a class="meetings-page__back" href="{{ route('meetings.index', request()->only(['employee'])) }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> กลับไปรายการประชุม</a><h1>{{ $meeting->title }}</h1><div class="meetings-page__detail-badges"><span class="meetings-page__status meetings-page__status--{{ $status[1] }}">{{ $status[0] }}</span>@if($inspectedRole)<span class="meetings-page__role">{{ $inspectedEmployee->name }} · {{ $inspectedRole }}</span>@endif</div></div>
        <div class="meetings-page__actions">@can('update', $meeting)<button class="meetings-page__button" type="button" data-meeting-modal-trigger="editMeetingModal" aria-controls="editMeetingModal" aria-haspopup="dialog" data-meeting-edit><i class="bi bi-pencil" aria-hidden="true"></i> แก้ไข</button>@endcan @can('delete', $meeting)<form method="POST" action="{{ route('meetings.destroy', $meeting) }}" data-meeting-delete data-meeting-title="{{ $meeting->title }}">@csrf @method('DELETE')<button class="meetings-page__button meetings-page__button--danger" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> ลบ</button></form>@endcan</div>
    </header>

    <div class="meetings-page__detail-grid">
        <main class="meetings-page__panel meetings-page__detail-main">
            <section><h2>รายละเอียดการประชุม</h2><p>{{ $meeting->description ?: 'ไม่มีรายละเอียดเพิ่มเติม' }}</p></section>
            <dl class="meetings-page__detail-meta">
                <div><dt><i class="bi bi-calendar3" aria-hidden="true"></i> วันที่</dt><dd>@if($start->isSameDay($end)){{ $start->locale('th')->isoFormat('dddd D MMMM YYYY') }}@else{{ $start->locale('th')->isoFormat('D MMM YYYY') }} – {{ $end->locale('th')->isoFormat('D MMM YYYY') }}@endif</dd></div>
                <div><dt><i class="bi bi-clock" aria-hidden="true"></i> เวลา</dt><dd>{{ $start->format('H:i') }}–{{ $end->format('H:i') }} น.</dd></div>
                <div><dt><i class="bi bi-geo-alt" aria-hidden="true"></i> สถานที่</dt><dd>{{ $meeting->location ?: 'ไม่ระบุสถานที่' }}</dd></div>
                <div><dt><i class="bi bi-person" aria-hidden="true"></i> ผู้สร้าง</dt><dd>{{ $meeting->creator?->name ?? 'บัญชีที่ถูกลบ' }}</dd></div>
            </dl>
        </main>
        <aside class="meetings-page__panel meetings-page__detail-people"><div class="meetings-page__panel-head"><div><h2>ผู้เข้าร่วม</h2><p>รายชื่อทั้งหมดในการประชุมนี้</p></div><span>{{ $meeting->attendees->count() }} คน</span></div>@include('meetings.components.attendee-list', ['meeting' => $meeting])</aside>
    </div>

    @can('update', $meeting) @include('meetings.components.form-modal', ['formMeeting' => $meeting, 'attendeeOptions' => $attendeeOptions, 'attendeeDepartments' => $attendeeDepartments]) @endcan
    <script type="application/json" data-meeting-feedback>@json($meetingFeedback)</script>
</div>
@endsection
