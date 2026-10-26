{{--
    ผลของการกระทำล่าสุดบนหน้า Smart Ticket (หลัง redirect)
      success — session('success') จาก jsonOrBack() / TicketController::store
      status  — ข้อผิดพลาดของการกระทำ เช่น สถานะถูกเปลี่ยนไปก่อน (409) จาก jsonOrBack()
    แสดงเป็นแถบในหน้า ไม่ใช่ป๊อปอัป ผู้ใช้จึงอ่านซ้ำได้และ screen reader ประกาศให้
--}}
@if (session('success'))
    <div class="ticket-flash ticket-flash--success" role="status">
        <i class="bi bi-check-circle" aria-hidden="true"></i>
        <span>{{ session('success') }}</span>
    </div>
@endif
@if ($errors->has('status'))
    <div class="ticket-flash ticket-flash--error" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <span>{{ $errors->first('status') }}</span>
    </div>
@endif
