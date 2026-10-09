@extends('layouts.app')
@section('title', $appointment->title)
@push('styles') @vite('resources/css/pages/meetings.css') @endpush
@push('scripts') @vite('resources/js/pages/meetings/index.js') @endpush

@section('content')
{{--
    รายละเอียดนัดหมาย — ใช้ภาษาและโครงหน้าของนัดหมาย ไม่ใช่ของการประชุม

    ใช้ design language ชุดเดียวกับหน้ารายละเอียดการประชุม (.meetings-page) เพื่อให้
    ทั้งสองหน้าหน้าตาเป็นระบบเดียวกัน แต่ไม่มีส่วนผู้เข้าร่วมและไม่มีการตอบรับ
    เพราะนัดหมายไม่เชิญใคร (ดู create_appointments_table)

    วันที่และเวลาจัดรูปแบบด้วย App\Support\ThaiDate ที่เดียวกับทุกวันที่ของสามหน้านี้
--}}
@php
    $thaiDate = \App\Support\ThaiDate::class;
    $appointmentBackUrl = route('mytasks.index', ['view' => 'meeting']);
    $canRespondToAppointment = auth()->user()->can('respondToInvitation', $appointment);
    $appointmentSchedule = $thaiDate::day($appointment->appointment_date).' เวลา '
        .$thaiDate::timeRangeLabel($appointment->starts_at_time, $appointment->ends_at_time)
        .($appointment->location ? ' · '.$appointment->location : '');

    // ลำดับเดียวกับหน้าประชุม: ที่ถูกสั่งกลับมา > มาจากแจ้งเตือนที่ขอคำตอบ > ฟอร์มแก้ไขเมื่อมี error
    $appointmentOpenModal = session('meeting_open_modal')
        ?: (request()->boolean('respond') && $canRespondToAppointment
            ? 'confirmInvitationModal'
            : ($errors->any() && auth()->user()->can('update', $appointment) ? 'editAppointmentModal' : null));

    $appointmentFeedback = [
        'success' => session('meeting_success'),
        'error' => session('meeting_error') ?: $errors->first(),
        'open_modal' => $appointmentOpenModal,
    ];

    // ค่าที่ partial คำเชิญซึ่งใช้ร่วมกับการประชุมต้องการ
    $appointmentInvitationContext = [
        'invitationSubject' => $appointment,
        'invitationDesign' => \App\Support\AppointmentAttendeeResponseDesign::class,
        'invitationNoun' => 'นัดหมาย',
        'invitationSubjectNoun' => 'นัดหมาย',
        'invitationOrganiser' => 'ผู้สร้างนัดหมาย',
        'invitationTitle' => $appointment->title,
        'invitationSchedule' => $appointmentSchedule,
        'invitationAcceptUrl' => route('appointments.attendance.accept', $appointment),
        'invitationDeclineUrl' => route('appointments.attendance.decline', $appointment),
    ];
@endphp
{{-- เว้นที่ด้านล่างให้แถบปุ่มติดขอบจอของมือถือ ไม่ให้ทับเนื้อหาแถวสุดท้าย --}}
<div class="meetings-page meetings-page--detail @if($canRespondToAppointment) meetings-page--awaiting-invitation-response @endif">
    <header class="meetings-page__header">
        <div>
            <a class="meetings-page__back" href="{{ $appointmentBackUrl }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> กลับไปปฏิทินประชุมและนัดหมาย
            </a>
            <h1>{{ $appointment->title }}</h1>
            <div class="meetings-page__detail-badges">
                <span class="meetings-page__status meetings-page__status--blue">
                    <i class="bi bi-bookmark-star" aria-hidden="true"></i> นัดหมาย
                </span>
            </div>
        </div>
        <div class="meetings-page__actions">
            @can('viewAttendeeResponses', $appointment)
                <button class="meetings-page__button" type="button"
                    data-meeting-modal-trigger="meetingAttendeeResponsesModal"
                    aria-controls="meetingAttendeeResponsesModal" aria-haspopup="dialog">
                    <i class="bi bi-people" aria-hidden="true"></i> สถานะผู้เข้าร่วม
                </button>
            @endcan
            @can('update', $appointment)
                <button class="meetings-page__button" type="button" data-meeting-modal-trigger="editAppointmentModal"
                    aria-controls="editAppointmentModal" aria-haspopup="dialog">
                    <i class="bi bi-pencil" aria-hidden="true"></i> แก้ไข
                </button>
            @endcan
            @can('delete', $appointment)
                <form method="POST" action="{{ route('appointments.destroy', $appointment) }}" data-meeting-delete
                    data-meeting-title="{{ $appointment->title }}" data-meeting-delete-kind="นัดหมาย">
                    @csrf @method('DELETE')
                    <button class="meetings-page__button meetings-page__button--danger" type="submit">
                        <i class="bi bi-trash3" aria-hidden="true"></i> ลบ
                    </button>
                </form>
            @endcan
        </div>
    </header>

    @if ($viewerInvitation)
        @include('invitations.components.response-card', $appointmentInvitationContext + ['viewerInvitation' => $viewerInvitation])
    @endif

    <main class="meetings-page__panel meetings-page__detail-main">
        <section>
            <h2>รายละเอียดนัดหมาย</h2>
            <p>{{ $appointment->description ?: 'ไม่มีรายละเอียดเพิ่มเติม' }}</p>
        </section>
        <dl class="meetings-page__detail-meta">
            <div>
                <dt><i class="bi bi-calendar3" aria-hidden="true"></i> วันที่</dt>
                <dd>{{ $thaiDate::weekdayLongDay($appointment->appointment_date) }}</dd>
            </div>
            <div>
                <dt><i class="bi bi-clock" aria-hidden="true"></i> เวลา</dt>
                <dd>{{ $thaiDate::timeRangeLabel($appointment->starts_at_time, $appointment->ends_at_time) }}</dd>
            </div>
            <div>
                <dt><i class="bi bi-geo-alt" aria-hidden="true"></i> สถานที่</dt>
                <dd>{{ $appointment->location ?: 'ไม่ระบุสถานที่' }}
                    @if ($appointment->map_url)
                        <a class="meetings-page__map-link" href="{{ $appointment->map_url }}" target="_blank"
                            rel="noopener"><i class="bi bi-map" aria-hidden="true"></i> เปิดแผนที่</a>
                    @endif
                </dd>
            </div>
            <div>
                <dt><i class="bi bi-person" aria-hidden="true"></i> ผู้สร้าง</dt>
                <dd>{{ $appointment->creator?->name ?? 'บัญชีที่ถูกลบ' }}</dd>
            </div>
        </dl>
        <section class="meetings-page__detail-people">
            <div class="meetings-page__panel-head">
                <div><h2>ผู้ร่วมนัดหมาย</h2><p>รายชื่อทั้งหมดในนัดหมายนี้</p></div>
                <span>{{ $appointment->attendees->count() }} คน</span>
            </div>
            @include('meetings.components.attendee-list', ['meeting' => $appointment])
        </section>
    </main>

    @if ($canRespondToAppointment)
        @include('invitations.components.decline-modal', $appointmentInvitationContext)
        @include('invitations.components.mobile-actions', $appointmentInvitationContext)
        @include('invitations.components.confirm-modal', $appointmentInvitationContext)
    @endif
    @can('viewAttendeeResponses', $appointment)
        @include('invitations.components.attendee-status-modal', $appointmentInvitationContext + ['attendeeResponseSummary' => $attendeeResponseSummary])
    @endcan
    @can('update', $appointment)
        @include('appointments.components.form-modal', [
            'formAppointment' => $appointment,
            'attendeeOptions' => $attendeeOptions,
            'attendeeDepartments' => $attendeeDepartments,
        ])
    @endcan
    <script type="application/json" data-meeting-feedback>@json($appointmentFeedback)</script>
</div>
@endsection
