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

</div>
