{{--
    แถบงานที่กำลังจับเวลา

    ตัวนับที่เห็นเป็นเพียงการแสดงผลจาก started_at ที่เซิร์ฟเวอร์ส่งมา จำนวนนาที
    ที่ถูกบันทึกจริงคำนวณด้วยเวลาของเซิร์ฟเวอร์ตอนกด "เสร็จสิ้น" เสมอ เพราะนาฬิกา
    ของเครื่องผู้ใช้ตั้งเองได้ และตัวเลขนี้ไปโผล่ในรายงานภาระงาน

    แถบนี้ถูก render เมื่อมีงานที่กำลังจับเวลาอยู่เท่านั้น ฝั่ง JavaScript จะซ่อน
    หรือแสดงตัวมันเองผ่าน property hidden หลังเริ่ม/หยุด โดยไม่ต้องโหลดหน้าใหม่
--}}
<section class="timer-banner"
    aria-live="polite"
    data-timer-banner
    data-started-at="{{ $runningLog?->started_at?->toIso8601String() }}"
    data-log-id="{{ $runningLog?->id }}"
    @unless($runningLog) hidden @endunless>

    <span class="timer-banner__pulse" aria-hidden="true"></span>

    <div class="timer-banner__body">
        <span class="timer-banner__label">กำลังทำอยู่</span>
        <strong class="timer-banner__title" data-timer-title>{{ $runningLog?->title }}</strong>
    </div>

    <span class="timer-banner__clock" data-timer-clock>00:00</span>

    <button type="button" class="timer-banner__stop" data-timer-stop>
        <i class="bi bi-stop-circle" aria-hidden="true"></i>
        <span>เสร็จสิ้น</span>
    </button>
</section>
