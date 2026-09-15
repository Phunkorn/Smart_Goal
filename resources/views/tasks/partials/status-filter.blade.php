{{--
    ตัวกรองสถานะของ Task Workspace — ใช้ร่วมกันทั้งหน้า "งานของฉัน" และ Workspace ของสมาชิก
    ห้ามคัดลอก markup ไปวางซ้ำ ค่าที่ยอมรับได้ต้องตรงกับ boardStatuses ใน task-filter-state.js

    "งานข้ามแผนก" ไม่ใช่สถานะ แต่ใช้ช่องเดียวกันเพราะเป็นมุมมองที่หัวหน้าและพนักงานใช้บ่อย
    การตัดสินว่างานใบไหนข้ามแผนกทำที่ server (App\Support\CrossDepartmentWork)
--}}
<div class="notion-filter" data-board-status-filter data-sg-select {{ ! in_array($workspaceView, ['table', 'board'], true) ? 'hidden' : '' }}>
    <i class="bi bi-funnel" aria-hidden="true"></i>
    <select data-filter aria-label="กรองตามสถานะ">
        <option value="" data-icon="bi-collection" data-description="แสดงงานทุกสถานะในขอบเขตปัจจุบัน">ทุกสถานะ</option>
        <option value="my_review" data-icon="bi-clipboard-check" data-description="คนที่เรามอบงานให้ส่งกลับมาแล้ว รอเราอนุมัติหรือส่งกลับไปแก้">รอฉันตรวจ</option>
        <option value="2" data-icon="bi-play-circle" data-description="งานที่กำลังดำเนินการอยู่" data-divider="true">กำลังทำ</option>
        <option value="awaiting_review" data-icon="bi-hourglass-split" data-description="งานที่เราส่งไปแล้ว กำลังรอผู้มอบหมายตรวจ">รอคนอื่นตรวจ</option>
        <option value="5" data-icon="bi-pause-circle" data-description="งานที่หยุดดำเนินการไว้ชั่วคราว">พักงาน</option>
        <option value="late" data-icon="bi-exclamation-triangle" data-description="งานที่เลยกำหนดส่งและยังไม่เสร็จ">ล่าช้า</option>
        <option value="4" data-icon="bi-check-circle" data-description="งานที่อนุมัติและปิดเรียบร้อยแล้ว">เสร็จแล้ว</option>
        <option value="cross_department" data-icon="bi-arrow-left-right" data-description="งานที่ร่วมกับแผนกอื่น หรือถูกมอบหมายมาจากแผนกอื่น" data-divider="true">งานข้ามแผนก</option>
    </select>
</div>
