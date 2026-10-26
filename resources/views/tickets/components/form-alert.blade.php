{{--
    ข้อผิดพลาดของทั้งฟอร์มที่ไม่ผูกกับช่องใดช่องหนึ่ง
      form        — rate limit, กันสแปม, กำลังบันทึกซ้ำ (TicketSubmissionGuard / TicketSubmissionService)
      token       — หน้าเปิดค้างจน session หมดอายุ (419, bootstrap/app.php)
      attachments — ไฟล์รวมใหญ่เกิน post_max_size (PostTooLargeException, bootstrap/app.php)
--}}
@php
    $formError = $errors->first('form') ?: $errors->first('token') ?: $errors->first('attachments');
@endphp
@if ($formError)
    <div class="ticket-form__alert" role="alert">
        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
        <span>{{ $formError }}</span>
    </div>
@endif
