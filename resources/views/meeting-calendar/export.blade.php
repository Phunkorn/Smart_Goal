<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
    @vite('resources/css/pages/meeting-calendar-export.css')
</head>
{{--
    เอกสารปฏิทินสำหรับสั่งพิมพ์หรือบันทึกเป็น PDF จากเบราว์เซอร์

    เป็นหน้า standalone ไม่ใช้ layouts.app เพราะเอกสารต้องไม่มีเมนู แถบข้าง หรือปุ่ม
    ของระบบติดไปในกระดาษ หัวเรื่อง การแยกส่วน และการจัดกลุ่มมาจาก
    CalendarPdfExportService แล้ว ส่วนนี้ไม่ตัดสินถ้อยคำและไม่เรียงลำดับใหม่
--}}
<body>
<div class="calendar-export">
    <header class="calendar-export__head">
        <h1>{{ $heading }}</h1>
        <p>ประเภท: {{ $typeLabel }} · ช่วงวันที่ {{ $rangeLabel }}</p>
    </header>

    <button class="calendar-export__print" type="button" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>

    @if ($events->isEmpty())
        <p class="calendar-export__empty">ไม่มีรายการในช่วงวันที่ที่เลือก</p>
    @else
        {{--
            เอกสารประชุมกับเอกสารนัดหมายแยกคนละส่วน แต่ละส่วนขึ้นหน้าใหม่

            สองอย่างนี้ถูกใช้ต่างกันหลังพิมพ์ — ประชุมเก็บเป็นหลักฐานของการประชุมนั้น
            นัดหมายเป็นตารางงานของคน การเรียงปนกันทำให้ต้องคัดแยกด้วยมือทุกครั้ง
            แยกตั้งแต่ในเอกสารจึงฉีกแจกได้เลย (ดู groupByType)

            ไม่มีคอลัมน์ "ประเภท" ในตารางอีกต่อไป เพราะทั้งส่วนเป็นประเภทเดียวกันแล้ว
            หัวข้อส่วนบอกไว้ครั้งเดียวพอ และคืนความกว้างให้คอลัมน์หัวข้อ
        --}}
        @foreach ($eventParts as $part)
            <section class="calendar-export__part">
                {{-- เอกสารที่มีส่วนเดียวไม่ต้องมีหัวข้อส่วนซ้ำกับหัวเรื่องของเอกสาร --}}
                @if ($eventParts->count() > 1)
                    <h2 class="calendar-export__part-title">{{ $part['typeLabel'] }}</h2>
                @endif

                {{--
                    ตารางสรุปของส่วนนี้ จัดกลุ่มตามวันด้วยแถวหัวข้อวัน

                    ทำไมเป็นตาราง: เอกสารนี้ถูกพิมพ์ลงกระดาษแล้วกวาดตาด้วยสายตา
                    ค่าที่อยู่ตรงคอลัมน์เดียวกันทุกแถวทำให้เทียบ "เวลา" หรือ "สถานที่"
                    ของหลายรายการได้ในพริบตา ซึ่งการ์ดรายตัวทำไม่ได้

                    ทำไมมีแถวหัวข้อวัน: คอลัมน์ "วันที่" เดิมซ้ำค่าเดิมทุกแถวของวันเดียวกัน
                    กินความกว้างที่ควรเป็นของหัวข้อไปเปล่า ๆ
                --}}
                <table class="calendar-export__table">
                    <thead>
                        <tr>
                            <th scope="col" class="calendar-export__col-title">หัวข้อ</th>
                            <th scope="col">เวลา</th>
                            <th scope="col">สถานที่</th>
                            <th scope="col">ผู้สร้าง</th>
                            <th scope="col">ผู้เข้าร่วม</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($part['eventDays'] as $day)
                            <tr class="calendar-export__day-row">
                                <th scope="colgroup" colspan="5">{{ $day['dateLabel'] }}</th>
                            </tr>

                            @foreach ($day['events'] as $event)
                                <tr>
                                    <td class="calendar-export__col-title">
                                        <strong class="calendar-export__title">{{ $event['title'] }}</strong>

                                        {{--
                                            รายละเอียดเกาะอยู่ใต้หัวข้อของตัวเอง ไม่ใช่คอลัมน์แยก
                                            เพราะเป็นข้อความยาวที่ความกว้างไม่แน่นอน ถ้าให้เป็น
                                            คอลัมน์ มันจะบีบคอลัมน์อื่นทุกแถวแม้แถวที่ไม่มีรายละเอียด
                                        --}}
                                        @if (filled($event['detail'] ?? null))
                                            <span class="calendar-export__detail">{{ $event['detail'] }}</span>
                                        @endif
                                    </td>
                                    <td class="calendar-export__col-time">{{ \App\Support\ThaiDate::timeRangeLabel($event['startTime'], $event['endTime']) }}</td>
                                    <td>{{ $event['location'] ?: 'ไม่ระบุสถานที่' }}</td>
                                    <td>{{ $event['owner'] }}</td>
                                    <td class="calendar-export__col-count">{{ $event['attendeeCount'] }} คน</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>

                <p class="calendar-export__total">รวม {{ $part['events']->count() }} รายการ</p>

                {{--
                    รายงานรายรายการ — หนึ่งรายการหนึ่งหน้ากระดาษ

                    ตารางสรุปข้างบนตอบว่า "ช่วงนี้มีอะไร" ส่วนนี้ตอบว่า "รายการนี้มีใคร"
                    ซึ่งตารางสรุปตอบไม่ได้ เพราะรายชื่อสิบคนในช่องเดียวจะดันแถวสูง
                    จนตารางเสียรูป แยกเป็นหน้าของตัวเองจึงได้ที่พอให้รายชื่อครบ
                    และมีช่องให้เซ็นจริง

                    รายการที่ไม่มีผู้เข้าร่วมก็ยังได้หน้าของตัวเอง ไม่งั้นจำนวนหน้าของ
                    เอกสารเดาไม่ได้ และคนที่ถือกระดาษจะไม่รู้ว่าหน้าหายหรือไม่มีตั้งแต่ต้น
                --}}
                @foreach ($part['events'] as $itemIndex => $event)
                    @php($attendees = $event['attendees'] ?? [])

                    <section class="calendar-export__item">
                        <h3 class="calendar-export__item-title">
                            <span class="calendar-export__item-index">{{ $itemIndex + 1 }}.</span>
                            {{ $event['title'] }}
                        </h3>

                        {{--
                            ใช้ ThaiDate::longDay() ไม่ใช่ weekdayLongDay()

                            weekdayLongDay() ถูกสงวนให้หัวข้อวันของตารางสรุป ซึ่งมีได้
                            วันละครั้งและทำหน้าที่บอกว่า "ขึ้นวันใหม่" ถ้าบล็อกนี้ใช้
                            รูปแบบเดียวกัน ป้ายเดียวกันจะปรากฏซ้ำหลายที่จนความหมายนั้นหาย
                        --}}
                        <dl class="calendar-export__item-meta">
                            <div>
                                <dt>วันที่</dt>
                                <dd>{{ \App\Support\ThaiDate::longDay(\Carbon\CarbonImmutable::parse($event['date'])) }}</dd>
                            </div>
                            <div>
                                <dt>เวลา</dt>
                                <dd>{{ \App\Support\ThaiDate::timeRangeLabel($event['startTime'], $event['endTime']) }}</dd>
                            </div>
                            <div>
                                <dt>สถานที่</dt>
                                <dd>{{ $event['location'] ?: 'ไม่ระบุสถานที่' }}</dd>
                            </div>
                            <div>
                                <dt>ผู้สร้าง</dt>
                                <dd>{{ $event['owner'] }}</dd>
                            </div>
                        </dl>

                        @if (filled($event['detail'] ?? null))
                            <p class="calendar-export__item-detail">{{ $event['detail'] }}</p>
                        @endif

                        <h4 class="calendar-export__roster-title">
                            ผู้ที่ถูกเชิญ ({{ $event['attendeeCount'] }} คน)
                        </h4>

                        @if ($attendees === [])
                            <p class="calendar-export__empty">ยังไม่มีผู้เข้าร่วม</p>
                        @else
                            {{--
                                ใช้คลาส .calendar-export__table เดิมซ้ำ จึงได้หัวตารางที่ซ้ำ
                                ทุกหน้ากระดาษ และกฎห้ามหน้ากระดาษผ่ากลางแถวมาด้วยทั้งชุด
                                (สำคัญเมื่อรายชื่อสี่สิบคนยาวข้ามหน้า)

                                ไม่มีคอลัมน์สถานะตอบรับ — เอกสารตอบว่า "ใครถูกเชิญ"
                                ส่วนใครมาจริงรู้จากลายเซ็นในช่องสุดท้าย คำตอบรายคน
                                ดูได้ที่หน้ารายละเอียดของรายการนั้น ไม่ใช่บนกระดาษ
                            --}}
                            <table class="calendar-export__table">
                                <thead>
                                    <tr>
                                        <th scope="col" class="calendar-export__col-no">ลำดับ</th>
                                        <th scope="col">ชื่อ</th>
                                        <th scope="col">บทบาท · แผนก</th>
                                        <th scope="col" class="calendar-export__col-sign">ลงชื่อเข้าร่วม</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($attendees as $rosterIndex => $attendee)
                                        <tr>
                                            <td class="calendar-export__col-no">{{ $rosterIndex + 1 }}</td>
                                            <td>{{ $attendee['name'] }}</td>
                                            <td>{{ $attendee['roleLabel'] }}</td>
                                            {{-- ช่องว่างสำหรับเซ็นจริงตอนประชุม ต้องไม่มีข้อความข้างใน --}}
                                            <td class="calendar-export__col-sign"></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </section>
                @endforeach
            </section>
        @endforeach
    @endif
</div>
</body>
</html>
