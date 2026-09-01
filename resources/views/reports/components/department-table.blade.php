{{--
    ตารางเปรียบเทียบแผนก

    แทนกราฟแท่งสามอันเดิม (ผลงานแต่ละแผนก / อัตราตรงเวลา / ภาระงาน) ที่ล้วนเป็นการเทียบรายแผนก
    ทำให้ต้องกวาดตาสามที่เพื่อประกอบภาพเดียว ตารางอ่านเทียบข้ามแผนกได้ในบรรทัดเดียว
    และตัวเลขจริงอยู่ข้างแถบสัดส่วนเสมอ ไม่ต้องกะจากความยาวแถบ

    ใช้ $departmentSummary ที่ AdminReportService คำนวณไว้อยู่แล้ว ไม่มีคิวรีเพิ่ม
--}}
<article class="report-panel report-dashboard-card report-dashboard-card--departments" aria-labelledby="department-table-title">
    <div class="report-panel__heading">
        <div>
            <h2 id="department-table-title">เทียบผลงานรายแผนก</h2>
            <p>ดูพร้อมกันว่าแผนกไหนงานเยอะ ปิดงานได้ดี และมีงานล่าช้าค้างอยู่</p>
        </div>
        <span class="report-panel__period">{{ $departmentSummary->count() }} แผนก</span>
    </div>

    @if($departmentSummary->isEmpty())
        <div class="report-empty">
            <i class="bi bi-diagram-3" aria-hidden="true"></i>
            <strong>ยังไม่มีข้อมูลแผนกในช่วงเวลานี้</strong>
            <span>ลองเปลี่ยนช่วงเวลาหรือตัวกรอง</span>
        </div>
    @else
        <div class="report-department-scroll">
            <table class="report-department-table">
                <thead>
                    <tr>
                        <th scope="col">แผนก</th>
                        <th scope="col">งานทั้งหมด</th>
                        <th scope="col">ปิดงานได้</th>
                        <th scope="col">ส่งตรงเวลา</th>
                        <th scope="col">ล่าช้าอยู่</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($departmentSummary as $department)
                        <tr>
                            <th scope="row">
                                <strong>{{ $department['name'] }}</strong>
                                <small>{{ $department['employees'] }} คน</small>
                            </th>
                            <td>
                                <strong>{{ number_format($department['total']) }}</strong>
                                <small>กำลังทำอยู่ {{ number_format($department['active']) }}</small>
                            </td>
                            <td>
                                <div class="report-department-metric">
                                    <span><strong>{{ $department['rate'] }}%</strong> <small>{{ number_format($department['done']) }} งาน</small></span>
                                    <span class="report-meter" style="--report-meter-tone:#059669" role="img" aria-label="ปิดงานได้ {{ $department['rate'] }} เปอร์เซ็นต์"><span style="width:{{ $department['rate'] }}%"></span></span>
                                </div>
                            </td>
                            <td>
                                @if($department['on_time_eligible'] === 0)
                                    <span class="report-department-none">ไม่มีงานที่กำหนดส่ง</span>
                                @else
                                    <div class="report-department-metric">
                                        <span><strong>{{ $department['on_time_rate'] }}%</strong> <small>จาก {{ number_format($department['on_time_eligible']) }} งาน</small></span>
                                        <span class="report-meter" style="--report-meter-tone:#1d4ed8" role="img" aria-label="ส่งตรงเวลา {{ $department['on_time_rate'] }} เปอร์เซ็นต์"><span style="width:{{ $department['on_time_rate'] }}%"></span></span>
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($department['overdue'] > 0)
                                    {{-- สื่อด้วยไอคอนและข้อความควบคู่กับสี ไม่ใช้สีอย่างเดียว --}}
                                    <span class="report-department-late is-late"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>{{ number_format($department['overdue']) }} งาน</span>
                                @else
                                    <span class="report-department-late"><i class="bi bi-check2" aria-hidden="true"></i>ไม่มี</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</article>
