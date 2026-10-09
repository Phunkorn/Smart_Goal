{{--
    หัวหน้าจอ — ข้อมูลหัวแบบตรวจและตัวเลขความคืบหน้า

    สองสถานะ:
      1. ยังไม่เปิดรอบ — ฟอร์มเลือกวันที่/รอบ/ผู้ตรวจ แล้วกดเริ่ม
      2. เปิดรอบแล้ว   — ข้อมูลหัวแบบตรวจและความคืบหน้าแบบอ่านอย่างเดียว

    ตัวเลขทุกตัวมาจาก ComputerCheckService::summarize() ฝั่งเซิร์ฟเวอร์
    JavaScript ไม่นับเองเพื่อไม่ให้ค่อย ๆ เพี้ยนออกจากฐานข้อมูล
--}}
@php
    use App\Support\ComputerCheckDesign;
@endphp

<header class="cc-head">
    <div class="cc-head__identity">
        <span class="cc-head__icon" aria-hidden="true"><i class="bi bi-pc-display"></i></span>
        <div>
            <p class="cc-head__eyebrow">{{ ComputerCheckDesign::LOG_CATEGORY }} · แบบฟอร์ม {{ ComputerCheckDesign::FORM_CODE }}</p>
            <h1 class="cc-head__title">ตรวจเช็กคอมพิวเตอร์ประจำวัน</h1>
            <p class="cc-head__subtitle">ตรวจอุปกรณ์และระบบการใช้งานคอมพิวเตอร์ภายในห้อง {{ ComputerCheckDesign::ROOM_NAME }}</p>
        </div>
    </div>

    @if($session === null)
        {{-- ยังไม่เปิดรอบของวัน/กะนี้ --}}
        <form class="cc-start" method="POST" action="{{ route('daily-logs.computer-check.store') }}" data-cc-start-form>
            @csrf

            <div class="cc-start__field">
                <label class="form-label" for="ccCheckDate">วันที่ตรวจ</label>
                <input type="date" class="form-control" id="ccCheckDate" name="check_date"
                    value="{{ $dateValue }}" min="{{ $minDate }}" max="{{ $maxDate }}" required>
            </div>

            <fieldset class="cc-start__field cc-start__shift">
                <legend class="form-label">รอบ</legend>
                <div class="cc-shift">
                    @foreach(ComputerCheckDesign::SHIFTS as $key => $shift)
                        <label class="cc-shift__option">
                            <input type="radio" name="shift" value="{{ $key }}" @checked($key === $shiftValue)>
                            <span>{{ $shift['label'] }} ({{ $key }})</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="cc-start__field">
                <label class="form-label" for="ccChecker1">ผู้ตรวจคนที่ 1</label>
                {{-- ผู้ตรวจคนที่ 1 คือคนที่ล็อกอินอยู่เสมอ เซิร์ฟเวอร์เป็นคนกำหนด
                     ช่องนี้จึงเป็นการแสดงผล ไม่ใช่ช่องกรอกที่ส่งค่าไปด้วย --}}
                <input type="text" class="form-control" id="ccChecker1" value="{{ $viewer->name }}" readonly>
            </div>

            <div class="cc-start__field">
                <label class="form-label" for="ccChecker2">ผู้ตรวจคนที่ 2</label>
                <select class="form-select" id="ccChecker2" name="checker_2_id">
                    <option value="">ไม่ระบุ</option>
                    @foreach($checkerOptions as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="cc-start__action">
                @if($capabilities['canCreate'])
                    <button type="submit" class="btn btn-primary" data-cc-start-submit>
                        <i class="bi bi-play-fill" aria-hidden="true"></i> เริ่มตรวจ
                    </button>
                @else
                    <p class="cc-start__denied">บัญชีของคุณไม่มีสิทธิ์เปิดรอบตรวจ</p>
                @endif
            </div>

            <p class="cc-start__hint">เวลาเริ่มตรวจถูกบันทึกอัตโนมัติเมื่อกดเริ่ม</p>
            <p class="cc-panel__error" role="alert" data-cc-start-error hidden></p>
        </form>
    @else
        <div class="cc-session">
            <dl class="cc-session__facts">
                <div><dt>วันที่ตรวจ</dt><dd>{{ $header['date_label'] }}</dd></div>
                <div><dt>รอบ</dt><dd>{{ $header['shift_label'] }}</dd></div>
                <div><dt>ผู้ตรวจคนที่ 1</dt><dd>{{ $header['checker_1'] }}</dd></div>
                <div><dt>ผู้ตรวจคนที่ 2</dt><dd>{{ $header['checker_2'] }}</dd></div>
                <div><dt>เวลา</dt><dd>{{ $header['started_label'] }} - {{ $header['completed_label'] }}</dd></div>
            </dl>

            <span class="cc-session__status cc-session__status--{{ $header['status'] }}">
                {{ $header['status_label'] }}
            </span>
        </div>
    @endif

    @include('daily-logs.computer-check.partials.progress', ['summary' => $summary])
</header>
