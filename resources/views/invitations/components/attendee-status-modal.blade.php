{{--
    สถานะผู้เข้าร่วมประชุมสำหรับผู้สร้างประชุม — เปิดจากปุ่ม "สถานะผู้เข้าร่วม" ในแถบปุ่มของหน้ารายละเอียด

    $attendeeResponseSummary มาจาก MeetingQueryService::attendeeResponseSummary() และถูกใส่มาเฉพาะ
    เมื่อ MeetingPolicy::viewAttendeeResponses อนุญาต ส่วนนี้จึงไม่ตัดสินสิทธิ์เองและไม่ query อะไรเพิ่ม

    ประวัติการตอบของแต่ละคนแสดงไว้ด้วย เพราะปุ่ม "เชิญอีกครั้ง" เขียนสถานะปัจจุบันทับได้หลายรอบ
    ถ้าหน้าจอโชว์แค่คำตอบล่าสุด ผู้สร้างประชุมจะไม่รู้ว่าเคยปฏิเสธมาก่อนด้วยเหตุผลอะไร
--}}
@php
    $responseDesign = $invitationDesign;
    $attendeeResponses = $attendeeResponseSummary['attendees'];
    $responseTotals = $attendeeResponseSummary['totals'];
@endphp
<div class="modal fade meeting-form-modal meeting-rsvp-modal" id="meetingAttendeeResponsesModal" tabindex="-1"
    aria-labelledby="meetingAttendeeResponsesModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="meetings-page__modal-kicker">MEETING</span>
                    <h2 class="modal-title" id="meetingAttendeeResponsesModalTitle">สถานะผู้เข้าร่วม{{ $invitationNoun }}</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div class="meeting-rsvp__totals">
                    {{-- ลำดับมาจาก attendeeResponseSummary() แล้ว Blade ไม่ต้องรู้ชื่อสถานะเอง --}}
                    @foreach ($responseTotals as $totalStatus => $totalCount)
                        <span class="meeting-rsvp__badge meeting-rsvp__badge--{{ $responseDesign::statusTone($totalStatus) }}">
                            <i class="bi {{ $responseDesign::statusIcon($totalStatus) }}" aria-hidden="true"></i>
                            {{ $responseDesign::statusLabel($totalStatus) }} {{ $totalCount }}
                        </span>
                    @endforeach
                </div>

                @if ($attendeeResponses->isEmpty())
                    <p class="meetings-page__muted">ยังไม่มีผู้เข้าร่วมใน{{ $invitationSubjectNoun }}นี้</p>
                @else
                    <ul class="meeting-rsvp__list">
                        @foreach ($attendeeResponses as $attendeeResponse)
                            <li class="meeting-rsvp__row" data-meeting-attendee-response>
                                <i class="meeting-rsvp__avatar" aria-hidden="true">@include('components.user-avatar-content', ['user' => $attendeeResponse['attendee']])</i>
                                <div class="meeting-rsvp__copy">
                                    <strong>{{ $attendeeResponse['name'] }} · {{ $attendeeResponse['department'] }}</strong>
                                    <span class="meeting-rsvp__badge meeting-rsvp__badge--{{ $responseDesign::statusTone($attendeeResponse['status']) }}">
                                        <i class="bi {{ $responseDesign::statusIcon($attendeeResponse['status']) }}"
                                            aria-hidden="true"></i>
                                        {{ $responseDesign::statusLabel($attendeeResponse['status']) }}
                                    </span>
                                    @if ($attendeeResponse['respondedAt'])
                                        <small>ตอบเมื่อ
                                            {{ $attendeeResponse['respondedAt']->locale('th')->isoFormat('D MMM YYYY') }}
                                            เวลา {{ $attendeeResponse['respondedAt']->format('H:i') }} น.</small>
                                    @endif
                                    @if ($attendeeResponse['declineReason'])
                                        <small class="meeting-rsvp__reason">เหตุผล:
                                            {{ $attendeeResponse['declineReason'] }}</small>
                                    @endif
                                    @if ($attendeeResponse['history']->isNotEmpty())
                                        <details class="meeting-rsvp__history">
                                            <summary>ประวัติการตอบกลับ
                                                ({{ $attendeeResponse['history']->count() }})</summary>
                                            <ol>
                                                @foreach ($attendeeResponse['history'] as $historyEntry)
                                                    <li>
                                                        <span>{{ $responseDesign::eventLabel($historyEntry['event']) }}</span>
                                                        <time
                                                            datetime="{{ $historyEntry['occurredAt']->toIso8601String() }}">{{ $historyEntry['occurredAt']->locale('th')->isoFormat('D MMM YYYY') }}
                                                            เวลา {{ $historyEntry['occurredAt']->format('H:i') }}
                                                            น.</time>
                                                        @if ($historyEntry['declineReason'])
                                                            <em>{{ $historyEntry['declineReason'] }}</em>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ol>
                                        </details>
                                    @endif
                                </div>
                                @if ($attendeeResponse['canBeReinvited'])
                                    <form class="meeting-rsvp__reinvite" method="POST"
                                        action="{{ $attendeeResponse['reinviteUrl'] }}">
                                        @csrf
                                        <button class="meetings-page__button" type="submit">
                                            <i class="bi bi-send" aria-hidden="true"></i> เชิญอีกครั้ง
                                        </button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="meetings-page__button meetings-page__button--primary"
                    data-bs-dismiss="modal"><i class="bi bi-check2" aria-hidden="true"></i> ปิด</button>
            </div>
        </div>
    </div>
</div>
