<section class="report-panel report-departments" aria-labelledby="department-performance-title">
    <div class="report-panel__heading">
        <div><h2 id="department-performance-title">ประสิทธิภาพรายแผนก</h2><p>จำนวนพนักงานที่ใช้งานอยู่ งานในช่วงที่เลือก และอัตราสำเร็จ</p></div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle report-departments__table">
            <thead><tr><th scope="col">แผนก</th><th scope="col">พนักงาน</th><th scope="col">งานทั้งหมด</th><th scope="col">ยังไม่เสร็จ</th><th scope="col">เสร็จ</th><th scope="col">ล่าช้า</th><th scope="col">อัตราสำเร็จ</th></tr></thead>
            <tbody>
                @forelse($departmentSummary as $department)
                    <tr>
                        <th scope="row">{{ $department['name'] }}</th>
                        <td>{{ number_format($department['employees']) }}</td>
                        <td>{{ number_format($department['total']) }}</td>
                        <td>{{ number_format($department['active']) }}</td>
                        <td>{{ number_format($department['done']) }}</td>
                        <td><span class="report-count {{ $department['overdue'] > 0 ? 'report-count--danger' : '' }}">{{ number_format($department['overdue']) }}</span></td>
                        <td>
                            <div class="report-rate"><progress value="{{ $department['rate'] }}" max="100" aria-label="อัตราสำเร็จ {{ $department['rate'] }} เปอร์เซ็นต์"></progress><strong>{{ $department['rate'] }}%</strong></div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="report-empty">ไม่พบข้อมูลแผนกตามตัวกรอง</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
