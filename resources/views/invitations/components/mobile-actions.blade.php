{{--
    แถบปุ่มตอบรับติดขอบล่างของจอ สำหรับจอเล็กเท่านั้น (ซ่อนด้วย media query ใน
    pages/meetings/attendee-response.css ไม่ใช่ด้วย JavaScript)

    บนมือถือการ์ดคำเชิญด้านบนจะเลื่อนหลุดจอไปเมื่อผู้ใช้อ่านรายละเอียดการประชุม แถบนี้
    ทำให้ยังตอบได้ทันทีโดยไม่ต้องเลื่อนกลับขึ้นไป

    ใช้ปุ่มคู่เดียวกับในแบนเนอร์ (invitations.components.response-actions) ไม่ประกาศปุ่มชุดใหม่
    ลำดับปุ่มบนจอเล็กให้ "ไม่ว่าง" อยู่ซ้ายและปุ่มหลักอยู่ขวาด้วย CSS ไม่ใช่สลับ markup
    ผู้เรียกเป็นผู้ตัดสินว่าจะ render หรือไม่ ตอบแล้วแถบนี้จะไม่ถูก render อีก
--}}
<div class="meeting-rsvp-mobile-actions" data-meeting-invitation-mobile-actions>
    <p class="meeting-rsvp-mobile-actions__prompt">ตอบกลับคำเชิญ{{ $invitationSubjectNoun }}นี้</p>
    <div class="meeting-rsvp-mobile-actions__buttons">
        @include('invitations.components.response-actions', ['declineLabel' => 'ไม่ว่าง'])
    </div>
</div>
