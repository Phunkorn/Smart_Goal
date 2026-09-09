{{--
    หัวหน้าจอ: ตัวเลื่อนวัน ป้ายวันที่แบบไทย (พ.ศ.) และตัวเลือกสมาชิก

    ปุ่ม "วันถัดไป" หายไปเมื่ออยู่ที่วันนี้แล้ว เพราะระบบไม่ให้บันทึกงานล่วงหน้า
    การแสดงปุ่มที่กดแล้วไม่เกิดอะไรขึ้นสร้างความสับสนมากกว่าไม่มีปุ่ม

    ป้ายวันที่กดได้ เปิดปฏิทินของเบราว์เซอร์เพื่อกระโดดไปวันไหนก็ได้ในช่วงที่
    ระบบยอมให้ย้อนหลัง โดยไม่ต้องกดลูกศรทีละวัน
--}}
<header class="daily-log__header">
    <div class="daily-log__heading">
        <div class="daily-log__eyebrow">
            <i class="bi bi-journal-check" aria-hidden="true"></i>
            บันทึกงานประจำวัน
        </div>
        <h1 class="daily-log__title">
            @if($isOwnDay)
                งานของฉัน
            @else
                งานของ {{ $owner->name }}
            @endif
        </h1>
        <p class="daily-log__subtitle">
            งานประจำและงานนอกสถานที่ที่ไม่ได้อยู่ในบอร์ดโปรเจกต์
        </p>
    </div>

    <div class="daily-log__controls">
        @if($capabilities['canViewOthers'])
            <form method="GET" action="{{ route('daily-logs.index') }}" class="daily-log__member-form" data-member-form>
                <input type="hidden" name="date" value="{{ $dateValue }}">
                <label class="visually-hidden" for="dailyLogMember">เลือกสมาชิก</label>
                <select class="form-select daily-log__member-select" id="dailyLogMember" name="user" data-member-select>
                    <option value="{{ auth()->id() }}" @selected($owner->id === auth()->id())>งานของฉัน</option>
                    @foreach($members as $member)
                        @continue($member->id === auth()->id())
                        <option value="{{ $member->id }}" @selected($member->id === $owner->id)>
                            {{ $member->name }}@if($member->department) · {{ $member->department->department_name }}@endif
                        </option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="btn btn-outline-secondary">ดู</button></noscript>
            </form>
        @endif

        {{--
            ปฏิทินเลือกวัน — ป้ายวันที่เป็นตัวเปิดปฏิทินในตัว

            ใช้ปฏิทินตัวเดียวกับทั้งระบบ (resources/js/components/date-picker.js)
            ผ่าน data-date-picker บนช่องวันที่จริง จึงได้ปฏิทินแบบ พ.ศ. หน้าตา
            เดียวกับหน้างานอื่น ไม่ใช่ปฏิทินของเบราว์เซอร์ที่เปลี่ยนไปตามเครื่อง
            ช่องวันที่ยังเป็นแหล่งความจริงของค่าและกติกา min/max และยังใช้ได้
            เมื่อ JavaScript ไม่ทำงาน (กดปุ่ม "ไป")

            ส่ง user ไปด้วยเฉพาะตอนดูของคนอื่น เพื่อให้ URL ของงานตัวเองสั้น
            เหลือแค่ ?date= ตามเดิมที่ผู้ใช้อ่านแล้วเข้าใจได้
        --}}
        <form method="GET" action="{{ route('daily-logs.index') }}" class="daily-log__date-nav" data-date-form>
            @unless($isOwnDay)
                <input type="hidden" name="user" value="{{ $owner->id }}">
            @endunless

            <a class="daily-log__date-step"
                href="{{ route('daily-logs.index', array_filter(['date' => $previousDate, 'user' => $isOwnDay ? null : $owner->id])) }}"
                aria-label="วันก่อนหน้า">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
            </a>

            <label class="daily-log__date-picker">
                <span class="daily-log__date-label" data-date-label>
                    {{ $dateLabel }}
                    @if($isToday)<span class="daily-log__today-chip">วันนี้</span>@endif
                    <i class="bi bi-calendar3 daily-log__date-icon" aria-hidden="true"></i>
                </span>
                <input type="date"
                    class="daily-log__date-input"
                    name="date"
                    value="{{ $dateValue }}"
                    min="{{ $minDate }}"
                    max="{{ $maxDate }}"
                    aria-label="เลือกวันที่"
                    data-date-picker
                    data-date-input>
            </label>

            @if($nextDate !== null)
                <a class="daily-log__date-step"
                    href="{{ route('daily-logs.index', array_filter(['date' => $nextDate, 'user' => $isOwnDay ? null : $owner->id])) }}"
                    aria-label="วันถัดไป">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            @else
                <span class="daily-log__date-step daily-log__date-step--disabled" aria-hidden="true">
                    <i class="bi bi-chevron-right"></i>
                </span>
            @endif

            <noscript><button type="submit" class="btn btn-outline-secondary btn-sm">ไป</button></noscript>
        </form>
    </div>
</header>

@if($capabilities['isReadOnly'])
    <p class="daily-log__notice" role="status">
        <i class="bi bi-eye" aria-hidden="true"></i>
        คุณกำลังดูบันทึกของ {{ $owner->name }} แบบอ่านอย่างเดียว — บันทึกงานประจำวันแก้ไขได้เฉพาะเจ้าของ
    </p>
@endif
