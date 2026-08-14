<!DOCTYPE html>
<html lang="th">

<head>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ระบบงาน') | Smart Goal By PremiumCare</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap"
        rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    @vite('resources/css/components/layout.css')

    @stack('styles')
</head>

<body>
    @php
        $currentUser = auth()->user();
        $isAdmin = $currentUser?->role === 'admin';
        $isViewer = $currentUser?->role === 'viewer';
        $roleLabel = match ($currentUser?->role) {
            'admin' => 'ผู้ดูแลระบบ',
            'viewer' => 'ผู้เข้าชม',
            default => 'พนักงาน',
        };
        $pendingApprovalJobs = $isAdmin
            ? \App\Models\WorkOrder::with(['creator', 'user'])->where('approval_status', 'pending')->latest('job_id')->take(3)->get()
            : collect();
        $pendingDeleteJobs = $isAdmin
            ? \App\Models\WorkOrder::with(['deleteRequester', 'user'])->whereNotNull('delete_requested_at')->latest('delete_requested_at')->take(3)->get()
            : collect();
        $dueSoonJobs = \App\Models\WorkOrder::with('user')
            ->where('approval_status', 'approved')
            ->where('job_status', '!=', 4)
            ->whereBetween('job_due_at', [now(), now()->addDays(2)])
            ->where(function ($query) use ($currentUser, $isAdmin, $isViewer) {
                if ($isAdmin || $isViewer) {
                    return;
                }

                $query->where('user_id', $currentUser->id)
                    ->orWhere('leader_user_id', $currentUser->id)
                    ->orWhereHas('collaborators', function ($collaboratorQuery) use ($currentUser) {
                        $collaboratorQuery
                            ->where('users.id', $currentUser->id)
                            ->where('work_order_collaborators.status', 'accepted');
                    });
            })
            ->orderBy('job_due_at')
            ->take(3)
            ->get();
        $decisionJobs = $isAdmin
            ? collect()
            : \App\Models\WorkOrder::where('created_by', $currentUser->id)
                ->whereIn('approval_status', ['approved', 'rejected'])
                ->latest('approved_at')
                ->take(3)
                ->get();
        $pendingInvitations = \App\Models\WorkOrder::with('leader')
            ->whereHas('collaborators', function ($query) use ($currentUser) {
                $query
                    ->where('users.id', $currentUser->id)
                    ->where('work_order_collaborators.status', 'pending');
            })
            ->latest('job_id')
            ->take(3)
            ->get();
        $systemNotifications = \App\Models\SystemNotification::with('workOrder')
            ->where('user_id', $currentUser->id)
            ->latest()
            ->take(3)
            ->get();
        $notificationCount = ($isAdmin ? \App\Models\WorkOrder::where('approval_status', 'pending')->count() : 0)
            + ($isAdmin ? \App\Models\WorkOrder::whereNotNull('delete_requested_at')->count() : 0)
            + \App\Models\SystemNotification::where('user_id', $currentUser->id)->count()
            + \App\Models\WorkOrder::whereHas('collaborators', function ($query) use ($currentUser) {
                $query
                    ->where('users.id', $currentUser->id)
                    ->where('work_order_collaborators.status', 'pending');
            })->count()
            + $dueSoonJobs->count()
            + $decisionJobs->count();
    @endphp

    <aside class="sidebar" id="appSidebar">
        <div class="sidebar-brand">
            <img src="{{ asset('images/premium-care.jpg') }}" alt="PremiumCare" class="brand-logo">
            <div>
                <div class="name">Smart Goal</div>
                <div class="sub">BY PREMIUMCARE</div>
            </div>
            <button type="button" class="sidebar-close" aria-label="ปิดเมนู" data-sidebar-close>
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="sidebar-nav">
            @if ($isAdmin)
                <div class="nav-section-label">ผู้บริหาร</div>

                <a href="{{ route('board.index') }}" class="nav-item {{ request()->routeIs('board.*') ? 'active' : '' }}">
                    <i class="bi bi-kanban-fill"></i> บอร์ดรวม
                </a>
            @endif

            @if ($isViewer)
                <div class="nav-section-label">ดูข้อมูล</div>

                <a href="{{ route('board.index') }}" class="nav-item {{ request()->routeIs('board.*') ? 'active' : '' }}">
                    <i class="bi bi-grid-1x2-fill"></i> แดชบอร์ด
                </a>

                <a href="{{ route('employees.index') }}" class="nav-item {{ request()->routeIs('employees.*') ? 'active' : '' }}">
                    <i class="bi bi-people-fill"></i> พนักงาน
                </a>

                <a href="{{ route('reports.index') }}" class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <i class="bi bi-bar-chart-line-fill"></i> รายงาน
                </a>
            @endif

            <div class="nav-section-label">{{ $isAdmin ? 'การจัดการ' : 'พื้นที่ของฉัน' }}</div>

            @if (! $isAdmin && ! $isViewer)
                <a href="{{ route('work-board.index') }}"
                    class="nav-item {{ request()->routeIs('work-board.*') ? 'active' : '' }}">
                    <i class="bi bi-kanban-fill"></i> บอร์ดงาน
                </a>
                <a href="{{ route('mytasks.index') }}"
                    class="nav-item {{ request()->routeIs('mytasks.*') ? 'active' : '' }}">
                    <i class="bi bi-briefcase"></i> งานของฉัน
                </a>
                <a href="{{ route('reports.my') }}"
                    class="nav-item {{ request()->routeIs('reports.my') ? 'active' : '' }}">
                    <i class="bi bi-clipboard-data-fill"></i> รายงานของฉัน
                </a>
            @endif

            @stack('sidebar_nav_extra')



            @if ($isAdmin)
                <a href="{{ route('employees.index') }}"
                    class="nav-item {{ request()->routeIs('employees.*') ? 'active' : '' }}">
                    <i class="bi bi-people-fill"></i> พนักงาน
                </a>
                <a href="{{ route('reports.index') }}"
                    class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <i class="bi bi-bar-chart-line-fill"></i> รายงาน
                </a>

                <div class="nav-section-label">ระบบ</div>

                <a href="{{ route('admin.trash.index') }}"
                    class="nav-item {{ request()->routeIs('admin.trash.*') ? 'active' : '' }}">
                    <i class="bi bi-trash3-fill"></i> ถังขยะ
                </a>
                <a href="{{ route('admin.activity-logs.index') }}"
                    class="nav-item {{ request()->routeIs('admin.activity-logs.*') ? 'active' : '' }}">
                    <i class="bi bi-clock-history"></i> บันทึกระบบ
                </a>
            @endif

                   <a href="{{ route('settings.index') }}" class="nav-item {{ request()->routeIs('settings.*') ? 'active' : '' }}">
                <i class="bi bi-gear-fill"></i> ตั้งค่า
            </a>

            {{-- <div class="nav-section-label">ระบบ</div> --}}
            {{-- <a href="{{ route('settings.index') }}"
                class="nav-item {{ request()->routeIs('settings.*') ? 'active' : '' }}">
                <i class="bi bi-gear-fill"></i> ตั้งค่า
            </a> --}}
        </div>

        <div class="sidebar-foot">
            <div class="avatar">
                @if($currentUser->profile_image)
                    <img src="{{ route('media.show', ['path' => $currentUser->profile_image]) }}" alt="{{ $currentUser->name }}">
                @else
                    {{ strtoupper(substr($currentUser->name, 0, 2)) }}
                @endif
            </div>

            <div class="flex-grow-1">
                <div class="who">{{ $currentUser->name }}</div>
                <div class="role">{{ $roleLabel }}{{ optional($currentUser->department)->department_name ? ' · ' . optional($currentUser->department)->department_name : '' }}</div>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="icon-btn icon-btn-compact" title="ออกจากระบบ">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </form>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <header class="topbar">
        <button type="button" class="mobile-menu-btn" aria-label="เปิดเมนู" aria-controls="appSidebar" aria-expanded="false" data-sidebar-open>
            <i class="bi bi-list"></i>
        </button>
        <div class="ms-auto d-flex align-items-center gap-2">
            <span class="role-chip {{ $isAdmin ? 'admin' : 'user' }}">
                <i class="bi {{ $isAdmin ? 'bi-shield-check' : 'bi-person-check' }}"></i>
                {{ $roleLabel }}
            </span>
            <div class="dropdown">
                <button class="icon-btn" data-bs-toggle="dropdown" aria-expanded="false" title="แจ้งเตือน">
                    <i class="bi bi-bell-fill"></i>
                    @if($notificationCount > 0)
                        <span class="notification-count">{{ $notificationCount > 99 ? '99+' : $notificationCount }}</span>
                    @endif
                </button>
                <div class="dropdown-menu dropdown-menu-end p-2 notification-menu">
                    <div class="d-flex align-items-center justify-content-between px-2 py-2">
                        <strong>การแจ้งเตือน</strong>
                        <span class="badge-soft {{ $notificationCount > 0 ? 'amber' : 'gray' }}">{{ $notificationCount }} รายการ</span>
                    </div>
                    @if($pendingApprovalJobs->count() > 0)
                        <div class="px-2 pb-1 text-muted notification-section-title">คำขอเปิดงานรออนุมัติ</div>
                        @foreach($pendingApprovalJobs as $pendingJob)
                            <div class="p-2 mb-2 notification-item">
                                <div class="notification-title">{{ $pendingJob->job_topic }}</div>
                                <div class="notification-meta notification-meta-spaced">
                                    ผู้ขอ: {{ optional($pendingJob->creator)->name ?? optional($pendingJob->user)->name ?? '-' }}
                                </div>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('tasks.show', $pendingJob->job_id) }}" class="btn btn-sm btn-outline-secondary">ดู</a>
                                    <form method="POST" action="{{ route('admin.tasks.approval', $pendingJob->job_id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="approval_status" value="approved">
                                        <button type="submit" class="btn btn-sm btn-success">อนุมัติ</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.tasks.approval', $pendingJob->job_id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="approval_status" value="rejected">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">ปฏิเสธ</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    @endif

                    @if($pendingDeleteJobs->count() > 0)
                        <div class="px-2 pb-1 text-muted notification-section-title">คำขอลบงาน</div>
                        @foreach($pendingDeleteJobs as $deleteJob)
                            <div class="p-2 mb-2 notification-item">
                                <div class="notification-title">{{ $deleteJob->job_topic }}</div>
                                <div class="notification-meta notification-meta-spaced">
                                    ผู้ขอ: {{ optional($deleteJob->deleteRequester)->name ?? '-' }}
                                </div>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('tasks.show', $deleteJob->job_id) }}" class="btn btn-sm btn-outline-secondary">ดู</a>
                                    <form method="POST" action="{{ route('admin.tasks.deleteRequest.approve', $deleteJob->job_id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">อนุมัติลบ</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.tasks.deleteRequest.reject', $deleteJob->job_id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">ปฏิเสธ</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    @endif

                    @if($systemNotifications->count() > 0)
                        <div class="px-2 pb-1 text-muted notification-section-title">การเปลี่ยนแปลงงาน</div>
                        @foreach($systemNotifications as $notice)
                            <div class="p-2 mb-2 notification-item d-flex gap-2 align-items-start {{ $notice->read_at ? '' : 'is-new' }}">
                                <a href="{{ $notice->work_order_id ? route('tasks.show', $notice->work_order_id) : '#' }}" class="notification-body">
                                    <div class="notification-title">
                                        {{ $notice->title }}
                                        @if(! $notice->read_at)
                                            <span class="notification-new">ใหม่</span>
                                        @endif
                                    </div>
                                    <div class="notification-meta notification-meta-tight">
                                        {{ $notice->message }}
                                    </div>
                                </a>
                                <form method="POST" action="{{ route('notifications.destroy', $notice->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="notification-delete" title="ลบการแจ้งเตือน">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    @endif

                    @if($pendingInvitations->count() > 0)
                        <div class="px-2 pb-1 text-muted notification-section-title">คำเชิญร่วมงาน</div>
                        @foreach($pendingInvitations as $inviteJob)
                            <div class="p-2 mb-2 notification-item">
                                <div class="notification-title">{{ $inviteJob->job_topic }}</div>
                                <div class="notification-meta notification-meta-spaced">
                                    ชวนโดย: {{ optional($inviteJob->leader)->name ?? '-' }}
                                </div>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('tasks.show', $inviteJob->job_id) }}" class="btn btn-sm btn-outline-secondary">ดู</a>
                                    <form method="POST" action="{{ route('tasks.invitation.respond', $inviteJob->job_id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="accepted">
                                        <button type="submit" class="btn btn-sm btn-success">รับ</button>
                                    </form>
                                    <form method="POST" action="{{ route('tasks.invitation.respond', $inviteJob->job_id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="rejected">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">ปฏิเสธ</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    @endif

                    @if($dueSoonJobs->count() > 0)
                        <div class="px-2 pb-1 text-muted notification-section-title">งานใกล้ครบกำหนด</div>
                        @foreach($dueSoonJobs as $dueJob)
                            <a href="{{ route('tasks.show', $dueJob->job_id) }}" class="d-block p-2 mb-2 notification-item">
                                <div class="notification-title">{{ $dueJob->job_topic }}</div>
                                <div class="notification-meta notification-meta-tight">
                                    กำหนดส่ง {{ $dueJob->job_due_at->locale('th')->isoFormat('D MMM YYYY HH:mm') }}
                                </div>
                            </a>
                        @endforeach
                    @endif

                    @if($decisionJobs->count() > 0)
                        <div class="px-2 pb-1 text-muted notification-section-title">ผลการอนุมัติงาน</div>
                        @foreach($decisionJobs as $decisionJob)
                            <a href="{{ route('tasks.show', $decisionJob->job_id) }}" class="d-block p-2 mb-2 notification-item">
                                <div class="notification-title">{{ $decisionJob->job_topic }}</div>
                                <div @class(['notification-meta', 'notification-meta-tight', 'notification-decision-approved' => $decisionJob->approval_status === 'approved', 'notification-decision-rejected' => $decisionJob->approval_status !== 'approved'])>
                                    {{ $decisionJob->approval_status === 'approved' ? 'อนุมัติแล้ว' : 'ถูกปฏิเสธ' }}
                                </div>
                            </a>
                        @endforeach
                    @endif

                    @if($notificationCount === 0)
                        <div class="text-center text-muted py-4 notification-dropdown-empty">ไม่มีคำขอเปิดงานที่รออนุมัติ</div>
                    @elseif($notificationCount > 12)
                        <div class="notification-more">แสดงรายการล่าสุดบางส่วน เพื่อไม่ให้หน้าต่างแจ้งเตือนยาวเกินไป</div>
                    @endif
                </div>
            </div>
            <a href="{{ route('settings.index') }}" class="icon-btn" title="ช่วยเหลือและตั้งค่า"><i class="bi bi-question-circle"></i></a>
        </div>
    </header>

    <main class="main">
        @yield('content')
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (() => {
            const body = document.body;
            const openButton = document.querySelector('[data-sidebar-open]');
            const closeTargets = document.querySelectorAll('[data-sidebar-close]');
            const navLinks = document.querySelectorAll('.sidebar .nav-item');
            const isDesktop = () => window.innerWidth > 991;

            if (isDesktop() && localStorage.getItem('smartgoal.sidebar.collapsed') === '1') {
                body.classList.add('sidebar-collapsed');
                openButton?.setAttribute('aria-expanded', 'false');
            }

            const closeSidebar = () => {
                if (isDesktop()) {
                    body.classList.add('sidebar-collapsed');
                    localStorage.setItem('smartgoal.sidebar.collapsed', '1');
                } else {
                    body.classList.remove('sidebar-open');
                }
                openButton?.setAttribute('aria-expanded', 'false');
            };

            openButton?.addEventListener('click', () => {
                if (isDesktop()) {
                    const isCollapsed = body.classList.toggle('sidebar-collapsed');
                    localStorage.setItem('smartgoal.sidebar.collapsed', isCollapsed ? '1' : '0');
                    openButton.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                    return;
                }

                const isOpen = body.classList.toggle('sidebar-open');
                openButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            closeTargets.forEach((target) => target.addEventListener('click', closeSidebar));
            navLinks.forEach((link) => link.addEventListener('click', () => {
                if (! isDesktop()) closeSidebar();
            }));

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') closeSidebar();
            });

            window.addEventListener('resize', () => {
                body.classList.remove('sidebar-open');
                if (! isDesktop()) {
                    openButton?.setAttribute('aria-expanded', 'false');
                }
            });
        })();
    </script>
    @stack('scripts')
</body>

</html>

