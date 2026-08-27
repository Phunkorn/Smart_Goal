@php
    $totalJobs = $jobs->count();

    $totalProjects = $jobs->pluck('work_order_list_id')->filter()->unique()->count();

    $doneJobs = $jobs->where('job_status', 4)->count();

    $completionRate = $totalJobs > 0 ? (int) round(($doneJobs / $totalJobs) * 100) : 0;

    $departmentRows = $workloadByDepartment;

    if ($currentDeptId) {
        $departmentRows = $departmentRows->where('id', $currentDeptId)->values();
    }

    if ($search !== '') {
        $departmentRows = $departmentRows
            ->filter(
                fn($row) => $row['total_jobs'] > 0 || str_contains(mb_strtolower($row['name']), mb_strtolower($search)),
            )
            ->values();
    }

    $recentJobs = $jobs->sortByDesc('updated_at')->take(3);

    $attention = $attentionJobs->take(3);

    $adminJobContext = static function ($job) use ($canManageTasks): array {
        $assignee = $job->user;
        $department = $assignee?->department ?? $job->department;
        $canOpenMemberWorkspace = $assignee
            && $department
            && $assignee->role === 'user'
            && (int) $assignee->department_id === (int) $department->id;

        return [
            'assignee' => $assignee,
            'department' => $department,
            'url' => $canManageTasks
                ? ($canOpenMemberWorkspace
                    ? route('admin.work-board.member', [$department, $assignee])
                    : route('admin.tasks.show', $job->job_id))
                : route('tasks.show', $job->job_id),
        ];
    };
@endphp

<div class="admin-board-overview">

    {{-- HEADER --}}
    <header class="admin-board-header">
        <div>
            <span class="admin-board-eyebrow">ADMIN</span>

            <h1>บอร์ดทุกแผนก</h1>

            <p>
                ภาพรวมการทำงานและประสิทธิภาพของทุกแผนกในองค์กร
            </p>
        </div>
    </header>

    @include('board.components.assignment-approval-queue', ['pendingAssignments' => $pendingAssignments])
    @include('board.components.collaborator-approval-queue', [
        'pendingCollaboratorTasks' => $pendingCollaboratorTasks,
        'pendingCollaboratorInviters' => $pendingCollaboratorInviters,
    ])

    {{-- FILTER --}}
    <form method="GET" action="{{ route('board.index') }}" class="admin-board-filter">

        <label class="admin-board-search">
            <i class="bi bi-search"></i>

            <input type="search" name="search" value="{{ $search }}"
                placeholder="ค้นหาแผนก ชื่องาน หรือผู้รับผิดชอบ...">
        </label>


        <select name="department_id" aria-label="เลือกแผนก">
            <option value="">ทุกแผนก</option>

            @foreach ($departments as $department)
                <option value="{{ $department->id }}" @selected($currentDeptId === $department->id)>
                    {{ $department->department_name }}
                </option>
            @endforeach
        </select>


        <select name="assignee" aria-label="เลือกผู้รับผิดชอบ">
            <option value="">ผู้รับผิดชอบทั้งหมด</option>

            @foreach ($employees as $employee)
                <option value="{{ $employee->id }}" @selected($currentAssignee === $employee->id)>
                    {{ $employee->name }}
                </option>
            @endforeach
        </select>


        <select name="status" aria-label="เลือกสถานะ">
            <option value="">สถานะทั้งหมด</option>

            <option value="1" @selected($currentStatus === '1')>
                ยังไม่เริ่ม
            </option>

            <option value="2" @selected($currentStatus === '2')>
                กำลังทำ
            </option>

            <option value="3" @selected($currentStatus === '3')>
                รอตรวจสอบ
            </option>

            <option value="4" @selected($currentStatus === '4')>
                เสร็จสิ้น
            </option>

            <option value="5" @selected($currentStatus === '5')>
                พักงาน
            </option>

            <option value="late" @selected($currentStatus === 'late')>
                ล่าช้า
            </option>
        </select>


        <div class="admin-board-filter-controls">
            <button type="submit" class="admin-board-filter-submit">
                <i class="bi bi-funnel" aria-hidden="true"></i>
                กรอง
            </button>

            @if ($search !== '' || $currentDeptId || $currentAssignee || $currentStatus !== '')
                <a href="{{ route('board.index') }}" class="admin-board-filter-reset">
                    ล้างตัวกรอง
                </a>
            @endif
        </div>

        @if($canManageTasks)
            @include('board.components.admin-assignment-trigger')
        @endif

    </form>


    {{-- DEPARTMENT LIST --}}
    <section class="admin-board-panel admin-department-panel">

        <header class="admin-board-panel-header">
            <div>
                <h2>รายการแผนกทั้งหมด</h2>

                <p>
                    {{ $departmentRows->count() }} แผนกในระบบ
                </p>
            </div>
        </header>


        <div class="admin-department-list">

            <div class="admin-department-table-head">
                <span>แผนก</span>
                <span>สมาชิก</span>
                <span>โปรเจกต์</span>
                <span>งานทั้งหมด</span>
                <span>ความคืบหน้า</span>
                <span>จัดการ</span>
            </div>


            @forelse($departmentRows as $row)
                <article class="admin-department-row">

                    <div class="admin-department-identity">

                        <span class="admin-department-code">
                            {{ $row['code'] }}
                        </span>

                        <div>
                            <h3>{{ $row['name'] }}</h3>

                            <p>
                                ข้อมูลล่าสุดจากระบบ Smart Goal
                            </p>
                        </div>

                    </div>


                    <div class="admin-department-stat">
                        <i class="bi bi-people"></i>

                        <strong>
                            {{ $row['employee_count'] }}
                        </strong>

                        <span>คน</span>
                    </div>


                    <div class="admin-department-stat">
                        <i class="bi bi-folder"></i>

                        <strong>
                            {{ $row['project_count'] }}
                        </strong>

                        <span>โปรเจกต์</span>
                    </div>


                    <div class="admin-department-stat">
                        <i class="bi bi-clipboard-check"></i>

                        <strong>
                            {{ $row['total_jobs'] }}
                        </strong>

                        <span>งาน</span>
                    </div>


                    <div class="admin-department-progress">

                        <div class="admin-progress-ring">

                            <svg viewBox="0 0 36 36" aria-hidden="true">
                                <circle class="admin-progress-ring-bg" cx="18" cy="18" r="15.5" />

                                <circle class="admin-progress-ring-value" cx="18" cy="18" r="15.5"
                                    pathLength="100" stroke-dasharray="{{ $row['completion_rate'] }} 100" />
                            </svg>

                            <strong>
                                {{ $row['completion_rate'] }}%
                            </strong>

                        </div>

                        <span>เสร็จสิ้น</span>

                    </div>


                    <div class="admin-department-action">

                        <a href="{{ route('admin.work-board.department', $row['id']) }}">
                            ดูรายละเอียด
                            <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>

                </article>

            @empty

                <div class="admin-board-empty">
                    ไม่พบข้อมูลแผนกตามเงื่อนไขที่เลือก
                </div>
            @endforelse

        </div>

    </section>


    {{-- BOTTOM DASHBOARD --}}
    <section class="admin-board-bottom-grid">

        {{-- SUMMARY --}}
        <article class="admin-board-panel">

            <header class="admin-board-panel-header">
                <div>
                    <h2>สรุปภาพรวมองค์กร</h2>
                    <p>ข้อมูลภาพรวมการทำงาน</p>
                </div>
            </header>


            <div class="admin-summary-grid">

                <div class="admin-summary-item">
                    <span>ความคืบหน้าโดยรวม</span>

                    <strong>
                        {{ $completionRate }}%
                    </strong>

                    <progress max="100" value="{{ $completionRate }}"></progress>
                </div>


                <div class="admin-summary-item">
                    <span>โปรเจกต์ทั้งหมด</span>

                    <strong>
                        {{ $totalProjects }}
                    </strong>

                    <small>โปรเจกต์</small>
                </div>


                <div class="admin-summary-item">
                    <span>งานทั้งหมด</span>

                    <strong>
                        {{ $totalJobs }}
                    </strong>

                    <small>งาน</small>
                </div>

            </div>

        </article>


        {{-- RECENT --}}
        <article class="admin-board-panel">

            <header class="admin-board-panel-header">
                <div>
                    <h2>กิจกรรมล่าสุด</h2>
                    <p>อัปเดตล่าสุดจากงานในระบบ</p>
                </div>
            </header>


            <div class="admin-activity-list">

                @forelse($recentJobs as $job)
                    @php($jobContext = $adminJobContext($job))
                    <a href="{{ $jobContext['url'] }}" class="admin-activity-row">

                        @if($jobContext['assignee'])
                            @include('work-board.partials.avatar', ['user' => $jobContext['assignee'], 'size' => 'md'])
                        @else
                            <span class="wb-avatar wb-avatar--md" title="ไม่ระบุผู้รับผิดชอบ">?</span>
                        @endif

                        <div>
                            <strong>
                                {{ $job->job_topic }}
                            </strong>

                            <span>
                                {{ $jobContext['assignee']?->name ?? 'ไม่ระบุผู้รับผิดชอบ' }}
                                ·
                                {{ $jobContext['department']?->department_name ?? 'ไม่ระบุแผนก' }}
                            </span>
                        </div>

                        <time>
                            {{ optional($job->updated_at)->diffForHumans() }}
                        </time>

                    </a>

                @empty

                    <div class="admin-board-empty">
                        ยังไม่มีกิจกรรมล่าสุด
                    </div>
                @endforelse

            </div>

        </article>


        {{-- ATTENTION --}}
        <article class="admin-board-panel">

            <header class="admin-board-panel-header">
                <div>
                    <h2>งานที่ต้องติดตาม</h2>
                    <p>งานล่าช้าหรือใกล้ครบกำหนด</p>
                </div>
            </header>


            <div class="admin-attention-list">

                @forelse($attention as $job)
                    @php($jobContext = $adminJobContext($job))
                    <a href="{{ $jobContext['url'] }}" class="admin-attention-row">

                        @if($jobContext['assignee'])
                            @include('work-board.partials.avatar', ['user' => $jobContext['assignee'], 'size' => 'md'])
                        @else
                            <span class="wb-avatar wb-avatar--md" title="ไม่ระบุผู้รับผิดชอบ">?</span>
                        @endif

                        <div>
                            <strong>
                                {{ $job->job_topic }}
                            </strong>

                            <span>
                                {{ $jobContext['assignee']?->name ?? 'ไม่ระบุผู้รับผิดชอบ' }}
                                ·
                                {{ $jobContext['department']?->department_name ?? 'ไม่ระบุแผนก' }}
                            </span>
                        </div>

                        <time>
                            @if ($job->is_overdue)
                                เกินกำหนด
                            @elseif($job->job_due_at)
                                {{ \Carbon\Carbon::parse($job->job_due_at)->locale('th')->isoFormat('D MMM YYYY') }}
                            @endif
                        </time>

                    </a>

                @empty

                    <div class="admin-board-empty">
                        ไม่มีงานที่ต้องติดตาม
                    </div>
                @endforelse

            </div>

        </article>

    </section>

</div>
