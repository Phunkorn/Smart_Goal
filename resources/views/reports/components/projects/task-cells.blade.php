{{--
    เซลล์ของแถวงานโปรเจกต์ (ถัดจากช่องลำดับ) — ชุดเดียวของหน้าหลักและหน้า "ดูรายละเอียดทั้งหมด"

    ช่องลำดับอยู่ที่ผู้เรียก เพราะหน้าหลักนับจาก 1 ส่วนหน้ารายละเอียดนับต่อจากหน้าก่อน
    ลำดับต้องตรงกับ task-head.blade.php ทุกคอลัมน์

    @param array $row  แถวหนึ่งจาก ProjectReportService ที่ผ่าน withEvidence() แล้ว (ไฟล์ตรวจ WorkOrderPolicy ทีละงาน)
--}}
@php
    $dateTime = fn ($date) => $date ? $date->locale('th')->translatedFormat('j M').' '.($date->year + 543).' · '.$date->format('H:i').' น.' : null;
@endphp
<td class="is-date">{{ $row['date'] ? $row['date']->locale('th')->translatedFormat('j M').' '.($row['date']->year + 543) : '—' }}</td>
<td class="project-report__project" title="{{ $row['project']['name'] }}">{{ $row['project']['name'] }}</td>
<th scope="row" class="project-report__topic" title="{{ $row['topic'] }}">{{ $row['topic'] }}</th>
<td class="report-subtask-cell">@include('reports.components.subtask-cell', ['job' => [...$row, 'project' => $row['project']['name']]])</td>
<td>
    @if($row['assignee'])
        <span class="project-report__person">
            @include('reports.components.projects.avatar', ['person' => $row['assignee'], 'decorative' => true])
            <span>{{ $row['assignee']['name'] }}</span>
        </span>
    @else
        <span class="project-report__muted">—</span>
    @endif
</td>
<td>
    {{-- จำนวนคนในตาราง กดแล้วเห็นรายชื่อใน popover ใต้ปุ่ม (components/participants-popover.js) --}}
    @if($row['participants'])
        <div class="project-report__participants" data-participants>
            <button type="button" class="project-report__participants-trigger" data-participants-trigger aria-haspopup="true" aria-expanded="false"
                aria-label="ดูผู้เข้าร่วม {{ count($row['participants']) }} คนของ {{ $row['topic'] }}">
                <i class="bi bi-people" aria-hidden="true"></i> {{ count($row['participants']) }} คน <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="project-report__participants-panel" data-participants-panel hidden>
                <p class="project-report__participants-title">ผู้เข้าร่วม {{ count($row['participants']) }} คน</p>
                <ul>
                    @foreach($row['participants'] as $person)
                        <li>
                            @include('reports.components.projects.avatar', ['person' => $person, 'decorative' => true])
                            <span>{{ $person['name'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @else
        <span class="project-report__muted">—</span>
    @endif
</td>
<td>
    {{-- รับผิดชอบ / ร่วมทำ — ภาพรวมแผนกบอกชื่อลูกทีมที่ร่วมทำไว้ใต้ป้าย --}}
    <span class="project-report__role project-report__role--{{ $row['role']['key'] }}">{{ $row['role']['label'] }}</span>
    @if($row['role']['detail'])
        <span class="project-report__role-detail">{{ $row['role']['detail'] }}</span>
    @endif
</td>
<td>
    <span class="project-report__scope project-report__scope--{{ $row['scope']['key'] }}" @if($row['scope']['detail']) title="{{ $row['scope']['detail'] }}" @endif>{{ $row['scope']['label'] }}</span>
</td>
<td>
    @include('reports.components.projects.evidence-count', [
        'jobId' => $row['id'], 'topic' => $row['topic'], 'label' => 'ไฟล์แนบงาน',
        'documents' => $row['evidence']['counts']['documents'], 'images' => $row['evidence']['counts']['images'],
        'locked' => $row['evidence']['locked'],
    ])
</td>
<td>
    @include('reports.components.projects.evidence-count', [
        'jobId' => $row['id'], 'topic' => $row['topic'], 'label' => 'ไฟล์แนบงานย่อย',
        'documents' => $row['child_counts']['documents'], 'images' => $row['child_counts']['images'],
        'locked' => $row['child_counts']['locked'] > 0,
    ])
</td>
<td class="is-datetime">{{ $dateTime($row['start_at']) ?? '—' }}</td>
<td class="is-datetime">
    @if($row['completed_at'])
        {{ $dateTime($row['completed_at']) }}
    @else
        <span class="project-report__muted" @if($row['due_at']) title="กำหนด {{ $dateTime($row['due_at']) }}" @endif>ยังไม่เสร็จ</span>
    @endif
</td>
<td>
    <span class="project-report__status project-report__status--{{ $row['status']['key'] }}">{{ $row['status']['label'] }}</span>
</td>
