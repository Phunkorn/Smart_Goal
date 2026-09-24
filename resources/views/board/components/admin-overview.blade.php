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

        @if($canManageTasks)
            @include('board.components.admin-assignment-trigger')
        @endif
    </header>

    <section class="admin-board-panel admin-department-panel">

        <header class="admin-board-panel-header">
            <div>
                <span class="admin-board-panel-eyebrow">ภาพรวมองค์กร</span>
                <h2>แผนกทั้งหมด</h2>

                <p>
                    เลือกแผนกเพื่อดูสมาชิก งาน และสถานะการทำงานเชิงลึก
                </p>
            </div>
            <span class="admin-board-panel-count"><strong>{{ $departmentRows->count() }}</strong> แผนก</span>
        </header>


        <div class="admin-department-list">

            @forelse($departmentRows as $row)
                <article class="admin-department-row">
                    <header class="admin-department-identity">
                        <span class="admin-department-code" aria-hidden="true">{{ $row['code'] }}</span>
                        <div>
                            <h3>{{ $row['name'] }}</h3>
                            <p>{{ $row['project_count'] }} โปรเจกต์ · {{ $row['total_jobs'] }} งานทั้งหมด</p>
                        </div>
                        @if($row['overdue_count'] > 0)
                            <span class="admin-department-alert">
                                <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                                {{ $row['overdue_count'] }} งานล่าช้า
                            </span>
                        @endif
                    </header>

                    <div class="admin-department-metrics" aria-label="สรุปแผนก {{ $row['name'] }}">
                        <div class="admin-department-metric">
                            <i class="bi bi-people" aria-hidden="true"></i>
                            <span>สมาชิก</span>
                            <strong>{{ $row['employee_count'] }}</strong>
                        </div>
                        <div class="admin-department-metric">
                            <i class="bi bi-activity" aria-hidden="true"></i>
                            <span>กำลังดำเนินการ</span>
                            <strong>{{ $row['active_count'] }}</strong>
                        </div>
                        <div class="admin-department-metric">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i>
                            <span>เสร็จแล้ว</span>
                            <strong>{{ $row['done_count'] }}</strong>
                        </div>
                    </div>

                    <a class="admin-department-action" href="{{ route('admin.work-board.department', $row['id']) }}">
                        <span>เปิดบอร์ดแผนก</span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </article>

            @empty

                <div class="admin-board-empty">
                    ไม่พบข้อมูลแผนกตามเงื่อนไขที่เลือก
                </div>
            @endforelse

        </div>
    </section>

</div>
