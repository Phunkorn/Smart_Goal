@php
    use App\Support\RoleLabel;
    use App\Support\TodayWorkspace;

    $canOpenWorkspace = $canOpenWorkspace ?? false;
    $allTasks = $allTasks ?? collect();
    $allTaskLimit = 50;
    $operations = $operations ?? null;
    $createdTodayProjects = $createdTodayProjects ?? collect();
    $createdTodayTasks = $createdTodayTasks ?? collect();
    $summary = $summary ?? ['total' => 0, 'done' => 0];
    $workspaceRoute = $workspaceRouteName ?? 'admin.work-board.member';
    $workspaceParameters = $workspaceRouteParameters ?? [$department, $member];
    $createdTodayCount = $createdTodayProjects->count() + $createdTodayTasks->count();
    $tabs = array_filter([
        'today' => ['งานวันนี้', $tasks->count(), 'งานที่ต้องจัดการวันนี้'],
        'operations' => $operations ? ['งานปฏิบัติ', count($operations['items']), 'งานปฏิบัติวันนี้'] : null,
        'created' => ['สร้างวันนี้', $createdTodayCount, 'โปรเจกต์และงานที่สร้างวันนี้'],
        'all' => ['งานทั้งหมด', $allTasks->count(), 'งานทั้งหมดที่รับผิดชอบ ร่วมทำ หรือเปิดเอง'],
    ]);
@endphp

<section class="wb-spotlight" data-member-preview-content data-preview-member-id="{{ $member->id }}">
    <template data-preview-header data-member-name="{{ $member->name }}">
        <span data-preview-header-avatar>@include('work-board.partials.avatar', ['user' => $member, 'size' => 'lg'])</span>
        <div data-preview-header-meta>
            <p class="wb-spotlight__member-meta">
                <span>{{ RoleLabel::for($member) }}</span>
                <span aria-hidden="true">·</span>
                <span title="แผนก: {{ $department->department_name }}">{{ $department->department_name }}</span>
            </p>
        </div>
        @if($canOpenWorkspace)
            <div data-preview-header-action>
                <a class="wb-spotlight__icon-button"
                    href="{{ route($workspaceRoute, $workspaceParameters) }}"
                    title="เปิดพื้นที่งานของสมาชิก (ดูงานทั้งหมดแบบอ่านอย่างเดียว)"
                    aria-label="เปิดพื้นที่งานของสมาชิก (ดูงานทั้งหมดแบบอ่านอย่างเดียว)"
                    data-preview-workspace-link>
                    <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
                </a>
            </div>
        @endif
    </template>

    <div class="wb-spotlight__controls">
        <div class="wb-spotlight__summary">
            <span><strong data-preview-actionable-count>{{ $tasks->count() }}</strong> งานที่ต้องจัดการ</span>
            {{-- ตัวเลขเสร็จสิ้นและงานทั้งหมดนับจากงานที่ผู้ดูมีสิทธิ์เปิด --}}
            @if($canOpenWorkspace || $summary['total'] > 0)
                <span data-preview-summary>เสร็จสิ้น <b data-preview-summary-count="done">{{ $summary['done'] }}</b>
                    / <b data-preview-summary-count="total">{{ $summary['total'] }}</b> งาน</span>
            @endif
        </div>

        <div class="wb-preview-tabs" role="tablist" aria-label="หมวดงานของสมาชิก" data-preview-tabs>
            @foreach($tabs as $key => [$label, $count])
                <button type="button" class="wb-preview-tabs__tab" role="tab"
                    id="memberPreviewTab-{{ $key }}" aria-controls="memberPreviewPanel-{{ $key }}"
                    aria-selected="{{ $key === 'today' ? 'true' : 'false' }}" tabindex="{{ $key === 'today' ? '0' : '-1' }}"
                    data-preview-tab="{{ $key }}">
                    {{ $label }} <b>{{ $count }}</b>
                </button>
            @endforeach
        </div>
    </div>

    @foreach($tabs as $key => [$label, $count, $heading])
        <div class="wb-preview-tabpanel" role="tabpanel" tabindex="0"
            id="memberPreviewPanel-{{ $key }}" aria-labelledby="memberPreviewTab-{{ $key }}" data-preview-tabpanel="{{ $key }}" @if($key !== 'today')hidden @endif>
            <h3 class="visually-hidden">{{ $heading }}</h3>

            @switch($key)
                @case('today')
                    <div data-preview-task-list>
                        @forelse($tasks as $task)
                            @include('work-board.partials.member-preview-task', ['task' => $task, 'mode' => 'today'])
                        @empty
                            <div class="wb-spotlight__empty" data-preview-empty>
                                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                                <h4>วันนี้ไม่มีงานที่ต้องติดตาม</h4>
                                @if($canOpenWorkspace)
                                    <p>เปิดพื้นที่งานของสมาชิกเพื่อดูงานทั้งหมด หรือสร้างโปรเจกต์และมอบหมายงานใหม่</p>
                                @else
                                    <p>เมื่อมีงานที่ต้องจัดการในวันนี้ รายการจะแสดงที่นี่</p>
                                @endif
                            </div>
                        @endforelse
                    </div>
                    @break

                @case('operations')
                    <div data-preview-operations>
                        @forelse($operations['items'] as $item)
                            <article class="wb-spotlight-row">
                                <i class="bi {{ $item['kind_icon'] }} wb-spotlight-row__icon" aria-hidden="true"></i>
                                <div class="wb-spotlight-row__content">
                                    <h4 class="wb-spotlight-row__title">{{ $item['title'] }}</h4>
                                    <div class="wb-spotlight-row__detail">
                                        <span>{{ $item['kind_label'] }} · {{ $item['category'] }}</span>
                                        @if($item['location'])
                                            <span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $item['location'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="wb-spotlight-row__meta">
                                    <span class="wb-spotlight-row__status {{ $item['status_tone'] === 'red' ? 'wb-spotlight-row__late' : '' }}">{{ $item['status_label'] }}</span>
                                    @if($item['time'])
                                        <span>{{ $item['time'] }}</span>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <p class="wb-spotlight__empty">วันนี้ยังไม่มีงานประจำหรืองานนอกสถานที่</p>
                        @endforelse
                    </div>
                    @break

                @case('created')
                    <div data-preview-created-today>
                        @if($createdTodayCount === 0)
                            <p class="wb-spotlight__empty" data-preview-created-empty>วันนี้ยังไม่มีโปรเจกต์หรืองานใหม่</p>
                        @endif

                        @foreach($createdTodayProjects as $project)
                            <article class="wb-spotlight-row" data-preview-created-project="{{ $project->id }}">
                                <i class="bi bi-folder-plus wb-spotlight-row__icon" aria-hidden="true"></i>
                                <div class="wb-spotlight-row__content">
                                    <h4 class="wb-spotlight-row__title">{{ $project->name }}</h4>
                                    <div class="wb-spotlight-row__detail">
                                        <time datetime="{{ $project->created_at?->toIso8601String() }}">สร้างเวลา {{ TodayWorkspace::timeLabel($project->created_at) }}</time>
                                    </div>
                                </div>
                                <span class="wb-spotlight-row__meta" data-preview-project-task-count>
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
                    @break

                @case('all')
                    <div data-preview-all-list>
                        @forelse($allTasks->take($allTaskLimit) as $task)
                            @include('work-board.partials.member-preview-task', ['task' => $task, 'mode' => 'all'])
                        @empty
                            <p class="wb-spotlight__empty" data-preview-all-empty>ยังไม่มีงานที่คุณมีสิทธิ์ดู</p>
                        @endforelse
                    </div>
                    @if($allTasks->count() > $allTaskLimit)
                        <p class="wb-spotlight__more" data-preview-all-more>
                            แสดง {{ $allTaskLimit }} จาก {{ $allTasks->count() }} งาน
                            @if($canOpenWorkspace)
                                · <a href="{{ route($workspaceRoute, $workspaceParameters) }}">ดูทั้งหมดในพื้นที่งาน</a>
                            @endif
                        </p>
                    @endif
                    @break
            @endswitch
        </div>
    @endforeach
</section>
