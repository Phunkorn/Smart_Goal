{{--
    ปุ่มตอบรับคำเชิญสองปุ่มของผู้ถูกเชิญ — ใช้ร่วมกันทั้งการประชุมและนัดหมาย

    คู่เดียวในระบบ ใช้ทั้งในแบนเนอร์คำเชิญ ในแถบปุ่มติดขอบล่างของมือถือ และในกล่อง
    ยืนยันที่เด้งจากการแจ้งเตือน จึงไม่มี markup ของปุ่มชุดที่สองที่ไหนอีก

    สัญญาที่ผู้เรียกต้องส่งมา:
    $invitationAcceptUrl   ปลายทางของการกดตอบรับ (คนละ route ระหว่างสอง domain)
    $invitationNoun        'ประชุม' หรือ 'นัดหมาย' ใช้ประกอบป้ายปุ่ม

    ผู้เรียกเป็นผู้ตัดสินว่าจะแสดงหรือไม่ (ผ่าน @can('respondToInvitation'))
    $declineLabel ใช้ย่อป้ายบนจอแคบตามที่พื้นที่อนุญาต ค่าเริ่มต้นคือป้ายเต็ม
--}}
<form class="meeting-rsvp-actions__accept" method="POST" action="{{ $invitationAcceptUrl }}">
    @csrf
    <button class="meetings-page__button meetings-page__button--primary" type="submit">
        <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $acceptLabel ?? 'เข้าร่วม'.$invitationNoun }}
    </button>
</form>
<button class="meetings-page__button" type="button" data-meeting-modal-trigger="declineMeetingInvitationModal"
    aria-controls="declineMeetingInvitationModal" aria-haspopup="dialog">
    <i class="bi bi-x-circle" aria-hidden="true"></i> {{ $declineLabel ?? 'ไม่ว่าง'.$invitationNoun }}
</button>
