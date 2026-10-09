{{--
    แจ้งไม่สะดวกเข้าร่วม — ใช้ร่วมกันทั้งการประชุมและนัดหมาย

    เหตุผลเป็นข้อมูลบังคับทั้งฝั่งฟอร์มและฝั่งเซิร์ฟเวอร์ เพราะผู้สร้างต้องรู้ว่าทำไม
    ($invitationDeclineUrl ชี้ไป controller ของ domain นั้น ซึ่งตรวจซ้ำเสมอ)

    ใช้คลาส .meeting-form-modal ร่วมกับฟอร์มประชุม เพื่อให้ resolveMeetingModal() ใน
    meetings/index.js เปิดใบนี้ได้ด้วยกลไกเดิม และได้สไตล์ฟอร์มชุดเดียวกันทั้งหน้า
--}}
<div class="modal fade meeting-form-modal" id="declineMeetingInvitationModal" tabindex="-1"
    aria-labelledby="declineMeetingInvitationModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div><span class="meetings-page__modal-kicker">MEETING</span>
                    <h2 class="modal-title" id="declineMeetingInvitationModalTitle">ไม่สะดวกเข้าร่วม{{ $invitationNoun }}</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="POST" action="{{ $invitationDeclineUrl }}">
                @csrf
                <div class="modal-body">
                    <p class="meeting-rsvp-decline__intro">กรุณาระบุเหตุผลที่ไม่สามารถเข้าร่วม{{ $invitationSubjectNoun }}ได้</p>
                    <label class="form-label" for="declineMeetingInvitationReason">เหตุผล
                        <span aria-hidden="true">*</span></label>
                    {{-- ข้อความผิดพลาดอยู่ติดใต้ช่องกรอกและผูกด้วย aria-describedby ให้ screen reader อ่านต่อกัน --}}
                    <textarea class="form-control @error('decline_reason') is-invalid @enderror"
                        id="declineMeetingInvitationReason" name="decline_reason" rows="3" maxlength="500" required
                        @error('decline_reason') aria-describedby="declineMeetingInvitationReasonError" aria-invalid="true" @enderror
                        placeholder="เช่น ติดประชุมกับลูกค้า">{{ old('decline_reason') }}</textarea>
                    @error('decline_reason')
                        <p class="meeting-rsvp__error" id="declineMeetingInvitationReasonError">
                            <i class="bi bi-exclamation-circle" aria-hidden="true"></i> {{ $message }}
                        </p>
                    @enderror
                </div>
                <div class="modal-footer">
                    <button type="button" class="meetings-page__button" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="meetings-page__button meetings-page__button--danger">
                        <i class="bi bi-x-circle" aria-hidden="true"></i> ยืนยันไม่เข้าร่วม
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
