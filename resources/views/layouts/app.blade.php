<!DOCTYPE html>
<html lang="th">

<head>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ระบบงาน') | Smart Goal</title>

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
    {{-- คืนสถานะย่อ/ขยายก่อน Sidebar ถูก render เพื่อไม่ให้เห็นการกระตุกตอนโหลดหน้า --}}
    <script>
        (() => {
            try {
                if (window.innerWidth > 991 && localStorage.getItem('smartgoal.sidebar.collapsed') === '1') {
                    document.body.classList.add('sidebar-collapsed');
                }
            } catch (error) {
                /* บางเบราว์เซอร์ปิด localStorage ไว้ ให้ถือว่าเป็นสถานะกางตามค่าเริ่มต้น */
            }
        })();
    </script>

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
            <span class="brand-mark" aria-hidden="true"><i class="bi bi-bullseye"></i></span>
            <div class="brand-text">
                <div class="name">Smart Goal</div>
            </div>
            <button type="button" class="sidebar-close" aria-label="ปิดเมนู" data-sidebar-close>
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="sidebar-nav">
            @if ($isAdmin)
                <div class="nav-section-label">ผู้บริหาร</div>

                {{-- เมนู "การประชุม" ย้ายไปเป็น view ที่ 4 ของ Admin Member Workspace แล้ว
                     เพื่อให้ผู้ดูแลดูประชุมในบริบทของสมาชิกที่กำลังเปิดอยู่ (route เดิมยังใช้งานได้) --}}
                <a href="{{ route('board.index') }}" class="nav-item {{ request()->routeIs('board.*') ? 'active' : '' }}">
                    <i class="bi bi-kanban"></i>
                    <span class="nav-item__label">บอร์ดรวม</span>
                </a>
            @endif

            @if ($isViewer)
                <div class="nav-section-label">ดูข้อมูล</div>

                <a href="{{ route('board.index') }}" class="nav-item {{ request()->routeIs('board.*') ? 'active' : '' }}">
                    <i class="bi bi-grid-1x2"></i>
                    <span class="nav-item__label">แดชบอร์ด</span>
                </a>

                <a href="{{ route('employees.index') }}" class="nav-item {{ request()->routeIs('employees.*') ? 'active' : '' }}">
                    <i class="bi bi-people"></i>
                    <span class="nav-item__label">พนักงาน</span>
                </a>

                <a href="{{ route('reports.index') }}" class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <i class="bi bi-bar-chart-line"></i>
                    <span class="nav-item__label">รายงาน</span>
                </a>
                <a href="{{ route('meetings.index') }}" class="nav-item {{ request()->routeIs('meetings.*') ? 'active' : '' }}">
                    <i class="bi bi-calendar-event"></i>
                    <span class="nav-item__label">การประชุม</span>
                </a>
            @endif

            @if ($isAdmin || $isViewer)
                <div class="nav-section-label">{{ $isAdmin ? 'การจัดการ' : 'พื้นที่ของฉัน' }}</div>
            @else
                {{-- พนักงาน: "งานของฉัน" เป็นศูนย์กลางงานและการประชุม จึงมาเป็นลำดับแรก --}}
                {{-- เมนู "การประชุม" ย้ายไปเป็น view ที่ 4 ในหน้างานของฉัน (route เดิมยังใช้งานได้) --}}
                <div class="nav-section-label">งานของฉัน</div>

                <a href="{{ route('mytasks.index') }}"
                    class="nav-item {{ request()->routeIs('mytasks.*') ? 'active' : '' }}">
                    <i class="bi bi-briefcase"></i>
                    <span class="nav-item__label">งานของฉัน</span>
                </a>
                <a href="{{ route('work-board.index') }}"
                    class="nav-item {{ request()->routeIs('work-board.*') ? 'active' : '' }}">
                    <i class="bi bi-kanban"></i>
                    <span class="nav-item__label">บอร์ดงาน</span>
                </a>
                <a href="{{ route('reports.my') }}"
                    class="nav-item {{ request()->routeIs('reports.my') ? 'active' : '' }}">
                    <i class="bi bi-clipboard-data"></i>
                    <span class="nav-item__label">รายงานของฉัน</span>
                </a>
            @endif

            @stack('sidebar_nav_extra')

            @if (! $isAdmin && ! $isViewer)
                <div class="nav-section-label">การสื่อสาร</div>
            @endif

            <a href="{{ route('notifications.index') }}"
                class="nav-item {{ request()->routeIs('notifications.*') ? 'active' : '' }}">
                <i class="bi bi-bell"></i>
                <span class="nav-item__label">การแจ้งเตือน</span>
                @if($notificationCount > 0)
                    <span class="nav-item__count" data-notification-count data-sidebar-notification-count>{{ $notificationDisplayCount }}</span>
                @endif
            </a>

            @if ($isAdmin)
                <a href="{{ route('admin.approvals.index') }}"
                    class="nav-item {{ request()->routeIs('admin.approvals.*') ? 'active' : '' }}">
                    <i class="bi bi-shield-check"></i>
                    <span class="nav-item__label">คำขออนุมัติ</span>
                    @if($approvalCounts['total'] > 0)
                        <span class="nav-item__count" data-approval-count>{{ $approvalCounts['total'] }}</span>
                    @endif
                </a>
                <a href="{{ route('employees.index') }}"
                    class="nav-item {{ request()->routeIs('employees.*') ? 'active' : '' }}">
                    <i class="bi bi-people"></i>
                    <span class="nav-item__label">พนักงาน</span>
                </a>
                <a href="{{ route('admin.departments.index') }}"
                    class="nav-item {{ request()->routeIs('admin.departments.*') ? 'active' : '' }}">
                    <i class="bi bi-diagram-3"></i>
                    <span class="nav-item__label">จัดการแผนก</span>
                </a>
                <a href="{{ route('reports.index') }}"
                    class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <i class="bi bi-bar-chart-line"></i>
                    <span class="nav-item__label">รายงาน</span>
                </a>

                <div class="nav-section-label">ระบบ</div>

                <a href="{{ route('admin.trash.index') }}"
                    class="nav-item {{ request()->routeIs('admin.trash.*') ? 'active' : '' }}">
                    <i class="bi bi-trash3"></i>
                    <span class="nav-item__label">ถังขยะ</span>
                </a>
                <a href="{{ route('admin.activity-logs.index') }}"
                    class="nav-item {{ request()->routeIs('admin.activity-logs.*') ? 'active' : '' }}">
                    <i class="bi bi-clock-history"></i>
                    <span class="nav-item__label">บันทึกระบบ</span>
                </a>
            @endif

            @if (! $isAdmin && ! $isViewer)
                <div class="nav-section-label">ระบบ</div>
            @endif

            <a href="{{ route('settings.index') }}" class="nav-item {{ request()->routeIs('settings.*') ? 'active' : '' }}">
                <i class="bi bi-gear"></i>
                <span class="nav-item__label">ตั้งค่า</span>
            </a>
        </div>

        <div class="sidebar-foot">
            <div class="avatar" title="{{ $currentUser->name }}">
                @if($currentUser->profile_image)
                    <img src="{{ route('media.profile', $currentUser) }}" alt="{{ $currentUser->name }}">
                @else
                    {{ strtoupper(substr($currentUser->name, 0, 2)) }}
                @endif
            </div>

            <div class="flex-grow-1 sidebar-foot__identity">
                <div class="who">{{ $currentUser->name }}</div>
                <div class="role">{{ $roleLabel }}{{ optional($currentUser->department)->department_name ? ' · ' . optional($currentUser->department)->department_name : '' }}</div>
            </div>

            <form method="POST" action="{{ route('logout') }}" class="sidebar-foot__logout">
                @csrf
                <button type="submit" class="icon-btn icon-btn-compact" title="ออกจากระบบ">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </form>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <header class="topbar">
        {{-- ปุ่มเดียวกันทำหน้าที่ย่อ/ขยาย Sidebar บนเดสก์ท็อป และเปิด/ปิด off-canvas บนจอเล็ก --}}
        <button type="button" class="mobile-menu-btn" aria-label="ย่อหรือขยายเมนู" title="ย่อหรือขยายเมนู" aria-controls="appSidebar" aria-expanded="false" data-sidebar-open>
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
                            <div class="p-2 mb-2 notification-item d-flex gap-2 align-items-start {{ $notice->read_at ? '' : 'is-new' }}" data-dropdown-notification-id="{{ $notice->id }}">
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

            const remember = (collapsed) => {
                try {
                    localStorage.setItem('smartgoal.sidebar.collapsed', collapsed ? '1' : '0');
                } catch (error) {
                    /* ถ้าเขียน localStorage ไม่ได้ ให้จำสถานะเฉพาะหน้านี้ */
                }
            };

            // โหมดย่อซ่อนข้อความเมนู จึงต้องให้ tooltip ของเบราว์เซอร์อธิบายไอคอนแทน
            // (ชื่อเมนูยังอยู่ใน DOM แบบซ่อนสายตา screen reader จึงอ่านได้ตามเดิม)
            const syncState = () => {
                const collapsed = isDesktop() && body.classList.contains('sidebar-collapsed');

                navLinks.forEach((link) => {
                    const label = link.querySelector('.nav-item__label')?.textContent.trim();
                    if (! label) return;

                    if (collapsed) {
                        link.setAttribute('title', label);
                    } else {
                        link.removeAttribute('title');
                    }
                });

                openButton?.setAttribute(
                    'aria-expanded',
                    isDesktop()
                        ? String(! collapsed)
                        : String(body.classList.contains('sidebar-open')),
                );
            };

            const closeSidebar = () => {
                if (isDesktop()) {
                    body.classList.add('sidebar-collapsed');
                    remember(true);
                } else {
                    body.classList.remove('sidebar-open');
                }
                syncState();
            };

            openButton?.addEventListener('click', () => {
                if (isDesktop()) {
                    remember(body.classList.toggle('sidebar-collapsed'));
                } else {
                    body.classList.toggle('sidebar-open');
                }

                syncState();
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
                syncState();
            });

            syncState();
        })();
    </script>
    @stack('scripts')
</body>

</html>
