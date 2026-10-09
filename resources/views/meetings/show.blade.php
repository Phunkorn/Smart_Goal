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
    // มาจากแท็บ "ประชุม" ใน Workspace ต้องกลับเข้า Workspace ไม่ใช่ถูกทิ้งไว้ที่หน้า /meetings เดี่ยว ๆ
    $fromWorkspace = request('from') === 'workspace' && auth()->user()->role === 'user';
    $meetingBackUrl = $fromWorkspace
        ? route('mytasks.index', ['view' => 'meeting'])
        : route('meetings.index', request()->only(['employee']));
    $meetingBackLabel = $fromWorkspace ? 'กลับไปหน้างานของฉัน' : 'กลับไปรายการประชุม';
    $canRespondToMeeting = auth()->user()->can('respondToInvitation', $meeting);
    /*
     * ลำดับความสำคัญของ modal ที่จะถูกเปิดตอนโหลดหน้า:
     * 1. ที่ถูกสั่งกลับมาตรง ๆ (เช่น เหตุผลไม่ว่างที่กรอกไม่ผ่าน)
     * 2. มาจากการแจ้งเตือนที่ขอคำตอบ (?respond=1) และยังตอบได้จริง
     * 3. กฎเดิม: มี error แปลว่ากลับไปเปิดฟอร์มแก้ไข
     *
     * ข้อ 2 ไม่เปิดเมื่อผู้ใช้เดินเข้าหน้าเอง คนที่มาดูรายละเอียดไม่ได้ตั้งใจมาตอบ
     */
    $meetingOpenModal = session('meeting_open_modal')
        ?: (request()->boolean('respond') && $canRespondToMeeting
            ? 'confirmInvitationModal'
            : (($errors->any() || request()->boolean('edit')) && auth()->user()->can('update', $meeting)
                ? 'editMeetingModal'
                : null));

    // ค่าที่ partial คำเชิญซึ่งใช้ร่วมกับนัดหมายต้องการ
    $meetingInvitationContext = [
        'invitationSubject' => $meeting,
        'invitationDesign' => \App\Support\MeetingAttendeeResponseDesign::class,
        'invitationNoun' => 'ประชุม',
        'invitationSubjectNoun' => 'การประชุม',
        'invitationOrganiser' => 'ผู้จัดประชุม',
        'invitationTitle' => $meeting->title,
        'invitationSchedule' => \App\Support\ThaiDate::day($start).' เวลา '.$start->format('H:i').'–'.$end->format('H:i').' น.'
            .($meeting->location ? ' · '.$meeting->location : ''),
        'invitationAcceptUrl' => route('meetings.attendance.accept', $meeting),
        'invitationDeclineUrl' => route('meetings.attendance.decline', $meeting),
    ];
    $meetingFeedback = [
        'success' => session('meeting_success'),
        'error' => session('meeting_error') ?: $errors->first(),
        'open_modal' => $meetingOpenModal,
    ];
@endphp
{{-- เว้นที่ด้านล่างให้แถบปุ่มติดขอบจอของมือถือ ไม่ให้ทับเนื้อหาแถวสุดท้าย --}}
<div class="meetings-page meetings-page--detail @if($canRespondToMeeting) meetings-page--awaiting-invitation-response @endif">
    <header class="meetings-page__header">
        <div><a class="meetings-page__back" href="{{ $meetingBackUrl }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> {{ $meetingBackLabel }}</a><h1>{{ $meeting->title }}</h1><div class="meetings-page__detail-badges"><span class="meetings-page__status meetings-page__status--{{ $status[1] }}">{{ $status[0] }}</span>@if($inspectedRole)<span class="meetings-page__role">{{ $inspectedEmployee->name }} · {{ $inspectedRole }}</span>@endif</div></div>
        {{-- แถบนี้เป็นปุ่มของผู้สร้างประชุมเท่านั้น ปุ่มตอบรับคำเชิญของผู้ถูกเชิญอยู่ในการ์ดด้านล่างหัวเรื่อง --}}
        <div class="meetings-page__actions">@can('viewAttendeeResponses', $meeting)<button class="meetings-page__button" type="button" data-meeting-modal-trigger="meetingAttendeeResponsesModal" aria-controls="meetingAttendeeResponsesModal" aria-haspopup="dialog"><i class="bi bi-people" aria-hidden="true"></i> สถานะผู้เข้าร่วม</button>@endcan @can('update', $meeting)<button class="meetings-page__button" type="button" data-meeting-modal-trigger="editMeetingModal" aria-controls="editMeetingModal" aria-haspopup="dialog" data-meeting-edit><i class="bi bi-pencil" aria-hidden="true"></i> แก้ไข</button>@endcan @can('delete', $meeting)<form method="POST" action="{{ route('meetings.destroy', $meeting) }}" data-meeting-delete data-meeting-title="{{ $meeting->title }}">@csrf @method('DELETE')<button class="meetings-page__button meetings-page__button--danger" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i> ลบ</button></form>@endcan</div>
    </header>

    @if ($viewerInvitation) @include('invitations.components.response-card', $meetingInvitationContext + ['viewerInvitation' => $viewerInvitation]) @endif

    {{--
        รายละเอียดกับผู้เข้าร่วมรวมอยู่ในการ์ดใบเดียวกันแล้ว (เดิมแยกเป็นสองคอลัมน์)
        ผู้เข้าร่วมอยู่ด้านล่างสุด เปิดหน้ามาต้องเห็น "ทั้งหมด" เสมอ ผู้ใช้กรองดูเฉพาะแผนกเองได้
        ถ้าต้องการ เพราะการประชุมหนึ่งอาจมีคนข้ามแผนกเข้าร่วม (ดู initializeAttendeeDepartmentFilter
        ใน meetings/index.js — กรองแค่ซ่อน/แสดงฝั่ง client ไม่กระทบจำนวนที่ระบุไว้)
    --}}
    <main class="meetings-page__panel meetings-page__detail-main">
        <section><h2>รายละเอียดการประชุม</h2><p>{{ $meeting->description ?: 'ไม่มีรายละเอียดเพิ่มเติม' }}</p></section>
        <dl class="meetings-page__detail-meta">
            <div><dt><i class="bi bi-calendar3" aria-hidden="true"></i> วันที่</dt><dd>@if($start->isSameDay($end)){{ $start->locale('th')->isoFormat('dddd D MMMM YYYY') }}@else{{ $start->locale('th')->isoFormat('D MMM YYYY') }} – {{ $end->locale('th')->isoFormat('D MMM YYYY') }}@endif</dd></div>
            <div><dt><i class="bi bi-clock" aria-hidden="true"></i> เวลา</dt><dd>{{ $start->format('H:i') }}–{{ $end->format('H:i') }} น.</dd></div>
            <div><dt><i class="bi bi-geo-alt" aria-hidden="true"></i> สถานที่</dt><dd>{{ $meeting->location ?: 'ไม่ระบุสถานที่' }}@if($meeting->map_url)<a class="meetings-page__map-link" href="{{ $meeting->map_url }}" target="_blank" rel="noopener"><i class="bi bi-map" aria-hidden="true"></i> เปิดแผนที่</a>@endif</dd></div>
            <div><dt><i class="bi bi-person" aria-hidden="true"></i> ผู้สร้าง</dt><dd>{{ $meeting->creator?->name ?? 'บัญชีที่ถูกลบ' }}</dd></div>
            @if($meeting->project)<div><dt><i class="bi bi-folder2-open" aria-hidden="true"></i> โปรเจกต์</dt><dd>{{ $meeting->project->name }}@if($meeting->task) · {{ $meeting->task->job_topic }}@endif</dd></div>@endif
        </dl>
        <section class="meetings-page__detail-people">
            <div class="meetings-page__panel-head"><div><h2>ผู้เข้าร่วม</h2><p>รายชื่อทั้งหมดในการประชุมนี้</p></div><span>{{ $meeting->attendees->count() }} คน</span></div>
            @include('meetings.components.attendee-list', ['meeting' => $meeting])
        </section>
    </main>

    @if($canRespondToMeeting)
        @include('invitations.components.decline-modal', $meetingInvitationContext)
        @include('invitations.components.mobile-actions', $meetingInvitationContext)
        @include('invitations.components.confirm-modal', $meetingInvitationContext)
    @endif
    @can('viewAttendeeResponses', $meeting) @include('invitations.components.attendee-status-modal', $meetingInvitationContext + ['attendeeResponseSummary' => $attendeeResponseSummary]) @endcan
    @can('update', $meeting) @include('meetings.components.form-modal', ['formMeeting' => $meeting, 'attendeeOptions' => $attendeeOptions, 'attendeeDepartments' => $attendeeDepartments, 'projectOptions' => $projectOptions, 'meetingContextQuery' => array_filter(['employee' => request('employee'), 'from' => $fromWorkspace ? 'workspace' : null])]) @endcan
    <script type="application/json" data-meeting-feedback>@json($meetingFeedback)</script>
</div>
@endsection
