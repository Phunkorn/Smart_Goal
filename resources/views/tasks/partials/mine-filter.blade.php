{{--
    ปุ่มสลับ "เฉพาะงานของฉัน" — ตัวกรองการแสดงผลฝั่ง client ล้วน ๆ

    ผู้ใช้ที่ถูกเชิญร่วมงานหนึ่งใบในโปรเจกต์ จะเห็นงานพี่น้องทุกใบในโปรเจกต์นั้นแบบ read-only
    ตามสิทธิ์ระดับโปรเจกต์ (WorkOrder::scopeVisibleInProjectsFor) ซึ่งถูกต้องแล้ว
    ปุ่มนี้ไม่ได้เปลี่ยนสิทธิ์หรือชุดงานที่ server ส่งมาเลยแม้แต่น้อย มันเพียงซ่อนแถวที่
    data-participate="0" เพื่อให้ผู้ใช้เห็นชัดว่าตัวเองอยู่ในงานไหนบ้าง

    ค่าเริ่มต้นต้องเป็น "ปิด" เสมอ บอร์ดตอนโหลดหน้าจึงยังแสดงทั้งโปรเจกต์เหมือนเดิม

    เฉพาะมุมมองบอร์ดเท่านั้น — มุมมองตารางกรองด้วย ability participate ที่ฝั่ง server
    อยู่แล้ว (table-kanban.blade.php) ปุ่มที่นั่นจึงไม่มีผลและจะทำให้ผู้ใช้สับสน
    ค่า hidden ตั้งต้นที่นี่เป็นเพียงการกันหน้ากระพริบ ตัวตัดสินจริงคือ applyView()
    ใน mytasks-views.js ซึ่งคำนวณใหม่ทุกครั้งที่สลับมุมมอง
--}}
<div class="mytasks-mine-filter" data-board-mine-filter {{ $workspaceView !== 'board' ? 'hidden' : '' }}>
    <button type="button" class="mytasks-mine-filter__button" data-board-mine-toggle
            aria-pressed="false" title="ซ่อนงานในโปรเจกต์ที่คุณไม่ได้ร่วมทำ">
        <i class="bi bi-person-check" aria-hidden="true"></i>
        <span>เฉพาะงานของฉัน</span>
    </button>
</div>
