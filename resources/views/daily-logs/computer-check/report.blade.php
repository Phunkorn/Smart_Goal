<!DOCTYPE html>
<html lang="th">

{{--
    แบบตรวจเช็กคอมพิวเตอร์ประจำวัน — A4 แนวตั้ง

    สร้างจากข้อมูลที่บันทึกไว้จริงใน computer_check_results ของรอบนั้น
    ไม่ใช่ภาพหน้าจอ และไม่อ่านสถานะปัจจุบันของเครื่องมาแทนผลย้อนหลัง
    (ดู ComputerCheckReportService เรื่องการเปิดย้อนหลัง)

    ใช้ layout ของตัวเองแทน layouts.app เพราะหน้านี้ต้องพิมพ์ลงกระดาษและถูกฝังใน
    iframe ของกล่องพรีวิว เมนูข้าง แถบบน และสคริปต์ของหน้าปกติไม่ควรติดไปด้วย

    ผังในแบบตรวจเรียก partial ตัวเดียวกับผังบนเว็บ ต่างกันแค่ mode='print'
    ตำแหน่งโต๊ะจึงตรงกันเสมอตามข้อกำหนดใน CLAUDE.md
--}}

@php
    use App\Support\ComputerCheckDesign;
@endphp

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แบบตรวจเช็กคอมพิวเตอร์ประจำวัน {{ $header['date_label'] }} {{ $header['shift_label'] }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite('resources/css/pages/computer-check-report.css')
</head>

{{-- คลาสเสริมเขียนเป็นนิพจน์ ไม่ใช่ @if ติดกับคำหน้า เพราะ Blade ไม่อ่าน
     directive ที่มีตัวอักษรนำหน้าทันที แล้วจะเหลือ @endif ลอยจนคอมไพล์ไม่ผ่าน --}}
<body class="cc-report {{ $embedded ? 'cc-report--embedded' : '' }}" data-cc-report-page>

    @unless($embedded)
        {{-- แถบนี้ไม่ถูกพิมพ์ (ดู @media print) และไม่แสดงตอนฝังใน iframe
             เพราะกล่องพรีวิวมีปุ่มของตัวเองอยู่แล้ว --}}
        <div class="cc-report__toolbar">
            <a href="{{ route('daily-logs.computer-check.index', ['session' => $session->id]) }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> กลับไปหน้าตรวจ
            </a>
            <button type="button" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
        </div>
    @endunless

    <main class="cc-sheet">
        <header class="cc-sheet__head">
            <p class="cc-sheet__form-code">{{ $header['form_code'] }}</p>
            <h1 class="cc-sheet__title">ตรวจเช็กคอมพิวเตอร์ประจำวัน</h1>
            <p class="cc-sheet__department">{{ ComputerCheckDesign::LOG_CATEGORY }} · ห้อง {{ $header['room'] }}</p>
        </header>

        <section class="cc-sheet__meta">
            <dl class="cc-sheet__fields">
                <div><dt>วันที่</dt><dd>{{ $header['date_label'] }}</dd></div>
                <div><dt>ผู้ตรวจคนที่ 1</dt><dd>{{ $header['checker_1'] }}</dd></div>
                <div><dt>ผู้ตรวจคนที่ 2</dt><dd>{{ $header['checker_2'] }}</dd></div>
                <div><dt>เวลา</dt><dd>{{ $header['started_label'] }} - {{ $header['completed_label'] }}</dd></div>
            </dl>

            {{-- ช่องรอบ ติ๊กเหมือนกระดาษจริง ใช้กล่องกับเครื่องหมายถูก ไม่พึ่งสี
                 เพราะแบบตรวจส่วนใหญ่ถูกพิมพ์ขาวดำ --}}
            <div class="cc-sheet__shift">
                @foreach(ComputerCheckDesign::SHIFTS as $key => $shift)
                    <span class="cc-sheet__shift-box">
                        {{ $key }}
                        <span class="cc-sheet__tick @if($header['shift'] === $key) is-on @endif" aria-hidden="true">
                            @if($header['shift'] === $key)✓@endif
                        </span>
                        <span class="visually-hidden">
                            {{ $shift['label'] }} {{ $header['shift'] === $key ? '(เลือก)' : '(ไม่เลือก)' }}
                        </span>
                    </span>
                @endforeach
            </div>
        </section>

        {{-- Legend ของตัวอักษรย่อในช่องบนโต๊ะ ต้องอยู่เหนือผังเสมอ
             ไม่งั้นช่อง K/S/M/I/C บนผังจะอ่านไม่ออกว่าหมายถึงอะไร --}}
        <section class="cc-sheet__legend" aria-label="คำอธิบายสัญลักษณ์">
            <ul class="cc-sheet__legend-devices">
                @foreach(ComputerCheckDesign::DEVICES as $device)
                    <li><strong>{{ $device['letter'] }}</strong> = {{ $device['label'] }}</li>
                @endforeach
            </ul>
            <ul class="cc-sheet__legend-marks">
                @foreach(ComputerCheckDesign::DEVICE_STATUSES as $status)
                    <li><strong>{{ $status['mark'] }}</strong> = {{ $status['label'] }}</li>
                @endforeach
            </ul>
        </section>

        <section class="cc-sheet__plan" aria-label="ผังห้อง {{ $header['room'] }}">
            @include('daily-logs.computer-check.partials.floor-plan', [
                'stations' => $stations,
                'mode' => 'print',
            ])
        </section>

        <section class="cc-sheet__summary" aria-label="สรุปผลการตรวจ">
            <table class="cc-sheet__summary-table">
                <caption class="visually-hidden">สรุปผลการตรวจ</caption>
                <tbody>
                    <tr>
                        <th scope="row">จำนวนเครื่องทั้งหมด</th><td>{{ $summary['total'] }}</td>
                        <th scope="row">ตรวจแล้ว</th><td>{{ $summary['checked'] }}</td>
                        <th scope="row">ปกติ</th><td>{{ $summary['normal'] }}</td>
                        <th scope="row">มีปัญหา</th><td>{{ $summary['issue'] }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        @if($issues !== [])
            <section class="cc-sheet__issues" aria-labelledby="ccSheetIssues">
                <h2 class="cc-sheet__issues-heading" id="ccSheetIssues">รายการปัญหา</h2>
                <table class="cc-sheet__issues-table">
                    <thead>
                        <tr>
                            <th scope="col">เครื่อง</th>
                            <th scope="col">อุปกรณ์</th>
                            <th scope="col">รายละเอียด</th>
                            <th scope="col">ผู้ตรวจ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($issues as $issue)
                            <tr>
                                <td>{{ $issue['code'] }}</td>
                                <td>{{ implode(', ', $issue['devices']) }}</td>
                                <td>{{ $issue['note'] }}</td>
                                <td>{{ $issue['checked_by'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <footer class="cc-sheet__foot">
            <span>แบบฟอร์ม {{ $header['form_code'] }}</span>
            <span>{{ $header['shift_label'] }}</span>
            <span>สถานะ: {{ $header['status_label'] }}</span>
        </footer>
    </main>
</body>

</html>
