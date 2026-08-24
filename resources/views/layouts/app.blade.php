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
        $notificationService = app(\App\Services\NotificationService::class);
        $systemNotifications = $notificationService->dropdown($currentUser);
        $notificationCount = $notificationService->unreadCount($currentUser);
        $notificationDisplayCount = $notificationService->displayCount($notificationCount);
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
                <a href="{{ route('meetings.index') }}" class="nav-item {{ request()->routeIs('meetings.*') ? 'active' : '' }}">
                    <i class="bi bi-calendar-event-fill"></i> การประชุม
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
                <a href="{{ route('meetings.index') }}" class="nav-item {{ request()->routeIs('meetings.*') ? 'active' : '' }}">
                    <i class="bi bi-calendar-event-fill"></i> การประชุม
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
                <a href="{{ route('meetings.index') }}" class="nav-item {{ request()->routeIs('meetings.*') ? 'active' : '' }}">
                    <i class="bi bi-calendar-event-fill"></i> การประชุม
                </a>
            @endif

            @stack('sidebar_nav_extra')

            <a href="{{ route('notifications.index') }}"
                class="nav-item {{ request()->routeIs('notifications.*') ? 'active' : '' }}">
                <i class="bi bi-bell-fill"></i>
                <span class="nav-item__label">การแจ้งเตือน</span>
                @if($notificationCount > 0)
                    <span class="nav-item__count" data-notification-count data-sidebar-notification-count>{{ $notificationDisplayCount }}</span>
                @endif
            </a>

            @if ($isAdmin)
                <a href="{{ route('employees.index') }}"
                    class="nav-item {{ request()->routeIs('employees.*') ? 'active' : '' }}">
                    <i class="bi bi-people-fill"></i> พนักงาน
                </a>
                <a href="{{ route('admin.departments.index') }}"
                    class="nav-item {{ request()->routeIs('admin.departments.*') ? 'active' : '' }}">
                    <i class="bi bi-diagram-3-fill"></i> จัดการแผนก
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
                    <img src="{{ route('media.profile', $currentUser) }}" alt="{{ $currentUser->name }}">
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
                        <span class="notification-count" data-notification-count data-bell-notification-count>{{ $notificationDisplayCount }}</span>
                    @endif
                </button>
                <div class="dropdown-menu dropdown-menu-end p-2 notification-menu">
                    <div class="d-flex align-items-center justify-content-between px-2 py-2">
                        <strong>การแจ้งเตือน</strong>
                        <span class="badge-soft {{ $notificationCount > 0 ? 'amber' : 'gray' }}" data-notification-summary>{{ $notificationCount }} รายการ</span>
                    </div>
                    @if($systemNotifications->count() > 0)
                        <div class="px-2 pb-1 text-muted notification-section-title">การเปลี่ยนแปลงงาน</div>
                        @foreach($systemNotifications as $notice)
                            <div class="p-2 mb-2 notification-item d-flex gap-2 align-items-start {{ $notice->read_at ? '' : 'is-new' }}">
                                <a href="{{ route('notifications.open', $notice) }}" class="notification-body">
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
                            </div>
                        @endforeach
                    @endif

                    @if($systemNotifications->isEmpty())
                        <div class="text-center text-muted py-4 notification-dropdown-empty">ไม่มีการแจ้งเตือน</div>
                    @elseif($systemNotifications->count() === 15)
                        <div class="notification-more">แสดงการแจ้งเตือนล่าสุด 15 รายการ</div>
                    @endif
                    <a href="{{ route('notifications.index') }}" class="notification-more d-block text-center">ดูการแจ้งเตือนทั้งหมด</a>
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
