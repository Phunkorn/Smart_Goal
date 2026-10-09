@php
    use App\Support\RoleLabel;
    use App\Support\WorkBoardDesign;
@endphp

{{--
    งานข้ามแผนกของทีม — ลูกทีมของแผนกกำลังเข้าร่วมโปรเจกต์ของแผนกอื่น

    เป็นหน้าติดตาม (tracking/visibility) ไม่ใช่คิวอนุมัติ ไม่มีปุ่มอนุมัติหรือปฏิเสธที่นี่
    ผู้เข้าร่วมเข้าโปรเจกต์เสร็จแล้วตั้งแต่ตอนที่หัวหน้าโปรเจกต์กดเพิ่ม
    (App\Services\CollaboratorInvitationService)

    จัดกลุ่มชั้นเดียว: พนักงานของเรา → งานที่เขาเข้าร่วม

    ของเดิมจัดกลุ่มสองชั้นโดยเอาแผนกปลายทางขึ้นก่อน คนคนเดียวที่ไปช่วยสองแผนกจึงถูกวาด
    เป็นหัวแถวสองครั้ง และตัวตารางสี่คอลัมน์ทำให้ชื่องาน หัวหน้าโปรเจกต์ กำหนดส่ง และปุ่ม
    ยืนห่างกันคนละมุมบนจอกว้าง ต้องกวาดสายตาทั้งแถวกว่าจะรู้ว่างานใบนี้เป็นของใครและช้าหรือไม่

    ตอนนี้คำถามของหัวหน้าแผนก ("ลูกทีมฉันไปช่วยใคร งานไหนช้า") ตอบได้จากบนลงล่าง:
    แถบสรุป → ตัวกรอง/ค้นหา → การ์ดของพนักงานหนึ่งคน → งานของเขา
    แผนกปลายทางกลายเป็นป้ายในแถวงาน เพราะเป็นคุณสมบัติของงาน ไม่ใช่ของคน

    ปุ่ม "ดูงาน" พาไป Workspace ของพนักงานคนนั้นในมุมมองบอร์ด กรอง "งานข้ามแผนก"
    และเปิดงานใบนั้นให้ทันที Workspace นั้นอ่านอย่างเดียว หัวหน้าต้นสังกัดดูงานและ
    คอมเมนต์ได้ (WorkOrderPolicy::comment) แต่แก้เวลาหรือสถานะไม่ได้
--}}
@php
    /*
     * $showsOutgoingHeading — ปิดหัวเรื่องของการ์ดเมื่อการ์ดนี้เป็นเนื้อหาหลักของหน้า
     * (หน้าของหัวหน้าแผนกที่ไม่มีคำขอค้าง) เพราะ <h1> ของหน้าพูดเรื่องเดียวกันไปแล้ว
     * ค่าตั้งต้นเป็น true เพื่อให้ทุกจุดที่ include อยู่เดิมได้พฤติกรรมเดิม
     */
    $showsOutgoingHeading = $showsOutgoingHeading ?? true;

    /*
     * สถานะจริงมีหกค่า แต่คำถามของหัวหน้ามีสามแบบ: ช้าไหม ยังทำอยู่ไหม จบหรือยัง
     * ตัวกรองจึงยุบเหลือสามถัง ส่วนป้ายในแถวงานยังแสดงสถานะเต็มตาม WorkBoardDesign เหมือนเดิม
     */
    $bucketOf = fn ($task): string => match (WorkBoardDesign::statusKey($task)) {
        'late' => 'late',
        'done' => 'done',
        default => 'active',
    };

    $trackBuckets = $outgoingCollaborations->map(fn (array $row) => $bucketOf($row['task']));
    $trackCounts = [
        'all' => $outgoingCollaborations->count(),
        'late' => $trackBuckets->filter(fn (string $bucket) => $bucket === 'late')->count(),
        'active' => $trackBuckets->filter(fn (string $bucket) => $bucket === 'active')->count(),
        'done' => $trackBuckets->filter(fn (string $bucket) => $bucket === 'done')->count(),
    ];

    // คนที่มีงานล่าช้ามากที่สุดขึ้นก่อน — สิ่งที่หัวหน้าต้องเห็นคือปัญหา ไม่ใช่ลำดับตามรหัสงาน
    $memberGroups = $outgoingCollaborations
        ->groupBy(fn (array $row) => $row['member']->id)
        ->sortBy(function ($memberRows) use ($bucketOf): string {
            $late = $memberRows->filter(fn (array $row) => $bucketOf($row['task']) === 'late')->count();

            return sprintf('%04d-%s', 9999 - $late, $memberRows->first()['member']->name);
        });

    $trackFilters = [
        'all' => ['ทั้งหมด', $trackCounts['all']],
        'late' => ['ล่าช้า', $trackCounts['late']],
        'active' => ['กำลังทำ', $trackCounts['active']],
        'done' => ['เสร็จแล้ว', $trackCounts['done']],
    ];
@endphp
<section class="admin-approvals-queue" id="outgoing-collaboration-queue"
    @if($showsOutgoingHeading) aria-labelledby="outgoingCollaborationHeading" @else aria-label="งานข้ามแผนกของทีม" @endif>
    @if($showsOutgoingHeading)
        <div class="admin-approvals-queue__heading">
            <div>
                <span>CROSS-DEPARTMENT TRACKING</span>
                <h2 id="outgoingCollaborationHeading">งานข้ามแผนกของทีม</h2>
                <p>รับทราบและติดตามว่าลูกทีมกำลังเข้าร่วมโปรเจกต์ของแผนกใด — ไม่มีอะไรต้องอนุมัติที่นี่</p>
            </div>
            <span class="badge rounded-pill text-bg-primary">{{ $trackCounts['all'] }} รายการ</span>
        </div>
    @endif

    @if($outgoingCollaborations->isEmpty())
        <div class="admin-approvals-empty">
            <i class="bi bi-check-circle" aria-hidden="true"></i>
            <p>ยังไม่มีพนักงานที่เข้าร่วมโปรเจกต์ของแผนกอื่น</p>
        </div>
    @else
        {{-- แถบสรุป — ตอบ "ภาพรวมเป็นอย่างไร" ก่อนที่จะต้องอ่านรายการทีละใบ --}}
        <div class="cross-dept-track__overview">
            <div class="cross-dept-track__tile">
                <i class="bi bi-people-fill" aria-hidden="true"></i>
                <span>พนักงานที่ไปช่วย<strong>{{ $memberGroups->count() }} คน</strong></span>
            </div>
            <div class="cross-dept-track__tile">
                <i class="bi bi-diagram-3-fill" aria-hidden="true"></i>
                <span>งานที่เข้าร่วม<strong>{{ $trackCounts['all'] }} งาน</strong></span>
            </div>
            <div class="cross-dept-track__tile is-red">
                <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                <span>ล่าช้า<strong>{{ $trackCounts['late'] }} งาน</strong></span>
            </div>
            <div class="cross-dept-track__tile is-green">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                <span>เสร็จแล้ว<strong>{{ $trackCounts['done'] }} งาน</strong></span>
            </div>
        </div>

        {{--
            ค้นหาและตัวกรองทำงานฝั่ง client บนรายการที่ server ส่งมาครบแล้ว จึงไม่ใช่ฟอร์ม
            และไม่ยิง request ใหม่ — ไม่ใช่เรื่องสิทธิ์ ขอบเขตที่เห็นถูกจำกัดมาแล้วที่
            AdminApprovalQuery::outgoingCollaborations()
        --}}
        <div class="cross-dept-track__toolbar">
            <div class="cross-dept-track__search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" data-cross-dept-search autocomplete="off"
                    placeholder="ค้นหาชื่อพนักงาน ชื่องาน โปรเจกต์ หรือแผนก"
                    aria-label="ค้นหาในงานข้ามแผนกของทีม">
            </div>
            <div class="cross-dept-track__filters" role="group" aria-label="กรองตามสถานะงาน">
                @foreach($trackFilters as $bucket => [$label, $count])
                    <button type="button" class="cross-dept-track__filter {{ $bucket === 'all' ? 'is-active' : '' }}"
                        data-cross-dept-filter="{{ $bucket }}" aria-pressed="{{ $bucket === 'all' ? 'true' : 'false' }}">
                        {{ $label }} <span>{{ $count }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="cross-dept-track" data-cross-dept-list>
            @foreach($memberGroups as $memberRows)
                @php
                    $member = $memberRows->first()['member'];
                    $memberLate = $memberRows->filter(fn (array $row) => $bucketOf($row['task']) === 'late')->count();
                    $memberDepartments = $memberRows->pluck('destination')->unique();

                    /*
                     * ภายในการ์ดของคนหนึ่งคน: เรียงตามแผนกปลายทาง แล้วให้งานย่อยอยู่ใต้งานแม่
                     * ของมันเมื่องานแม่อยู่ในรายการเดียวกัน งานย่อยที่งานแม่ไม่ได้อยู่ในรายการ
                     * ยังยืนเป็นแถวของตัวเองตามเดิม
                     */
                    $visibleIds = $memberRows->map(fn (array $row) => (int) $row['task']->job_id)->all();
                    $orderedRows = $memberRows
                        ->sortBy(function (array $row) use ($visibleIds): string {
                            $task = $row['task'];
                            $nested = $task->parent_job_id
                                && in_array((int) $task->parent_job_id, $visibleIds, true);

                            return sprintf(
                                '%s-%010d-%d-%010d',
                                $row['destination'],
                                $nested ? (int) $task->parent_job_id : (int) $task->job_id,
                                $nested ? 1 : 0,
                                (int) $task->job_id
                            );
                        })
                        ->values();

                    $memberSearch = mb_strtolower(trim(
                        $member->name.' '.($member->department?->department_name ?? '')
                    ));
                @endphp

                <article class="cross-dept-track__member" data-cross-dept-member data-search="{{ $memberSearch }}">
                    <header class="cross-dept-track__member-head">
                        <span class="cross-dept-track__avatar cross-dept-track__avatar--lg" aria-hidden="true">
                            @include('components.user-avatar-content', ['user' => $member, 'avatarUpper' => true])
                        </span>
                        <span class="cross-dept-track__member-copy">
                            <strong>{{ $member->name }}</strong>
                            {{--
                                บทบาทมาจาก RoleLabel ที่เดียวทั้งระบบ — ของเดิมเขียน "พนักงานแผนก…" ไว้ตรงนี้
                                จึงเรียกหัวหน้าแผนกที่ไปร่วมโปรเจกต์ต่างแผนกว่าพนักงาน (users.is_department_head
                                เป็นธงแยก ไม่ใช่ค่าใน users.role) withDepartment() ให้ "หัวหน้าแผนก · IT" ได้ในบรรทัดเดียว
                            --}}
                            <small>{{ RoleLabel::withDepartment($member) }}</small>
                        </span>
                        <span class="admin-approvals-status is-green">
                            <i class="bi bi-people" aria-hidden="true"></i>กำลังเข้าร่วม
                        </span>
                        <span class="cross-dept-track__member-stats">
                            @if($memberLate > 0)
                                <span class="cross-dept-track__member-late">
                                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>ล่าช้า {{ $memberLate }}
                                </span>
                            @endif
                            <span class="cross-dept-track__member-count" data-cross-dept-count>{{ $memberRows->count() }} งาน</span>
                            <small>{{ $memberDepartments->count() }} แผนก</small>
                        </span>
                    </header>

                    <div class="cross-dept-track__tasks">
                        @foreach($orderedRows as $row)
                            @php
                                $task = $row['task'];
                                $leader = $row['leader'];
                                $status = WorkBoardDesign::status($task);
                                $priority = WorkBoardDesign::taskPriority((int) $task->job_priority);
                                $bucket = $bucketOf($task);
                                $isNested = $task->parent_job_id
                                    && in_array((int) $task->parent_job_id, $visibleIds, true);
                                $project = $task->taskList?->name ?? 'งานทั่วไป';
                                $boardQuery = ['view' => 'board', 'status' => 'cross_department', 'open_task' => $task->job_id];
                                $boardUrl = $isAdminViewer
                                    ? route('admin.work-board.member', [$member->department_id, $member, ...$boardQuery])
                                    : route('work-board.member', [$member->department_id, $member, 'workspace' => 1, ...$boardQuery]);
                                $taskSearch = mb_strtolower(trim(implode(' ', [
                                    $task->job_topic,
                                    $project,
                                    $row['destination'],
                                    $leader?->name ?? '',
                                    $status['label'],
                                ])));
                            @endphp
                            <article class="cross-dept-track__task {{ $isNested ? 'is-nested' : '' }}"
                                data-cross-dept-task data-bucket="{{ $bucket }}" data-search="{{ $taskSearch }}">
                                <div class="cross-dept-track__body">
                                    <p class="cross-dept-track__topic">
                                        @if($isNested)
                                            {{-- งานแม่อยู่แถวบนแล้ว บอกแค่ว่าแถวนี้เป็นงานย่อยของมัน --}}
                                            <i class="bi bi-arrow-return-right cross-dept-track__nest-mark" aria-hidden="true"></i>
                                        @endif
                                        <strong>{{ $task->job_topic }}</strong>
                                        <span class="admin-approvals-status is-{{ $status['tone'] }}">
                                            <i class="bi {{ $status['icon'] }}" aria-hidden="true"></i>{{ $status['label'] }}
                                        </span>
                                    </p>
                                    <p class="cross-dept-track__meta">
                                        <span class="cross-dept-track__chip cross-dept-track__chip--dept">
                                            <i class="bi bi-building" aria-hidden="true"></i>แผนก{{ $row['destination'] }}
                                        </span>
                                        <span class="cross-dept-track__chip">
                                            <i class="bi bi-folder2-open" aria-hidden="true"></i>{{ $project }}
                                        </span>
                                        {{--
                                            หัวหน้าโปรเจกต์ — รูปจริงถ้ามี ไม่งั้นตัวย่อชื่อ ตาม
                                            components/user-avatar-content ที่เดียวทั้งระบบ
                                            ไม่มีหัวหน้า (งานเก่าที่ created_by ว่าง) ก็ยังเป็นป้ายไอคอนเดิม
                                        --}}
                                        <span class="cross-dept-track__chip cross-dept-track__chip--leader">
                                            @if($leader)
                                                <span class="cross-dept-track__avatar cross-dept-track__avatar--xs" aria-hidden="true">
                                                    @include('components.user-avatar-content', ['user' => $leader, 'avatarUpper' => true])
                                                </span>
                                            @else
                                                <i class="bi bi-person-badge" aria-hidden="true"></i>
                                            @endif
                                            <span>หัวหน้าโปรเจกต์ {{ $leader?->name ?? 'ไม่ระบุ' }}</span>
                                        </span>
                                        @if($task->parent && ! $isNested)
                                            <span class="cross-dept-track__chip cross-dept-track__chip--parent">
                                                <i class="bi bi-diagram-2" aria-hidden="true"></i>งานย่อยของ {{ $task->parent->job_topic }}
                                            </span>
                                        @endif
                                        <span class="cross-dept-track__chip admin-approvals-priority is-{{ $priority['tone'] }}">
                                            <i class="bi bi-flag" aria-hidden="true"></i>{{ $priority['label'] }}
                                        </span>
                                    </p>
                                </div>

                                <p class="cross-dept-track__schedule">
                                    <span class="cross-dept-track__due">
                                        <i class="bi bi-calendar-check" aria-hidden="true"></i>
                                        กำหนดส่ง <strong>{{ $task->job_due_at?->timezone('Asia/Bangkok')->format('d/m/Y') ?? '-' }}</strong>
                                    </span>
                                    <small>เริ่ม {{ $task->job_start_at?->timezone('Asia/Bangkok')->format('d/m/Y') ?? '-' }}</small>
                                </p>

                                <a class="cross-dept-track__action" href="{{ $boardUrl }}"
                                    aria-label="ดูงาน {{ $task->job_topic }} ของ {{ $member->name }} ในบอร์ด">
                                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                    <span>ดูงาน</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </div>

        {{-- สถานะว่างของตัวกรอง ไม่ใช่ของข้อมูล — ข้อมูลว่างใช้ .admin-approvals-empty ด้านบน --}}
        <p class="cross-dept-track__no-result" data-cross-dept-no-result hidden>
            <i class="bi bi-search" aria-hidden="true"></i>
            ไม่พบงานที่ตรงกับคำค้นหรือตัวกรองนี้
        </p>
    @endif
</section>
