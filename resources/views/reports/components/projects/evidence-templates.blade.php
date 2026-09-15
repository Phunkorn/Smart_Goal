{{--
    เนื้อหากล่องหลักฐานของแต่ละแถว — ชุดเดียวของหน้าหลักและหน้า "ดูรายละเอียดทั้งหมด"

    อยู่ใน <template> จึงไม่แสดงและไม่โหลดอะไรจนกว่าจะกดไอคอนไฟล์ในแถวนั้น (components/evidence-modal.js)
    ลิงก์ไฟล์มาจาก ProjectReportService::withEvidence() ซึ่งตรวจ WorkOrderPolicy ทีละงานแล้ว

    @param iterable $rows  แถวที่แสดงอยู่บนตาราง
--}}
@php
    $dateTime = fn ($date) => $date ? $date->locale('th')->translatedFormat('j M').' '.($date->year + 543).' · '.$date->format('H:i').' น.' : null;
@endphp
@foreach($rows as $row)
    <template data-evidence-template="{{ $row['id'] }}">
        <header class="project-report__evidence-head">
            <p class="subtask-modal__eyebrow">{{ $row['project']['name'] }}</p>
            <h2 class="subtask-modal__title" id="projectEvidenceTitle">{{ $row['topic'] }}</h2>
            <p class="project-report__evidence-meta">
                <span>{{ $row['assignee']['name'] ?? 'ไม่ระบุผู้รับผิดชอบ' }}</span>
                <span>เริ่ม {{ $dateTime($row['start_at']) ?? '—' }}</span>
                <span>{{ $row['completed_at'] ? 'เสร็จ '.$dateTime($row['completed_at']) : 'ยังไม่เสร็จ' }}</span>
                <span class="project-report__status project-report__status--{{ $row['status']['key'] }}">{{ $row['status']['label'] }}</span>
            </p>
        </header>

        <section class="project-report__evidence-section">
            <h3>ไฟล์แนบงาน ({{ count($row['evidence']['files']) }})</h3>
            @include('reports.components.projects.file-list', ['evidence' => $row['evidence']])
        </section>

        <section class="project-report__evidence-section">
            <h3>งานย่อย ({{ $row['subtask_summary']['total'] }})</h3>
            @if($row['children'] === [])
                <span class="project-report__muted">ไม่มีงานย่อย</span>
            @else
                <ol class="project-report__evidence-subtasks">
                    @foreach($row['children'] as $child)
                        <li>
                            <div class="project-report__evidence-subtask-head">
                                <strong>{{ $child['topic'] }}</strong>
                                <span class="project-report__status project-report__status--{{ $child['status']['key'] }}">{{ $child['status']['label'] }}</span>
                            </div>
                            <small>เริ่ม {{ $dateTime($child['start_at']) ?? '—' }} · {{ $child['completed_at'] ? 'เสร็จ '.$dateTime($child['completed_at']) : 'ยังไม่เสร็จ' }}</small>
                            @include('reports.components.projects.file-list', ['evidence' => $child['evidence']])
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </template>
@endforeach
