{{--
    ตารางภาระงานปฏิบัติการรายคน

    อ่านเทียบข้ามคนได้ในบรรทัดเดียว ซึ่งกราฟทำไม่ได้
    คอลัมน์ "ไม่ลงโปรเจกต์" เป็นตัวเลขที่ตอบคำถามหลักของรายงานนี้: เวลาของคนนี้
    หายไปกับงานปฏิบัติการเท่าไร ซึ่งเป็นเหตุผลที่งานโครงการไม่ขยับ
--}}
<article class="report-panel report-dashboard-card report-dashboard-card--departments report-operational-table" aria-labelledby="operational-member-table-title">
    <div class="report-panel__heading">
        <div>
            <h2 id="operational-member-table-title">ภาระงานปฏิบัติการรายคน</h2>
            <p>เรียงจากชั่วโมงมากไปน้อย ในช่วง {{ $filters['period_label'] }}</p>
        </div>
        <span class="report-panel__period">{{ $peopleCount }} คน</span>
    </div>

    @if($memberSummary->isEmpty())
        <p class="report-empty">
            <i class="bi bi-clipboard" aria-hidden="true"></i>
            <strong>ยังไม่มีบันทึกงานในช่วงเวลานี้</strong>
            <span>ลองเปลี่ยนช่วงเวลาหรือตัวกรอง</span>
        </p>
    @else
        {{--
            แบ่งหน้าฝั่งผู้ใช้ ครั้งละ 10 แถว

            แถวทั้งหมดถูก render มาแล้วเพราะ $memberSummary เป็นผลสรุปที่คำนวณใน
            หน่วยความจำ ไม่ใช่ paginator ของฐานข้อมูล การแบ่งหน้าที่เซิร์ฟเวอร์จึงจะ
            ต้องคำนวณสรุปทั้งชุดใหม่ทุกครั้งที่เปลี่ยนหน้าโดยไม่ได้ประหยัดอะไรเลย

            ปุ่มถูก render จาก Blade ไม่ใช่สร้างจาก JavaScript ตามแบบเดียวกับ
            ตัวแบ่งหน้าของการ์ดสรุปในปฏิทิน เพื่อไม่ให้มีเทมเพลตปุ่มชุดที่สอง
        --}}
        <div class="report-department-scroll">
            <table class="report-department-table" data-operational-member-table data-page-size="10">
                <thead>
                    <tr>
                        <th scope="col">ผู้บันทึก</th>
                        <th scope="col">แผนก</th>
                        <th scope="col" class="report-operational-table__number">ชั่วโมงรวม</th>
                        <th scope="col" class="report-operational-table__number">รายการ</th>
                        <th scope="col" class="report-operational-table__number">งานประจำ</th>
                        <th scope="col" class="report-operational-table__number">นอกสถานที่</th>
                        <th scope="col" class="report-operational-table__number">ไม่ลงโปรเจกต์</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($memberSummary as $member)
                        <tr data-operational-member-row>
                            <th scope="row"><a href="{{ route('reports.operational', array_merge(request()->except('owner'), ['owner' => $member['id']])) }}">{{ $member['name'] }}</a></th>
                            <td>{{ $member['department'] }}</td>
                            <td class="report-operational-table__number"><strong>{{ $member['hours_label'] }}</strong></td>
                            <td class="report-operational-table__number">{{ $member['count'] }}</td>
                            <td class="report-operational-table__number">{{ $member['routine'] }}</td>
                            <td class="report-operational-table__number">{{ $member['field'] }}</td>
                            <td class="report-operational-table__number">
                                {{ \App\Support\WorkLogDesign::durationLabel($member['unlinked_minutes']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <nav class="report-table-pager" data-operational-member-pager aria-label="เปลี่ยนหน้าตารางภาระงานรายคน" hidden>
            <button type="button" data-operational-member-previous aria-label="หน้าก่อนหน้า">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
            </button>
            <span data-operational-member-page role="status" aria-live="polite"></span>
            <button type="button" data-operational-member-next aria-label="หน้าถัดไป">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
        </nav>
    @endif
</article>
