@php
    use App\Support\RoleLabel;
    use App\Support\TodayWorkspace;

    /**
     * แผง Preview ของสมาชิกหนึ่งคน — ข้อมูลทั้งหมดมาจาก DepartmentWorkBoardQuery::memberPreview()
     *
     * แบ่งเป็นแท็บ (สลับฝั่ง client ด้วย resources/js/pages/work-board/department.js):
     *   "งานวันนี้"        นิยามของ TodayWorkspace::tasks() ตัวเดียวกับหน้า "งานของฉัน"
     *   "งานปฏิบัติ"       งานประจำ/นอกสถานที่ของวันนี้ — พนักงานทุกแผนกเห็นภาพรวม ไม่มีสถานที่
     *                      (viewTodaySummary) ตัวเอง/หัวหน้าแผนก/admin เห็นครบ (viewDay)
     *   "สร้างวันนี้"       โปรเจกต์ที่สร้างวันนี้ (รวมที่ยังไม่มีงาน) และงานที่สร้างวันนี้แต่ยังไม่ถึงวันเริ่ม
     *   "งานทั้งหมด"       งานทุกใบที่สมาชิกรับผิดชอบ ร่วมทำ หรือเปิดเอง (รวมที่ปิดแล้ว) เฉพาะใบที่ผู้ดูมีสิทธิ์ดู
     *
     * "วันนี้" กับ "สร้างวันนี้" ไม่ซ้ำกัน ส่วน "งานทั้งหมด" เป็นภาพรวมทุกใบจึงครอบสองแท็บนั้นโดยตั้งใจ
     *
     * บทบาท "หัวหน้าโปรเจกต์ / ผู้เข้าร่วม" อ่านจาก ProjectRoles เท่านั้น และเป็นคนละเรื่องกับ
     * บทบาทในระบบ (หัวหน้าแผนก / พนักงาน) ที่อ่านจาก RoleLabel — สองอย่างนี้ห้ามปนกันบนจอ
     *
     * $canOpenWorkspace ตัดสินที่ controller จาก WorkOrderPolicy::viewMemberWorkspace()
     * ตัวแปรนี้มีไว้เลือกว่าจะวาดลิงก์หรือไม่ ไม่ใช่ตัวกันสิทธิ์ — route ตรวจซ้ำเองทุกครั้ง
     */
    $canOpenWorkspace = $canOpenWorkspace ?? false;
    $allTasks = $allTasks ?? collect();
    // แท็บงานทั้งหมดวาดได้สูงสุดเท่านี้ ที่เหลือดูต่อในพื้นที่งาน — สมาชิกบางคนมีงานหลายร้อยใบ
    $allTaskLimit = 50;
    $operations = $operations ?? null;
    $createdTodayProjects = $createdTodayProjects ?? collect();
    $createdTodayTasks = $createdTodayTasks ?? collect();
    $summary = $summary ?? ['total' => 0, 'active' => 0, 'dueSoon' => 0, 'done' => 0];
    $roleCounts = $roleCounts ?? ['leader' => 0, 'participant' => 0, 'basis' => 0];
    $workspaceRoute = $workspaceRouteName ?? 'admin.work-board.member';
    $workspaceParameters = $workspaceRouteParameters ?? [$department, $member];
    $createdTodayCount = $createdTodayProjects->count() + $createdTodayTasks->count();
    $tabs = array_filter([
        'today' => ['งานวันนี้', $tasks->count()],
        // แท็บงานปฏิบัติมีเฉพาะผู้ที่ DepartmentWorkBoardQuery::memberOperations() อนุญาต ไม่ใช่แท็บว่างที่ซ่อนไว้
        'operations' => $operations ? ['งานปฏิบัติ', count($operations['items'])] : null,
        'created' => ['สร้างวันนี้', $createdTodayCount],
        'all' => ['งานทั้งหมด', $allTasks->count()],
    ]);
    $summaryCards = [
        ['total', 'bi-clipboard-check', 'งานทั้งหมด'],
        ['active', 'bi-play-circle', 'กำลังทำ'],
        ['dueSoon', 'bi-alarm', 'ใกล้ครบกำหนด'],
        ['done', 'bi-check-circle', 'เสร็จสิ้นแล้ว'],
    ];
@endphp

<section class="wb-member-preview" data-member-preview-content data-preview-member-id="{{ $member->id }}">
    {{--
        ส่วนหัวของแผง (รูป ชื่อ บทบาทในระบบ แผนก สรุปบทบาทในโปรเจกต์ และปุ่มเปิดพื้นที่งาน)
        ถูกย้ายไปวาดใน .wb-member-preview-panel__header โดย department.js
        จึงอยู่ใน <template> — ไม่ถูกวาดซ้ำในเนื้อหาที่เลื่อนได้
    --}}
    <template data-preview-header data-member-name="{{ $member->name }}">
        <span data-preview-header-avatar>@include('work-board.partials.avatar', ['user' => $member, 'size' => 'lg'])</span>
        <div data-preview-header-meta>
            <p class="wb-member-preview-panel__identity" title="บทบาทในระบบและแผนก">
                <span class="wb-member-preview-panel__system-role">{{ RoleLabel::for($member) }}</span>
                <span class="wb-member-preview-panel__separator" aria-hidden="true">•</span>
                <span class="wb-member-preview-panel__department" title="แผนก: {{ $department->department_name }}">{{ $department->department_name }}</span>
            </p>
            {{-- นับจากงานที่ยังไม่ปิดและผู้ดูมีสิทธิ์เปิดดู (DepartmentWorkBoardQuery::memberPreview) --}}
            @if(($roleCounts['basis'] ?? 0) > 0)
                <p class="wb-member-preview-panel__roles" data-preview-role-summary>
                    <span class="visually-hidden">บทบาทในโปรเจกต์ของงานที่ยังไม่ปิด:</span>
                    <span class="wb-project-role wb-project-role--leader">
                        <i class="bi bi-star-fill" aria-hidden="true"></i>หัวหน้าโปรเจกต์ <b data-preview-role-count="leader">{{ $roleCounts['leader'] }}</b>
                    </span>
                    <span class="wb-project-role wb-project-role--participant">
                        <i class="bi bi-people-fill" aria-hidden="true"></i>ผู้เข้าร่วม <b data-preview-role-count="participant">{{ $roleCounts['participant'] }}</b>
                    </span>
                </p>
            @endif
        </div>
        @if($canOpenWorkspace)
            <div data-preview-header-action>
                {{-- เข้าพื้นที่งานได้เสมอแม้สมาชิกยังไม่มีงาน — route ยังตรวจสิทธิ์ฝั่ง Server ตามเดิม --}}
                <a class="wb-member-preview__workspace-link"
                    href="{{ route($workspaceRoute, $workspaceParameters) }}"
                    title="เปิดพื้นที่งานของสมาชิก (ดูงานทั้งหมดแบบอ่านอย่างเดียว)"
                    aria-label="เปิดพื้นที่งานของสมาชิก (ดูงานทั้งหมดแบบอ่านอย่างเดียว)"
                    data-preview-workspace-link>
                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                    <span aria-hidden="true">เปิดพื้นที่งาน</span>
                </a>
                <small class="wb-member-preview__workspace-hint" aria-hidden="true"></small>
            </div>
        @endif
    </template>

    {{-- @else
        <div class="wb-member-preview__readonly" data-preview-readonly>
            <i class="bi bi-lock" aria-hidden="true"></i>
            <div>
                <strong>ดูแบบอ่านอย่างเดียว</strong>
                <span>ข้อมูลนี้เป็นภาพรวมงาน ไม่สามารถแก้ไขหรือเปิดพื้นที่งานของผู้อื่นได้</span>
            </div>
        </div> --}}

    {{-- ตัวเลขสรุป — นับเฉพาะงานที่ผู้ดูมีสิทธิ์ดู ผู้ที่ไม่เห็นงานใดเลยจึงไม่เห็นแถวนี้ --}}
    @if($summary['total'] > 0)
        <dl class="wb-preview-summary" data-preview-summary>
            @foreach($summaryCards as [$key, $icon, $label])
                <div class="wb-preview-summary__item wb-preview-summary__item--{{ $key }}">
                    <dt><i class="bi {{ $icon }}" aria-hidden="true"></i>{{ $label }}</dt>
                    <dd data-preview-summary-count="{{ $key }}">{{ $summary[$key] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    <div class="wb-preview-tabs" role="tablist" aria-label="หมวดงานของสมาชิก" data-preview-tabs>
        @foreach($tabs as $key => [$label, $count])
            <button type="button" class="wb-preview-tabs__tab {{ $key === 'today' ? 'is-active' : '' }}"
                role="tab" id="memberPreviewTab-{{ $key }}" aria-controls="memberPreviewPanel-{{ $key }}"
                aria-selected="{{ $key === 'today' ? 'true' : 'false' }}" tabindex="{{ $key === 'today' ? '0' : '-1' }}"
                data-preview-tab="{{ $key }}">
                {{ $label }} <b>{{ $count }}</b>
            </button>
        @endforeach
    </div>

    {{-- งานวันนี้ --}}
    <div class="wb-preview-tabpanel" role="tabpanel" id="memberPreviewPanel-today" aria-labelledby="memberPreviewTab-today" data-preview-tabpanel="today">
        <div class="wb-member-preview__section-heading">
            <h4>งานที่ต้องจัดการวันนี้</h4>
            @if($tasks->isNotEmpty())
                <span class="wb-count-chip" data-preview-task-count>{{ $tasks->count() }} งาน</span>
            @endif
        </div>

        <div class="wb-preview-task-list" data-preview-task-list>
            @forelse($tasks as $task)
                @include('work-board.partials.member-preview-task', ['task' => $task, 'mode' => 'today'])
            @empty
                <div class="wb-preview-empty" data-preview-empty>
                    <span><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                    <h5>วันนี้ไม่มีงานที่ต้องติดตาม</h5>
                    @if($canOpenWorkspace)
                        <p>เปิดพื้นที่งานของสมาชิกเพื่อดูงานทั้งหมด หรือสร้างโปรเจกต์และมอบหมายงานใหม่</p>
                    @else
                        <p>เมื่อมีงานที่ต้องจัดการในวันนี้ รายการจะแสดงที่นี่</p>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    {{-- งานปฏิบัติวันนี้ (งานประจำ/นอกสถานที่) — null เมื่อผู้ดูไม่มีสิทธิ์ (ดู memberOperations()) --}}
    @if($operations)
    <div class="wb-preview-tabpanel" role="tabpanel" id="memberPreviewPanel-operations" aria-labelledby="memberPreviewTab-operations" data-preview-tabpanel="operations" hidden>
        <div class="wb-member-preview__section-heading">
            <h4>งานปฏิบัติวันนี้</h4>
            <span class="wb-count-chip" data-preview-operation-count>{{ count($operations['items']) }} รายการ</span>
        </div>
        <div class="wb-preview-ops" data-preview-operations>
            @forelse($operations['items'] as $item)
                <article class="wb-preview-op">
                    <span class="wb-preview-op__icon wb-preview-op__icon--{{ $item['kind'] }}" aria-hidden="true"><i class="bi {{ $item['kind_icon'] }}"></i></span>
                    <div class="wb-preview-op__body">
                        <div class="wb-preview-op__title-row">
                            <h5>{{ $item['title'] }}</h5>
                            <span class="wb-preview-op__status wb-ops-tone-{{ $item['status_tone'] }}">{{ $item['status_label'] }}</span>
                        </div>
                        <p>
                            {{ $item['kind_label'] }} · {{ $item['category'] }}
                            @if($item['time']) · {{ $item['time'] }} @endif
                            @if($item['location']) · <i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $item['location'] }} @endif
                        </p>
                    </div>
                </article>
            @empty
                <p class="wb-preview-ops__empty">วันนี้ยังไม่มีงานประจำหรืองานนอกสถานที่</p>
            @endforelse
        </div>
    </div>
    @endif

    {{--
        สร้างวันนี้ — แยกจาก "งานวันนี้" โดยตั้งใจ
        งานที่สร้างวันนี้แต่วันเริ่มอยู่ในอนาคตยังไม่ใช่งานที่ต้องทำวันนี้ จึงอยู่ที่นี่ ไม่ถูกย้ายไปแท็บแรก
    --}}
    <div class="wb-preview-tabpanel" role="tabpanel" id="memberPreviewPanel-created" aria-labelledby="memberPreviewTab-created" data-preview-tabpanel="created" hidden>
        <div class="wb-member-preview__section-heading">
            <h4>โปรเจกต์และงานที่สร้างวันนี้</h4>
            @if($createdTodayCount > 0)
                <span class="wb-count-chip" data-preview-created-count>{{ $createdTodayCount }} รายการ</span>
            @endif
        </div>

        <div class="wb-preview-created" data-preview-created-today>
            @if($createdTodayCount === 0)
                <p class="wb-preview-created__empty" data-preview-created-empty>วันนี้ยังไม่มีโปรเจกต์หรืองานใหม่</p>
            @endif

            @foreach($createdTodayProjects as $project)
                <article class="wb-preview-project" data-preview-created-project="{{ $project->id }}">
                    <span class="wb-preview-project__icon" aria-hidden="true"><i class="bi bi-folder-plus"></i></span>
                    <div class="wb-preview-project__body">
                        <h5 title="{{ $project->name }}">{{ $project->name }}</h5>
                        <p>
                            <span class="wb-preview-project__badge">สร้างวันนี้</span>
                            <time datetime="{{ $project->created_at?->toIso8601String() }}">สร้างเวลา {{ TodayWorkspace::timeLabel($project->created_at) }}</time>
                        </p>
                    </div>
                    <span class="wb-preview-project__count" data-preview-project-task-count>
                        @if($project->shown_task_count > 0)
                            {{ $project->shown_task_count }} งาน
                        @elseif((int) $project->work_orders_count === 0)
                            ยังไม่มีรายการงาน
                        @else
                            ยังไม่มีงานของ {{ $member->name }}
                        @endif
                    </span>
                </article>
            @endforeach

            @foreach($createdTodayTasks as $task)
                @include('work-board.partials.member-preview-task', ['task' => $task, 'mode' => 'created'])
            @endforeach
        </div>
    </div>

    {{-- งานทั้งหมด — รับผิดชอบ ร่วมทำ หรือเปิดเอง รวมที่ปิดแล้ว เฉพาะใบที่ผู้ดูมีสิทธิ์ดู --}}
    <div class="wb-preview-tabpanel" role="tabpanel" id="memberPreviewPanel-all" aria-labelledby="memberPreviewTab-all" data-preview-tabpanel="all" hidden>
        <div class="wb-member-preview__section-heading">
            <h4>งานทั้งหมดที่รับผิดชอบ ร่วมทำ หรือเปิดเอง</h4>
            @if($allTasks->isNotEmpty())
                <span class="wb-count-chip" data-preview-all-count>{{ $allTasks->count() }} งาน</span>
            @endif
        </div>
        <div class="wb-preview-task-list" data-preview-all-list>
            @forelse($allTasks->take($allTaskLimit) as $task)
                @include('work-board.partials.member-preview-task', ['task' => $task, 'mode' => 'all'])
            @empty
                <p class="wb-preview-created__empty" data-preview-all-empty>ยังไม่มีงานที่คุณมีสิทธิ์ดู</p>
            @endforelse
        </div>
        @if($allTasks->count() > $allTaskLimit)
            <p class="wb-preview-all__more" data-preview-all-more>
                แสดง {{ $allTaskLimit }} จาก {{ $allTasks->count() }} งาน
                @if($canOpenWorkspace)
                    · <a href="{{ route($workspaceRoute, $workspaceParameters) }}">ดูทั้งหมดในพื้นที่งาน</a>
                @endif
            </p>
        @endif
    </div>
</section>
