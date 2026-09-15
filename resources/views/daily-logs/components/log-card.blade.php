<article class="log-card log-card--status-{{ $presented['display_status'] }}"
    id="work-log-{{ $presented['id'] }}" data-log-card data-log-id="{{ $presented['id'] }}"
    data-log-kind="{{ $presented['kind'] }}" data-log-status="{{ $presented['status'] }}"
    @if($presented['planned_end_at']) data-planned-end-at="{{ $presented['planned_end_at'] }}" @endif>
    <div class="log-card__time">
        <strong>{{ $presented['started_time'] ?? $presented['planned_start_time'] ?? '—' }}</strong>
        <small>{{ $presented['ended_time'] ?? $presented['planned_end_time'] ?? '—' }}</small>
    </div>
    <div class="log-card__name">
        <span class="log-card__kind-icon" aria-hidden="true"><i class="bi {{ $presented['kind_icon'] }}"></i></span>
        <span>
            <strong>{{ $presented['title'] }}</strong>
            @if($presented['shared_from'] && $presented['shared_from']['owner_name'])
                <small class="log-card__shared"><i class="bi bi-people" aria-hidden="true"></i> {{ $presented['shared_from']['owner_name'] }} เพิ่มคุณเข้างานนี้</small>
            @endif
            @if($presented['location']) <small><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $presented['location'] }}</small> @endif
            @if($presented['details']) <small>{{ $presented['details'] }}</small> @endif
            @if(count($presented['attachments']))
                <small><i class="bi bi-paperclip" aria-hidden="true"></i> {{ count($presented['attachments']) }} ไฟล์</small>
            @endif
        </span>
    </div>
    <div class="log-card__type">
        <span class="log-card__type-label">{{ $presented['kind_label'] }}</span>
        <small>{{ $presented['category']['name'] ?? 'ไม่ระบุหมวด' }}</small>
    </div>
    <div class="log-card__project">
        @if($presented['project'])
            <i class="bi bi-folder2" aria-hidden="true"></i>
            <span>{{ $presented['project']['name'] }}</span>
            @if($presented['task']) <small>{{ $presented['task']['topic'] }}</small> @endif
        @else
            <span class="log-card__muted">—</span>
            @if($presented['task']) <small>{{ $presented['task']['topic'] }}</small> @endif
        @endif
    </div>
    <div class="log-card__people">
        @php
            $people = collect($presented['participants'])->pluck('name');
            if ($presented['routine'] && ! empty($presented['routine']['people'])) {
                $people = $people->merge($presented['routine']['people']);
            }
            if ($presented['shared_from']) {
                $people = $people->merge($presented['shared_from']['people']);
            }
            $people = $people->unique()->values();
        @endphp
        @if($people->isNotEmpty())
            <span title="{{ $people->join(', ') }}" aria-label="ผู้ร่วมงาน: {{ $people->join(', ') }}">
                @foreach($people->take(2) as $person)
                    <span class="log-card__avatar" aria-hidden="true">{{ \Illuminate\Support\Str::substr($person, 0, 1) }}</span>
                @endforeach
                @if($people->count() > 2) <small>+{{ $people->count() - 2 }}</small> @endif
            </span>
        @else
            <span class="log-card__muted">—</span>
        @endif
    </div>
    <div class="log-card__state">
        <span class="log-card__status" data-log-status-chip>{{ $presented['status_label'] }}</span>
        @if($presented['is_in_progress'] && $presented['started_at'])
            <small data-routine-elapsed data-started-at="{{ $presented['started_at'] }}">กำลังทำ 0 นาที</small>
        @endif
        @if($presented['late_start_reason']) <small>เริ่มช้า: {{ $presented['late_start_reason'] }}</small> @endif
        @if($presented['late_completion_reason']) <small>เสร็จช้า: {{ $presented['late_completion_reason'] }}</small> @endif
        @if($presented['absent_marked_by']) <small>{{ $presented['absent_marked_by'] }} ระบุว่าไม่มา</small> @endif
        {{-- skip_reason เป็นเหตุผลของกลุ่ม "ไม่ได้ทำ" ป้ายตามสถานะจริง ส่วนเริ่มแล้วไม่กดเสร็จแยกคอลัมน์ของตัวเอง --}}
        @if($presented['skip_reason'])
            <small>{{ ['not_started' => 'ไม่ได้เริ่ม', 'absent' => 'ไม่มา'][$presented['display_status']] ?? 'ไม่ได้ทำ' }}: {{ $presented['skip_reason'] }}</small>
        @endif
        @if($presented['unfinished_reason']) <small>เริ่มแล้วไม่กดเสร็จ: {{ $presented['unfinished_reason'] }}</small> @endif
        @if($presented['has_issue']) <small class="log-card__issue"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> พบปัญหา: {{ $presented['issue_details'] }}</small> @endif
    </div>
    <div class="log-card__actions">
        @unless($capabilities['isReadOnly'] ?? true)
            @if($presented['requires_explanation'])
                {{-- ปิดรอบแล้วและยังไม่มีเหตุผล ถามคนละชุดตามกรณี (ไม่ได้เริ่ม / ไม่มา / เริ่มแล้วไม่กดเสร็จ) --}}
                <button type="button" class="log-card__action" data-row-skip data-explain-type="{{ $presented['explanation_type'] }}">ระบุเหตุผล</button>
            @elseif($presented['is_closed_by_cutoff'])
                {{-- ปิดรอบและมีเหตุผลแล้ว ไม่มีปุ่มให้กดต่อ --}}
            @elseif($presented['routine'] && in_array($presented['status'], ['open', 'absent'], true))
                <button type="button" class="log-card__action log-card__action--primary" data-row-start
                    @if($presented['planned_start_at']) data-planned-start-at="{{ $presented['planned_start_at'] }}" @endif
                    @unless($presented['can_start']) disabled title="เริ่มได้เมื่อถึงเวลาที่กำหนด" @endunless>เริ่มงาน</button>
                <button type="button" class="log-card__action log-card__action--quiet" data-row-skip>ไม่ได้ทำ</button>
            @elseif($presented['routine'] && $presented['status'] === 'in_progress')
                <button type="button" class="log-card__action log-card__action--primary" data-row-complete>เสร็จงาน</button>
                <button type="button" class="log-card__action log-card__action--quiet" data-row-skip>ไม่ได้ทำ</button>
            @elseif(! $presented['routine'] && ! $presented['is_done'] && ! $presented['is_skipped'])
                <button type="button" class="log-card__action" data-row-complete>ทำเสร็จแล้ว</button>
            @endif
            {{-- เสร็จแล้ว / ไม่ได้ทำ เป็นสถานะสุดท้าย จึงไม่มีปุ่มย้อนสถานะ (ดู WorkLogService::markDone) --}}
            <button type="button" class="log-card__menu-trigger" aria-haspopup="true" aria-expanded="false"
                aria-label="จัดการบันทึก {{ $presented['title'] }}" data-log-menu-trigger>
                <i class="bi bi-three-dots" aria-hidden="true"></i>
            </button>
        @endunless
    </div>
</article>
