{{--
    สมาชิกโปรเจกต์ — แสดงอยู่ที่เดียวในคอลัมน์ขวาของ Team Manager

    โครงสร้างตรงกับ App\Support\ProjectRoles:
      แถวบน  — หัวหน้าโปรเจกต์ (ผู้สร้างโปรเจกต์)
      ด้านล่าง — ผู้เข้าร่วมทั้งหมด พร้อมป้าย "ข้ามแผนก" เมื่อมาจากแผนกอื่น

    รายชื่อถูกเติมด้วย JavaScript จาก data-team-data เพราะ modal ใช้ร่วมกันทุกงาน
--}}
<section class="team-current" data-team-current>
    <div class="team-current__head">
        <strong data-team-count>ผู้เข้าร่วม (0 คน)</strong>
        <span>หัวหน้าโปรเจกต์และผู้เข้าร่วมทั้งหมด</span>
    </div>
    <div class="team-member-list">
        <article class="team-member team-member--assignee" data-team-owner></article>
        <div class="team-member-list__collaborators" data-team-members></div>
    </div>
    <p class="team-current__empty" data-team-empty hidden>ยังไม่มีผู้เข้าร่วมในโปรเจกต์นี้</p>
</section>
