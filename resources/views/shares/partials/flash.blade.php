{{--
    ข้อความผลลัพธ์จากการ submit แบบฟอร์มธรรมดา

    หน้าเดียวกันนี้ยังทำงานได้เมื่อ JavaScript ไม่ทำงาน เพราะ
    RespondsWithTaskResult::jsonOrBack() ตอบกลับเป็น redirect พร้อม flash
--}}
@if(session('success'))
    <div class="shares-flash shares-flash--ok" role="status">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>{{ session('success') }}
    </div>
@endif
@error('status')
    <div class="shares-flash shares-flash--error" role="alert">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>{{ $message }}
    </div>
@enderror
