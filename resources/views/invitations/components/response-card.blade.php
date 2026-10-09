{{--
    แบนเนอร์คำเชิญของผู้ถูกเชิญ — ใช้ร่วมกันทั้งการประชุมและนัดหมาย

    อยู่เหนือแผงรายละเอียดเพื่อให้เห็นก่อน scroll และแยกออกจากแถบปุ่มมุมขวาบนโดยเจตนา
    ที่นั่นเป็นปุ่มของผู้สร้าง (สถานะผู้เข้าร่วม/แก้ไข/ลบ) การวางปุ่มตอบรับไว้ปนกัน
    ทำให้ผู้ถูกเชิญมองไม่เห็นว่าตัวเองต้องตอบอะไร

    ตอนที่ยังรอตอบ แบนเนอร์จะได้น้ำหนักเต็ม (สีพื้น แถบซ้ายหนา หัวเรื่องใหญ่ ปุ่มสูง)
    เพราะเป็นสิ่งเดียวที่ผู้ใช้ต้องลงมือทำบนหน้านี้ ส่วนสถานะที่ตอบแล้วเป็นแค่การ์ดเบา ๆ

    สัญญาที่ผู้เรียกต้องส่งมา:
    $invitationSubject    โมเดลที่ใช้ตรวจสิทธิ์ (Meeting หรือ Appointment)
    $viewerInvitation     อาเรย์จาก viewerInvitation() ของ query service ของ domain นั้น
    $invitationDesign     class-string ของคลาสป้ายสถานะ
    $invitationNoun       รูปสั้นสำหรับป้าย 'ประชุม' หรือ 'นัดหมาย'
    $invitationSubjectNoun รูปเต็มสำหรับประโยค 'การประชุม' หรือ 'นัดหมาย'
    $invitationOrganiser  'ผู้จัดประชุม' หรือ 'ผู้สร้างนัดหมาย'
    $invitationAcceptUrl  ปลายทางของการกดตอบรับ
    $invitationSchedule   บรรทัดวัน-เวลา-สถานที่ ของนัดนั้น

    ส่วนนี้ไม่คิวรี ไม่ตัดสินสิทธิ์ และไม่แปลงโซนเวลาเอง
    "ตอบไม่ได้" ภายในแบนเนอร์มีความหมายเดียวคือตอบไปแล้ว เพราะผู้ที่ไม่ได้ถูกเชิญ
    จะไม่มี $viewerInvitation และแบนเนอร์นี้จะไม่ถูก render เลย
--}}
@php
    $invitationStatus = $viewerInvitation['status'];
    $invitationTone = $invitationDesign::statusTone($invitationStatus);
    $isAwaitingAnswer = auth()->user()->can('respondToInvitation', $invitationSubject);
@endphp
<section
    class="meeting-rsvp-card meeting-rsvp-card--{{ $invitationTone }} @if ($isAwaitingAnswer) meeting-rsvp-card--awaiting @endif"
    aria-labelledby="meetingInvitationResponseHeading" data-meeting-invitation-card>
    <div class="meeting-rsvp-card__header">
        <span class="meeting-rsvp-card__icon">
            <i class="bi {{ $invitationDesign::statusIcon($invitationStatus) }}" aria-hidden="true"></i>
        </span>
        <div class="meeting-rsvp-card__copy">
            @if ($isAwaitingAnswer)
                @if ($viewerInvitation['isReinvitation'])
                    <h2 id="meetingInvitationResponseHeading">{{ $invitationOrganiser }}เชิญคุณเข้าร่วมอีกครั้ง</h2>
                    <p>กรุณาตอบกลับคำเชิญใหม่</p>
                @else
                    <h2 id="meetingInvitationResponseHeading">คำเชิญเข้าร่วม{{ $invitationNoun }}</h2>
                    <p>คุณได้รับเชิญให้เข้าร่วม{{ $invitationSubjectNoun }}นี้ กรุณาตรวจสอบรายละเอียดและตอบกลับ</p>
                @endif

                {{-- บอกวัน-เวลาในแบนเนอร์เลย ผู้ใช้จะได้ตัดสินใจโดยไม่ต้องเลื่อนลงไปอ่านรายละเอียด --}}
                <p class="meeting-rsvp-card__schedule"><i class="bi bi-calendar3" aria-hidden="true"></i>
                    {{ $invitationSchedule }}</p>
            @elseif ($viewerInvitation['hasAccepted'])
                <h2 id="meetingInvitationResponseHeading">คุณตอบรับเข้าร่วม{{ $invitationNoun }}แล้ว</h2>
            @else
                <h2 id="meetingInvitationResponseHeading">คุณแจ้งว่าไม่สะดวกเข้าร่วม{{ $invitationNoun }}</h2>
                <p class="meeting-rsvp-card__reason">เหตุผล: {{ $viewerInvitation['declineReason'] }}</p>
                <p>หากต้องการเปลี่ยนคำตอบ ต้องรอให้{{ $invitationOrganiser }}เชิญคุณอีกครั้ง</p>
            @endif

            <p class="meeting-rsvp-card__status">
                <span class="meeting-rsvp__badge meeting-rsvp__badge--{{ $invitationTone }}">
                    <i class="bi {{ $invitationDesign::statusIcon($invitationStatus) }}" aria-hidden="true"></i>
                    {{ $invitationDesign::statusLabel($invitationStatus) }}
                </span>
                @if ($viewerInvitation['respondedAt'])
                    <span class="meeting-rsvp-card__answered-at">ตอบเมื่อ
                        {{ $invitationDesign::respondedAtLabel($viewerInvitation['respondedAt']) }}</span>
                @endif
            </p>
        </div>
    </div>

    @if ($isAwaitingAnswer)
        <div class="meeting-rsvp-card__actions">
            @include('invitations.components.response-actions')
        </div>
    @endif
</section>
