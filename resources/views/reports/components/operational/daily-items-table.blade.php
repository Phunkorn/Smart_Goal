{{--
    ตารางสรุปรายวันระดับรายการงาน — ใช้ร่วมกันระหว่าง Overview (หนึ่งสัปดาห์) และหน้าฉบับเต็ม (ทั้งเดือน)
    แถวมาจาก OperationalWorkloadReportService::rowFor() ชุดเดียวกับไฟล์ CSV
    ลำดับคอลัมน์ต้องตรงกับ OperationalWorkloadReportService::DAILY_HEADERS

    @param Collection $rows
    @param string $caption
    @param string $emptyText
--}}
<div class="operational-table-scroll">
    <table class="operational-table operational-table--daily" data-operational-daily-table>
        <caption class="visually-hidden">{{ $caption }}</caption>
        <thead>
            <tr>
                <th scope="col">วันที่</th>
                <th scope="col">รายการงาน</th>
                <th scope="col">ประเภทงาน</th>
                <th scope="col">หมวดงาน</th>
                <th scope="col">เวลาที่ตั้งไว้</th>
                <th scope="col">เวลาเริ่ม</th>
                <th scope="col">เวลาเสร็จ</th>
                <th scope="col">สถานะ</th>
                <th scope="col" class="is-number">ชั่วโมง</th>
                <th scope="col">ผู้ร่วมงาน</th>
                <th scope="col">สถานที่ / ผู้แจ้ง</th>
                <th scope="col">เหตุผล</th>
                <th scope="col">หมายเหตุ</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr data-operational-daily-row>
                    <td data-label="วันที่" class="is-date">{{ $row['date_label'] }}</td>
                    <th scope="row" data-label="รายการงาน">
                        {{ $row['title'] }}
                        {{-- รายการที่มาจากรอบตรวจเช็กคอมพิวเตอร์เปิดแบบตรวจย้อนหลังได้จากที่นี่
                             คอลัมน์ของ CSV ไม่เปลี่ยน เพราะค่านี้ไม่ได้อยู่ใน dailyCells() --}}
                        @if($row['inspection'] ?? null)
                            <button type="button" class="operational-table__inspection"
                                data-cc-report-open
                                data-report-url="{{ $row['inspection']['report_url'] }}"
                                data-report-title="{{ $row['inspection']['label'] }}"
                                data-report-meta="{{ $row['inspection']['meta'] }}">
                                <i class="bi bi-file-earmark-text" aria-hidden="true"></i> ดูแบบตรวจ
                            </button>
                        @endif
                    </th>
                    <td data-label="ประเภทงาน">{{ $row['kind_label'] }}</td>
                    <td data-label="หมวดงาน">{{ $row['category'] }}</td>
                    <td data-label="เวลาที่ตั้งไว้" class="is-time">{{ $row['planned_time'] }}</td>
                    <td data-label="เวลาเริ่ม" class="is-time">{{ $row['start_time'] }}</td>
                    <td data-label="เวลาเสร็จ" class="is-time">{{ $row['end_time'] }}</td>
                    <td data-label="สถานะ">
                        @include('reports.components.operational.status-text', ['tone' => $row['status_tone'], 'label' => $row['status_label']])
                    </td>
                    <td data-label="ชั่วโมง" class="is-number">{{ $row['hours'] }}</td>
                    <td data-label="ผู้ร่วมงาน" class="is-people">{{ $row['coworkers'] }}</td>
                    <td data-label="สถานที่ / ผู้แจ้ง" class="is-place">{{ $row['place'] }}</td>
                    <td data-label="เหตุผล" class="is-note">{{ $row['reason'] }}</td>
                    <td data-label="หมายเหตุ" class="is-note">{{ $row['note'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="13" class="operational-table__empty">{{ $emptyText }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
