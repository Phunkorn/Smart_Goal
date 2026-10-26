{{--
    ช่องกรอกที่ฟอร์มแจ้งปัญหาแบบหน้าเดียวใช้ร่วมกัน: สาขา/สถานที่ · รายละเอียด · รูปภาพ
    ใช้ใน tickets/create.blade.php (ผู้ใช้ภายใน, ทางสำรองเมื่อไม่มี JavaScript) และ tickets/public/create.blade.php (สาธารณะ)
    แต่ละส่วนเป็น partial ของตัวเอง เพื่อให้ modal แจ้งปัญหา (submit-modal) จัดเป็นขั้นตอนได้จากชุดเดียวกัน

    พารามิเตอร์: $locations — สาขาที่เปิดใช้งาน (id, name)
--}}
@include('tickets.components.location-field', ['locations' => $locations])

@include('tickets.components.problem-fields')
