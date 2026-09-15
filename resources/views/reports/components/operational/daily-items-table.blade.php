{{--
    ตารางสรุปรายวันระดับรายการงาน — ใช้ร่วมกันระหว่าง Overview (หนึ่งสัปดาห์) และหน้าฉบับเต็ม (ทั้งเดือน)
    แถวมาจาก OperationalWorkloadReportService::rowFor() ชุดเดียวกับไฟล์ CSV

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
                <th scope="col">สถานะ</th>
                <th scope="col" class="is-number">ชั่วโมง</th>
                <th scope="col">หมายเหตุ</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr data-operational-daily-row>
                    <td data-label="วันที่" class="is-date">{{ $row['date_label'] }}</td>
                    <th scope="row" data-label="รายการงาน">{{ $row['title'] }}</th>
                    <td data-label="ประเภทงาน">{{ $row['kind_label'] }}</td>
                    <td data-label="หมวดงาน">{{ $row['category'] }}</td>
                    <td data-label="สถานะ">
                        @include('reports.components.operational.status-text', ['tone' => $row['status_tone'], 'label' => $row['status_label']])
                    </td>
                    <td data-label="ชั่วโมง" class="is-number">{{ $row['hours'] }}</td>
                    <td data-label="หมายเหตุ" class="is-note">{{ $row['note'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="operational-table__empty">{{ $emptyText }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
