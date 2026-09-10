<section class="personal-report__panel personal-report__attention" aria-labelledby="personal-attention-title">
    <div class="personal-report__section-head">
        <div><h2 id="personal-attention-title">สิ่งที่ต้องรีบ</h2><p>งานเลยกำหนด ใกล้ครบกำหนด หรือสำคัญด่วน เรียงตามความเร่งด่วน</p></div>
        <span>{{ $attentionJobs->count() }} งาน</span>
    </div>
    @if($attentionJobs->isNotEmpty())
        <div class="personal-report__table-wrap">
            <table class="table table-hover align-middle mb-0 personal-report__table personal-report__table--attention" data-personal-attention-table data-page-size="10">
                <caption class="visually-hidden">งานที่ต้องรีบจัดการในช่วง {{ $filters['period_label'] }}</caption>
                <thead><tr><th scope="col" class="personal-report__index-col">ลำดับ</th><th scope="col">หัวข้อโปรเจกต์</th><th scope="col">ชื่องาน</th><th scope="col">งานย่อย</th><th scope="col">สถานะ</th><th scope="col">ความสำคัญ</th><th scope="col">สิ่งที่ต้องติดตาม</th><th scope="col">กำหนดส่ง</th></tr></thead>
                <tbody>
                    @foreach($attentionJobs as $job)
                        <tr data-personal-attention-row>
                            <td data-label="ลำดับ" class="personal-report__index">{{ $loop->iteration }}</td>
                            <td data-label="หัวข้อโปรเจกต์" class="personal-report__project">{{ $job['project'] }}</td>
                            <th scope="row" data-label="ชื่องาน"><a class="personal-report__task-link" href="{{ $job['url'] }}">{{ $job['topic'] }}</a></th>
                            <td data-label="งานย่อย" class="report-subtask-cell">@include('reports.components.subtask-cell')</td>
                            <td data-label="สถานะ"><span class="report-tag report-tone-{{ $job['status']['tone'] }}">{{ $job['status']['label'] }}</span></td>
                            <td data-label="ความสำคัญ"><span class="report-tag report-tone-{{ $job['priority']['tone'] }}">{{ $job['priority']['label'] }}</span></td>
                            <td data-label="สิ่งที่ต้องติดตาม"><span class="personal-report__tag personal-report__tag--{{ $job['reason'] === 'เกินกำหนด' ? 'red' : 'amber' }}">{{ $job['reason'] }}</span></td>
                            <td data-label="กำหนดส่ง"><time @if($job['due_at']) datetime="{{ $job['due_at']->toDateString() }}" @endif>{{ $job['due_at']?->locale('th')->isoFormat('D MMM YY') ?? 'ไม่มีกำหนด' }}</time></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <nav class="report-table-pager" data-personal-attention-pager aria-label="เปลี่ยนหน้าตารางงานที่ต้องรีบ" hidden>
            <button type="button" data-personal-attention-previous aria-label="หน้าก่อนหน้า">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                <span>ย้อนกลับ</span>
            </button>
            <span data-personal-attention-page role="status" aria-live="polite"></span>
            <button type="button" data-personal-attention-next aria-label="หน้าถัดไป">
                <span>ถัดไป</span>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
        </nav>
    @else
        <div class="personal-report__empty"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>ไม่มีงานที่ต้องรีบจัดการ</strong><span>ไม่พบงานเลยกำหนด ใกล้ครบกำหนด หรือสำคัญด่วนในช่วงเวลาที่เลือก</span></div>
    @endif
</section>
