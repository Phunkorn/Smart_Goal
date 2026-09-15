{{--
    งานที่ทำบ่อยที่สุด — Overview แสดง 5 อันดับ หน้าฉบับเต็มแสดงทั้งหมด
    แถวมาจาก OperationalWorkloadReportService::frequentWork() ชุดเดียวกับไฟล์ CSV
--}}
<div class="operational-table-scroll">
    <table class="operational-table operational-table--frequent" data-operational-frequent-table>
        <caption class="visually-hidden">งานที่ทำบ่อยที่สุด เรียงตามจำนวนครั้ง พร้อมเวลาที่ใช้</caption>
        <thead>
            <tr>
                <th scope="col" class="is-rank">อันดับ</th>
                <th scope="col">ลักษณะงาน</th>
                <th scope="col" class="is-number">จำนวนครั้ง</th>
                <th scope="col" class="is-number">เวลาที่ใช้</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr data-operational-frequent-row>
                    <td data-label="อันดับ" class="is-rank">{{ $row['rank'] }}</td>
                    <th scope="row" data-label="ลักษณะงาน">{{ $row['title'] }}</th>
                    <td data-label="จำนวนครั้ง" class="is-number">{{ number_format($row['count']) }} ครั้ง</td>
                    <td data-label="เวลาที่ใช้" class="is-number">{{ $row['hours_text'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="operational-table__empty">ยังไม่มีงานที่บันทึกในเดือนนี้</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
