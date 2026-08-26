{{--
    ทีมปัจจุบันของงาน — แสดงอยู่ที่เดียวในคอลัมน์ขวาของ Team Manager
    รายชื่อถูกเติมด้วย JavaScript จาก data-team-data เพราะ modal ใช้ร่วมกันทุกงาน
--}}
<section class="team-current" data-team-current>
    <div class="team-current__head">
        <strong data-team-count>ทีมปัจจุบัน 0 คน</strong>
        <span>สมาชิกที่อยู่ในงานนี้แล้ว</span>
    </div>
    <div class="team-member-list" data-team-members></div>
    <p class="team-current__empty" data-team-empty hidden>ยังไม่มีผู้ร่วมงานในงานนี้</p>
</section>
