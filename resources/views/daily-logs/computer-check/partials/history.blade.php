{{--
    ประวัติการตรวจ — รอบที่ผ่านมาที่ผู้ใช้คนนี้มีสิทธิ์เปิดดู

    แถวถูกกรองด้วย ComputerCheckSessionPolicy ที่ controller แล้ว ที่นี่ไม่ตัดสิน
    สิทธิ์ซ้ำ กดดูแบบตรวจแล้วเปิดกล่องพรีวิวชุดเดียวกับที่หน้ารายงานปฏิบัติงานใช้

    @param array $history
--}}
<div class="cc-history">
    <div class="cc-history__scroll">
        <table class="cc-history__table">
            <caption class="visually-hidden">ประวัติการตรวจเช็กคอมพิวเตอร์ย้อนหลัง</caption>
            <thead>
                <tr>
                    <th scope="col">วันที่</th>
                    <th scope="col">รอบ</th>
                    <th scope="col">ผู้ตรวจ</th>
                    <th scope="col" class="is-number">เครื่อง</th>
                    <th scope="col" class="is-number">ปกติ</th>
                    <th scope="col" class="is-number">มีปัญหา</th>
                    <th scope="col">เวลา</th>
                    <th scope="col">แบบตรวจ</th>
                </tr>
            </thead>
            <tbody>
                @forelse($history as $row)
                    <tr>
                        <td data-label="วันที่">{{ $row['date_label'] }}</td>
                        <td data-label="รอบ">{{ $row['shift_label'] }}</td>
                        <td data-label="ผู้ตรวจ">{{ $row['checkers'] }}</td>
                        <td data-label="เครื่อง" class="is-number">{{ $row['total'] }}</td>
                        <td data-label="ปกติ" class="is-number">{{ $row['normal'] }}</td>
                        <td data-label="มีปัญหา" class="is-number">{{ $row['issue'] }}</td>
                        <td data-label="เวลา">{{ $row['time_range'] }}</td>
                        <td data-label="แบบตรวจ">
                            @if($row['status'] === 'completed')
                                <button type="button" class="cc-history__action"
                                    data-cc-report-open
                                    data-report-url="{{ route('daily-logs.computer-check.report', ['session' => $row['id']]) }}"
                                    data-report-title="แบบตรวจเช็กคอมพิวเตอร์ประจำวัน"
                                    data-report-meta="{{ $row['date_label'] }} · {{ $row['shift_label'] }}">
                                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i> ดูแบบตรวจ
                                </button>
                            @else
                                <a class="cc-history__action cc-history__action--resume"
                                    href="{{ route('daily-logs.computer-check.index', ['session' => $row['id']]) }}">
                                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i> ตรวจต่อ
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="cc-empty">ยังไม่มีประวัติการตรวจ</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
