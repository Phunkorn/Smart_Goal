{{--
    กล่องยืนยันการเข้าร่วม — เด้งเฉพาะเมื่อผู้ใช้กดมาจากการแจ้งเตือนที่ขอคำตอบ

    ไม่เด้งเมื่อผู้ใช้เปิดหน้าเอง เพราะคนที่เดินเข้ามาดูรายละเอียดไม่ได้ตั้งใจมาตอบ
    ธงที่สั่งให้เปิดคือ ?respond=1 ซึ่ง NotificationService::target() ต่อท้ายให้เฉพาะ
    แจ้งเตือนชนิด *_scheduled และ *_reinvited (ดู invitationRouteParameters)

    ใช้กลไกเปิด modal ชุดเดิมของหน้าประชุมทั้งหมด: คลาส .meeting-form-modal ทำให้
    resolveMeetingModal() รู้จัก และ showFeedback() เป็นผู้สั่งเปิดจาก data-meeting-feedback
    จึงไม่มีโค้ด auto-open ชุดใหม่ในระบบ

    ปุ่มสองปุ่มเป็นชุดเดียวกับแบนเนอร์ ไม่ประกาศซ้ำ
--}}
<div class="modal fade meeting-form-modal meeting-rsvp-confirm-modal" id="confirmInvitationModal" tabindex="-1"
    aria-labelledby="confirmInvitationModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="meetings-page__modal-kicker">{{ $invitationNoun === 'ประชุม' ? 'MEETING' : 'APPOINTMENT' }}</span>
                    <h2 class="modal-title" id="confirmInvitationModalTitle">ยืนยันการเข้าร่วม{{ $invitationNoun }}</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <p class="meeting-rsvp-confirm__intro">คุณได้รับเชิญให้เข้าร่วม{{ $invitationSubjectNoun }}นี้
                    กรุณาตอบกลับเพื่อให้{{ $invitationOrganiser }}ทราบ</p>

                <div class="meeting-rsvp-confirm__subject">
                    <strong>{{ $invitationTitle }}</strong>
                    <span><i class="bi bi-calendar3" aria-hidden="true"></i> {{ $invitationSchedule }}</span>
                </div>
            </div>
            <div class="modal-footer meeting-rsvp-confirm__actions">
                @include('invitations.components.response-actions')
            </div>
        </div>
    </div>
</div>
