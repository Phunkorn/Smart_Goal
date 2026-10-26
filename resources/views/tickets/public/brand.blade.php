{{--
    หัวการ์ดของหน้าแจ้งปัญหาสาธารณะ: โลโก้ Premium Care (ไฟล์จริงของโปรเจกต์ ใช้ชุดเดียวกับหน้าเข้าสู่ระบบ) · Smart Ticket · แจ้งปัญหา IT
    $asHeading — true = "แจ้งปัญหา IT" เป็น <h1> (หน้าฟอร์ม) · false = เป็นข้อความธรรมดา เพราะหน้านั้นมี <h1> ของตัวเอง (หน้ายืนยัน)
--}}
<header class="ticket-public__brand">
    <img class="ticket-public__logo" src="{{ asset('images/premiuum-care-logo.png') }}" alt="Premium Care" width="1060" height="370">
    <p class="ticket-public__product">Smart Ticket</p>
    @if ($asHeading ?? false)
        <h1 class="ticket-public__service">แจ้งปัญหา IT</h1>
    @else
        <p class="ticket-public__service">แจ้งปัญหา IT</p>
    @endif
</header>
