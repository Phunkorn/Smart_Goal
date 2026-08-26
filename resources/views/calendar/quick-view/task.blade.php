@php
    /**
     * Quick View ของรายการงาน — โหมดดูข้อมูลอย่างเดียว ไม่มีฟอร์มแก้ไข ไม่มีช่องกรอก ไม่มี dropdown
     * ใช้ formatter/label ชุดเดียวกับที่ระบบใช้อยู่ (WorkBoardDesign, TodayWorkspace) ไม่ประกาศชุดใหม่
     * ชื่อโปรเจกต์ถูกส่งไปแสดงเป็น kicker ของ popover shell ผ่าน data-quick-view-kicker-text ด้านล่าง
     */
    use App\Support\TodayWorkspace;
    use App\Support\WorkBoardDesign;

    $status = WorkBoardDesign::status($task);
    $priority = WorkBoardDesign::taskPriority((int) $task->job_priority);
    $dateRange = TodayWorkspace::dateRangeLabel($task->job_start_at, $task->job_due_at);
    $progress = TodayWorkspace::timeProgress($task);
    $overdueDays = TodayWorkspace::overdueDays($task);

    $remainingText = match (true) {
        $status['key'] === 'done' => 'เสร็จสิ้นแล้ว',
        $status['key'] === 'late' => $overdueDays > 0 ? 'เกินกำหนด '.$overdueDays.' วัน' : 'เกินกำหนดส่ง',
        $status['key'] === 'paused' => 'พักงานอยู่',
        $progress && $progress['is_due_today'] => 'ครบกำหนดวันนี้',
        $progress => 'เหลือ '.$progress['remaining_days'].' วัน',
        default => null,
    };

    $accepted = $task->collaborators->where('pivot.status', 'accepted')->values();
    $pendingCount = $task->collaborators->where('pivot.status', '!=', 'accepted')->count();
    $attachmentsCount = (int) ($task->images_count ?? 0);
    $latestUpdate = $task->updates->sortByDesc('created_at')->first();
@endphp

<article class="qv" data-quick-view-type="task" data-quick-view-title-text="{{ $task->job_topic }}" data-quick-view-kicker-text="{{ $task->taskList?->name ?? 'งานทั่วไป' }}">
    {{-- สรุปแบบบรรทัดสั้น: สถานะ/ความสำคัญเป็น icon + ข้อความล้วน ไม่ใช่ select/dropdown --}}
    <p class="qv-summary">
        <span class="qv-summary__item qv-tone-{{ $status['tone'] }}"><i class="bi {{ $status['icon'] }}" aria-hidden="true"></i> {{ $status['label'] }}</span>
        <span class="qv-summary__sep" aria-hidden="true">&middot;</span>
        <span class="qv-summary__item qv-tone-{{ $priority['tone'] }}"><i class="bi bi-flag-fill" aria-hidden="true"></i> {{ $priority['label'] }}</span>
    </p>

    {{-- วันที่: ข้อความอ่านอย่างเดียว ไม่ใช่ timeline/slider --}}
    <p class="qv-dates">
        <i class="bi bi-calendar3" aria-hidden="true"></i>
        <span>{{ $dateRange ?? (TodayWorkspace::calendarDate($task->job_due_at) ?: 'ยังไม่ระบุกำหนดการ') }}</span>
        @if($remainingText)
            <span class="qv-dates__badge qv-tone-{{ $status['tone'] }}">{{ $remainingText }}</span>
        @endif
    </p>

    {{-- คนที่เกี่ยวข้อง: ผู้รับผิดชอบหลัก + ผู้ร่วมงานแบบ avatar ซ้อนกัน ไม่มี People Selector --}}
    <section class="qv-people">
        <div class="qv-avatar-row">
            @if($task->user)
                <span class="qv-avatar" title="{{ $task->user->name }}">
                    @if($task->user->profile_image)
                        <img src="{{ route('media.profile', $task->user) }}" alt="">
                    @else
                        {{ WorkBoardDesign::initials($task->user->name) }}
                    @endif
                </span>
                <span class="qv-people__name">{{ $task->user->name }}</span>
            @else
                <span class="qv-people__name qv-muted">ยังไม่มีผู้รับผิดชอบ</span>
            @endif
        </div>

        @if($accepted->isNotEmpty())
            <div class="qv-avatar-row">
                <span class="qv-avatar-stack">
                    @foreach($accepted->take(4) as $person)
                        <span class="qv-avatar qv-avatar--sm" title="{{ $person->name }}">
                            @if($person->profile_image)
                                <img src="{{ route('media.profile', $person) }}" alt="">
                            @else
                                {{ WorkBoardDesign::initials($person->name) }}
                            @endif
                        </span>
                    @endforeach
                </span>
                <span class="qv-people__meta">ผู้ร่วมงาน {{ $accepted->count() }} คน@if($pendingCount) &middot; รอตอบรับอีก {{ $pendingCount }} คน @endif</span>
            </div>
        @elseif($pendingCount > 0)
            <p class="qv-people__meta qv-muted">รอตอบรับ {{ $pendingCount }} คน</p>
        @endif
    </section>

    {{-- ข้อมูลรอง: บรรทัดข้อความธรรมดา ไม่ใช่ dashboard card --}}
    <p class="qv-secondary">
        <span><i class="bi bi-paperclip" aria-hidden="true"></i> {{ $attachmentsCount > 0 ? $attachmentsCount.' ไฟล์แนบ' : 'ไม่มีไฟล์แนบ' }}</span>
        <span><i class="bi bi-clock-history" aria-hidden="true"></i> {{ $latestUpdate ? 'อัปเดต '.$latestUpdate->created_at->translatedFormat('j M H:i') : 'ยังไม่มีอัปเดต' }}</span>
    </p>
</article>
