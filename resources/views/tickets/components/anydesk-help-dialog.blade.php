{{--
    วิธีหาเลข AnyDesk — modal แบบ <dialog> ของเบราว์เซอร์ ใช้ร่วมกันทุกหน้าที่มีช่อง AnyDesk
    (หน้าสาธารณะ /support, modal แจ้งปัญหาในหน้า /tickets และฟอร์มหน้าเดียว /tickets/create)

    showModal() ให้ backdrop, กันโฟกัสหลุด และปิดด้วย Escape เอง — หน้าสาธารณะไม่ได้โหลด Bootstrap JS
    และ <dialog> อยู่ใน top layer จึงลอยเหนือ backdrop ของ Bootstrap modal ได้เองโดยไม่ต้องจัด z-index
    พฤติกรรมเปิด/ปิด/คืนโฟกัส: components/help-dialog.js (เจ้าของสถานะที่เดียว)

    ต้อง include ไว้ "นอก" <form> ของหน้านั้นเสมอ — ปุ่มในนี้จึงไม่มีทางส่งฟอร์ม
--}}
<dialog class="ticket-help-dialog" id="ticket-anydesk-help" aria-labelledby="ticket-anydesk-help-title" data-help-dialog>
    <div class="ticket-help-dialog__head">
        {{-- โฟกัสแรกอยู่ที่หัวเรื่อง (autofocus) ไม่ใช่ปุ่มปิด — ผู้ใช้ screen reader ได้ยินชื่อ dialog ก่อน --}}
        <h2 class="ticket-help-dialog__title" id="ticket-anydesk-help-title" tabindex="-1" autofocus>วิธีหาเลข AnyDesk</h2>
        <button type="button" class="ticket-help-dialog__close" data-help-dialog-close aria-label="ปิดหน้าต่างวิธีหาเลข AnyDesk">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>
    <ol class="ticket-help-dialog__steps">
        <li>เปิดโปรแกรม <strong>AnyDesk</strong> บนเครื่องที่มีปัญหา</li>
        <li>ดูตัวเลขสีแดงที่ช่อง <strong>Your Address</strong> ด้านบนของหน้าต่าง</li>
        <li>กรอกตัวเลขนั้นในช่องนี้ เช่น <strong class="text-nowrap">1 051 536 333</strong></li>
    </ol>
    <img class="ticket-help-dialog__image" src="{{ asset('images/'.rawurlencode('Anydesk - Example.png')) }}"
        width="1918" height="707" loading="lazy"
        alt="ตัวอย่างหน้าต่าง AnyDesk: เลขที่ต้องกรอกอยู่ในกรอบสีแดงที่ช่อง Your Address ด้านบน">
    <p class="ticket-help-dialog__note">ไม่มีโปรแกรม AnyDesk เว้นช่องนี้ว่างได้ — ทีม IT จะติดต่อกลับทางเบอร์โทร</p>
</dialog>
