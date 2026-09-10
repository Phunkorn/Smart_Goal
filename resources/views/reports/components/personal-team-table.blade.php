<section class="personal-report__panel personal-report__contributions" aria-labelledby="personal-contributions-title">
    <div class="personal-report__section-head">
        <div>
            <h2 id="personal-contributions-title">ผลงานและทีมที่ร่วมงาน</h2>
            <p>งานทุกใบที่คุณมีส่วนร่วมในช่วง {{ $filters['period_label'] }}</p>
        </div>
        <span>{{ $taskRows->count() }} งาน</span>
    </div>
    @if($taskRows->isNotEmpty())
        <div class="personal-report__table-wrap">
            <table class="table table-hover align-middle mb-0 personal-report__table personal-report__table--contributions" data-personal-contribution-table data-page-size="10">
                <caption class="visually-hidden">ผลงานและทีมที่ร่วมงานในช่วง {{ $filters['period_label'] }}</caption>
                <thead><tr><th scope="col" class="personal-report__index-col">ลำดับ</th><th scope="col">หัวข้อโปรเจกต์</th><th scope="col">ชื่องาน</th><th scope="col">งานย่อย</th><th scope="col">เจ้าของงาน</th><th scope="col">ผู้ร่วมงาน</th><th scope="col">สถานะ</th><th scope="col">กำหนดส่ง</th></tr></thead>
                <tbody>
                    @foreach($taskRows as $job)
                        <tr data-personal-contribution-row>
                            <td data-label="ลำดับ" class="personal-report__index">{{ $loop->iteration }}</td>
                            <td data-label="หัวข้อโปรเจกต์" class="personal-report__project">{{ $job['project'] }}</td>
                            <th scope="row" data-label="ชื่องาน"><a class="personal-report__task-link" href="{{ $job['url'] }}">{{ $job['topic'] }}</a></th>
                            <td data-label="งานย่อย" class="report-subtask-cell">@include('reports.components.subtask-cell')</td>
                            @include('reports.components.task-team-cell', ['team' => $job['team']])
                            <td data-label="สถานะ"><span class="report-tag report-tone-{{ $job['status']['tone'] }}">{{ $job['status']['label'] }}</span></td>
                            <td data-label="กำหนดส่ง"><time @if($job['due_at']) datetime="{{ $job['due_at']->toDateString() }}" @endif>{{ $job['due_at']?->locale('th')->isoFormat('D MMM YY') ?? 'ไม่มีกำหนด' }}</time></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <nav class="report-table-pager" data-personal-contribution-pager aria-label="เปลี่ยนหน้าตารางผลงานและทีมที่ร่วมงาน" hidden>
            <button type="button" data-personal-contribution-previous aria-label="หน้าก่อนหน้า">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                <span>ย้อนกลับ</span>
            </button>
            <span data-personal-contribution-page role="status" aria-live="polite"></span>
            <button type="button" data-personal-contribution-next aria-label="หน้าถัดไป">
                <span>ถัดไป</span>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
        </nav>
    @else
        <div class="personal-report__empty"><i class="bi bi-inbox" aria-hidden="true"></i><strong>ยังไม่มีผลงานในช่วงเวลานี้</strong><span>เมื่อคุณได้รับมอบหมายงานหรือถูกเชิญไปร่วมงาน รายการจะแสดงที่นี่</span></div>
    @endif
</section>
