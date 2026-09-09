{{--
    ตารางการตรวจงานประจำ

    ทำหน้าที่สองอย่างในตารางเดียว:
    1. ตอบคำถามของเช้าวันนี้ — "เหลืออะไรที่ยังไม่ได้ตรวจ" ผ่านตัวนับของวันนี้
       ที่หัวตาราง และแถวของวันนี้ที่อยู่บนสุด
    2. เป็นหลักฐานย้อนหลัง — เวลามีคนขอดูว่าที่ผ่านมาทำอะไรไปบ้าง ตารางนี้คือสิ่งที่
       หยิบให้ดูได้ทันที จึงมีคอลัมน์วันที่ และอยู่ท้ายหน้าซึ่งเป็นที่ของรายละเอียด
       ที่เปิดดูเมื่อถูกถาม ไม่ใช่ตัวเลขสรุปที่ต้องเห็นทันทีที่เปิดหน้า

    แถวที่ยังไม่ตรวจถูกดันขึ้นบนสุดของวันและทำสีแดง เพราะเป็นสิ่งเดียวในตารางนี้ที่
    ต้องลงมือต่อ สีไม่ใช่สัญญาณเดียว — มีทั้งไอคอนและข้อความกำกับ

    แบ่งหน้าครั้งละ 10 แถวด้วย table-pager.js ตัวเดียวกับตารางภาระงานรายคน
    ปุ่มถูก render จาก Blade ไม่ใช่สร้างจาก JavaScript จึงไม่มีเทมเพลตปุ่มชุดที่สอง
--}}
<section class="report-panel report-checklist" aria-labelledby="today-checklist-title">
    <div class="report-panel__heading">
        <div>
            <h2 id="today-checklist-title">การตรวจงานประจำ</h2>
            <p>
                ใช้เป็นหลักฐานย้อนหลังได้ว่าวันไหนตรวจอะไรไปแล้วบ้าง
                — รายการในช่วง {{ $filters['period_label'] }}
            </p>
        </div>

        @if($todayChecklist['today_total'] > 0)
            {{-- ตัวนับเป็นของ "วันนี้" เสมอ ไม่เปลี่ยนตามช่วงเวลาที่เลือกด้านบน
                 เพราะคำถามของวันนี้ไม่ควรเปลี่ยนไปตามช่วงที่เลือกดูย้อนหลัง --}}
            <div class="report-checklist__score @if($todayChecklist['is_complete']) is-complete @elseif($todayChecklist['today_pending'] > 0) is-pending @endif">
                <strong>{{ number_format($todayChecklist['today_done']) }}</strong>
                <span>/ {{ number_format($todayChecklist['today_total']) }} ตรวจแล้ววันนี้</span>
            </div>
        @endif
    </div>

    @if($todayChecklist['total'] === 0)
        <p class="report-checklist__empty">
            <i class="bi bi-clipboard" aria-hidden="true"></i>
            ยังไม่มีการตรวจงานประจำในช่วงนี้ — ตั้งงานประจำได้ที่หน้าบันทึกงานประจำวัน
        </p>
    @else
        <div class="report-department-scroll">
            <table class="report-department-table report-checklist__table"
                data-checklist-table
                data-page-size="{{ $todayChecklist['page_size'] }}">
                <caption class="visually-hidden">รายการการตรวจงานประจำ พร้อมวันที่และสถานะ</caption>
                <thead>
                    <tr>
                        <th scope="col">วันที่</th>
                        <th scope="col">งานที่ต้องตรวจ</th>
                        <th scope="col">ช่วงเวลา</th>
                        @if($showChecklistOwner)
                            <th scope="col">ผู้รับผิดชอบ</th>
                        @endif
                        <th scope="col">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($todayChecklist['rows'] as $row)
                        <tr data-checklist-row
                            class="@unless($row['is_done']) report-checklist__row--pending @endunless">
                            <td class="report-checklist__date">
                                {{ $row['date_label'] }}
                                @if($row['is_today'])
                                    <span class="report-checklist__today">วันนี้</span>
                                @endif
                            </td>
                            <td class="report-checklist__task">
                                {{ $row['title'] }}
                                @if($row['category'])
                                    <small class="report-checklist__category">{{ $row['category'] }}</small>
                                @endif
                            </td>
                            <td class="report-checklist__window">{{ $row['window'] ?? 'ไม่ระบุเวลา' }}</td>
                            @if($showChecklistOwner)
                                <td>{{ $row['owner'] }}</td>
                            @endif
                            <td>
                                @if($row['is_done'])
                                    <span class="report-checklist__status report-checklist__status--done">
                                        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                                        ตรวจแล้ว@if($row['done_at']) · {{ $row['done_at'] }}@endif
                                    </span>
                                @elseif($row['status'] === 'skipped')
                                    <span class="report-checklist__status report-checklist__status--skipped">
                                        <i class="bi bi-calendar-x" aria-hidden="true"></i> ไม่ได้ทำวันนี้
                                    </span>
                                @elseif($row['status'] === 'in_progress')
                                    <span class="report-checklist__status report-checklist__status--running">
                                        <i class="bi bi-play-circle-fill" aria-hidden="true"></i> กำลังทำ
                                    </span>
                                @elseif($row['status'] === 'overdue')
                                    <span class="report-checklist__status report-checklist__status--pending">
                                        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> เกินเวลา
                                    </span>
                                @else
                                    <span class="report-checklist__status report-checklist__status--pending">
                                        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                                        ยังไม่ตรวจ
                                    </span>
                                @endif
                                @if($row['reason'])<small class="report-checklist__reason">{{ $row['reason'] }}</small>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <nav class="report-table-pager" data-checklist-pager aria-label="เปลี่ยนหน้าตารางการตรวจงานประจำ" hidden>
            <button type="button" data-checklist-previous aria-label="หน้าก่อนหน้า">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
            </button>
            <span data-checklist-page role="status" aria-live="polite"></span>
            <button type="button" data-checklist-next aria-label="หน้าถัดไป">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
        </nav>
    @endif
</section>
