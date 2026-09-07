{{--
    บล็อกภาระงานปฏิบัติการบนหน้ารายงานรายบุคคล

    ตั้งใจแยกจากตัวเลขผลงานโครงการด้านบนอย่างชัดเจน ไม่รวมเป็นก้อนเดียวกัน
    เพราะชั่วโมงงานปฏิบัติการไม่ใช่ผลงานโครงการ การรวมกันจะทำให้อัตราปิดงาน
    และความคืบหน้าของโครงการเพี้ยน สองบล็อกตอบคนละคำถาม:
    ด้านบนคือ "ทำโครงการได้ดีแค่ไหน" ที่นี่คือ "เวลาที่เหลือหายไปกับอะไร"

    เพราะเป็นคนละเรื่องกัน จึงต้องมีเส้นแบ่งและหัวข้อของตัวเองที่มองเห็นได้
    ไม่ใช่บรรทัดเล็ก ๆ ที่กลืนไปกับเนื้อหารอบข้าง และปุ่มไปหน้ารายงานเต็ม
    ต้องเป็นปุ่มจริงที่เห็นชัด ไม่ใช่ลิงก์ข้อความ
--}}
<section class="employee-operational" aria-labelledby="employee-operational-title">
    <header class="employee-operational__head">
        <div class="employee-operational__intro">
            <span class="employee-operational__eyebrow">
                <i class="bi bi-journal-check" aria-hidden="true"></i> Operational workload
            </span>
            <h2 id="employee-operational-title">งานปฏิบัติการของ {{ $employee->name }}</h2>
            <p>
                งานประจำ งานแทรก และงานนอกสถานที่ ที่ไม่ปรากฏบนบอร์ดโปรเจกต์ —
                นับแยกจากตัวเลขผลงานโครงการด้านบน
            </p>
        </div>

        <a href="{{ $operationalUrl }}" class="employee-operational__cta">
            <span>ดูรายงานภาระงานปฏิบัติการ</span>
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
    </header>

    @if($operational['count'] === 0)
        <div class="report-empty">
            <i class="bi bi-journal" aria-hidden="true"></i>
            <strong>ยังไม่มีบันทึกงานปฏิบัติการในช่วงนี้</strong>
            <span>เมื่อพนักงานบันทึกงานประจำวัน ตัวเลขจะขึ้นที่นี่</span>
        </div>
    @else
        <div class="employee-operational__stats">
            <div class="employee-operational__total">
                <span class="employee-operational__total-label">เวลาที่บันทึกไว้</span>
                <strong class="employee-operational__total-value">{{ $operational['hours_label'] }}</strong>
                <span class="employee-operational__total-note">
                    จาก {{ number_format($operational['count']) }} รายการ ·
                    ไม่ได้ลงโปรเจกต์
                    {{ \App\Support\WorkLogDesign::durationLabel($operational['unlinked_minutes']) }}
                </span>
            </div>

            <dl class="employee-operational__kinds">
                @foreach($operational['kinds'] as $kind)
                    <div class="employee-operational__kind employee-operational__kind--{{ $kind['tone'] }}">
                        <dt><i class="bi {{ $kind['icon'] }}" aria-hidden="true"></i> {{ $kind['label'] }}</dt>
                        <dd>
                            <strong>{{ $kind['hours_label'] }}</strong>
                            <span>{{ $kind['count'] }} รายการ</span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- ข้อมูลกราฟใช้ island แยกจากกราฟของงานโครงการ เพื่อไม่ให้สองโดเมน
             ปนกันแม้แต่ในชั้นข้อมูลที่ส่งไปฝั่ง client --}}
        <script type="application/json" id="employee-operational-chart-data">@json($operational['chart'])</script>

        <article class="employee-report__panel employee-operational__chart"
            data-report-chart data-chart-kind="stacked-bar" data-chart-state="loading">
            <div class="employee-report__panel-head">
                <div>
                    <h3>ชั่วโมงงานปฏิบัติการรายวัน</h3>
                    <p>แยกตามประเภทงาน — ช่วงที่แท่งสูงคือวันที่เวลาถูกดึงออกจากงานโครงการ</p>
                </div>
                <span>{{ $filters['period_label'] }}</span>
            </div>
            <div class="report-chart-shell">
                <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
                <div class="report-chart-wrap"><canvas id="employeeOperationalChart" aria-label="กราฟแท่งซ้อนชั่วโมงงานปฏิบัติการรายวัน" role="img">กราฟแท่งซ้อนชั่วโมงงานปฏิบัติการรายวัน</canvas></div>
                <div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>ยังไม่มีข้อมูลในช่วงเวลานี้</strong></div>
                <div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong></div>
            </div>
        </article>

        {{-- ตารางล่าสุดไล่เรียงตามวัน จำกัดจำนวนไว้เพราะเป็นภาพประกอบ
             ไม่ใช่รายการทั้งหมด ผู้ที่ต้องการดูครบใช้ปุ่มด้านบน --}}
        <article class="employee-report__panel employee-operational__table">
            <div class="employee-report__panel-head">
                <div>
                    <h3>บันทึกล่าสุด</h3>
                    <p>เรียงจากวันใหม่ไปเก่า แสดง {{ $operational['row_limit'] }} รายการล่าสุด</p>
                </div>
                <span>{{ number_format($operational['count']) }} รายการทั้งหมด</span>
            </div>

            <div class="employee-report__table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">วันที่</th>
                            <th scope="col">เวลา</th>
                            <th scope="col">ประเภท</th>
                            <th scope="col">หมวดงาน</th>
                            <th scope="col">งานที่ทำ</th>
                            <th scope="col">ใช้เวลา</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($operational['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['date']?->locale('th')->translatedFormat('j M') }}</th>
                                <td>{{ $row['time_range'] ?? '—' }}</td>
                                <td><span class="report-tag report-tone-{{ $row['kind_tone'] }}">{{ $row['kind_label'] }}</span></td>
                                <td>{{ $row['category'] ?? '—' }}</td>
                                <td>
                                    {{ $row['title'] }}
                                    @if($row['project'])
                                        <small>{{ $row['project'] }}</small>
                                    @endif
                                    @if(count($row['participants']) > 0)
                                        <small>ทำด้วยกันกับ {{ implode(', ', $row['participants']) }}</small>
                                    @endif
                                </td>
                                <td class="employee-operational__duration">{{ $row['duration_label'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </article>
    @endif
</section>
