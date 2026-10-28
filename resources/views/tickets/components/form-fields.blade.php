{{--
    ช่องกรอกที่ฟอร์มแจ้งปัญหาแบบหน้าเดียวใช้ร่วมกัน: สาขา/สถานที่ · รายละเอียด · เบอร์โทร · AnyDesk · รูปภาพ
    ใช้ใน tickets/create.blade.php (ผู้ใช้ภายใน, ทางสำรองเมื่อไม่มี JavaScript)
    แต่ละส่วนเป็น partial ของตัวเอง เพื่อให้ modal แจ้งปัญหา (submit-modal) และหน้าสาธารณะ
    จัดเป็นขั้นตอนได้จากชุดเดียวกัน

    พารามิเตอร์: $locations — สาขาที่เปิดใช้งาน (id, name)
                $requester — ผู้ใช้ที่ login (ใช้เติมเบอร์โทรตั้งต้นจากโปรไฟล์)
--}}
@php
    use App\Services\TicketSubmissionService;
@endphp

@include('tickets.components.location-field', ['locations' => $locations])

{{-- รูปภาพ include แยกท้ายสุด เพื่อให้ช่องติดต่อกลับอยู่ก่อนตัวเลือกรูป --}}
@include('tickets.components.problem-fields', ['withPhotos' => false])

@include('tickets.components.contact-fields', ['contactValue' => TicketSubmissionService::prefillPhone($requester->phone)])

@include('tickets.components.photo-picker')
