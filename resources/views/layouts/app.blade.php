<!DOCTYPE html>
<html lang="th">

<head>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ระบบงาน') | Smart Goal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    @vite('resources/css/components/layout.css')

    @stack('styles')
</head>

<body
    data-realtime-sync-url="{{ route('realtime.sync') }}"
    data-realtime-cursor="{{ app(\App\Services\NotificationService::class)->latestId(auth()->user()) }}">
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
        $isDepartmentHead = $currentUser?->isDepartmentHead() ?? false;
        // ชื่อบทบาทมาจาก Support ตัวเดียวเสมอ เพื่อไม่ให้หน้าไหนลืมธง is_department_head
        $roleLabel = \App\Support\RoleLabel::for($currentUser);
        $notificationService = app(\App\Services\NotificationService::class);
        $systemNotifications = $notificationService->dropdown($currentUser);
        $notificationCount = $notificationService->unreadCount($currentUser);
        $notificationDisplayCount = $notificationService->displayCount($notificationCount);
    @endphp

    <aside class="sidebar" id="appSidebar">
        <div class="sidebar-brand">
            {{-- โลโก้บริษัทอยู่ใน public/images จึงเสิร์ฟตรงได้ ไม่ต้องผ่าน MediaController ที่มีไว้สำหรับไฟล์ส่วนตัว --}}
            <span class="brand-mark" aria-hidden="true"><img src="{{ asset('images/premiuum-care-logo.png') }}" alt=""></span>
            <div class="brand-text">
                <div class="brand-name">Smart Goals</div>
                <div class="brand-subtitle">ระบบจัดการองค์กร</div>
            </div>
            <button type="button" class="sidebar-close" aria-label="ปิดเมนู" data-sidebar-close>
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="sidebar-nav">
            @if ($isAdmin)
                <div class="nav-section-label">ภาพรวม</div>

                {{-- เมนู "การประชุม" อยู่ใน Admin Member Workspace เพื่อให้ดูในบริบทของสมาชิก --}}
                <a href="{{ route('board.index') }}" class="nav-item {{ request()->routeIs('board.*') ? 'active' : '' }}">
                    <i class="bi bi-kanban"></i>
                    <span class="nav-item__label">บอร์ดรวม</span>
                </a>
                <a href="{{ route('reports.index') }}" class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <i class="bi bi-bar-chart-line"></i>
                    <span class="nav-item__label">รายงาน</span>
                </a>
            @elseif ($isViewer)
                <div class="nav-section-label">ภาพรวม</div>

                <a href="{{ route('board.index') }}" class="nav-item {{ request()->routeIs('board.*') ? 'active' : '' }}">
                    <i class="bi bi-grid-1x2"></i>
                    <span class="nav-item__label">แดชบอร์ด</span>
                </a>
                <a href="{{ route('reports.index') }}" class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <i class="bi bi-bar-chart-line"></i>
                    <span class="nav-item__label">รายงาน</span>
                </a>
            @else
                {{-- พนักงาน: "งานของฉัน" เป็นศูนย์กลางงานและการประชุม --}}
                <div class="nav-section-label">งานของฉัน</div>

                <a href="{{ route('mytasks.index') }}"
                    class="nav-item {{ request()->routeIs('mytasks.*') ? 'active' : '' }}">
                    <i class="bi bi-briefcase"></i>
                    <span class="nav-item__label">งานของฉัน</span>
                </a>
                <a href="{{ $isDepartmentHead ? route('work-board.department', $currentUser->department_id) : route('work-board.index') }}"
                    class="nav-item {{ request()->routeIs('work-board.*') ? 'active' : '' }}">
                    <i class="bi bi-kanban"></i>
                    <span class="nav-item__label">บอร์ดงาน</span>
                </a>
                {{--
                    หัวหน้าแผนกต้องเข้าหน้าเลือกประเภทรายงานก่อน (ภาพรวม / รายบุคคล)
                    เดิมชี้ตรงไป reports.organization ทำให้ข้ามหน้าเลือกไปเลย
                    และเมนูไม่ขึ้น active เมื่ออยู่หน้ารายงานอื่นในกลุ่มเดียวกัน
                --}}
                <a href="{{ $isDepartmentHead ? route('reports.index') : route('reports.my') }}"
                    class="nav-item {{ request()->routeIs($isDepartmentHead ? 'reports.*' : 'reports.my') ? 'active' : '' }}">
                    <i class="bi bi-clipboard-data"></i>
                    <span class="nav-item__label">{{ $isDepartmentHead ? 'รายงานแผนก' : 'รายงานของฉัน' }}</span>
                </a>
            @endif

            @stack('sidebar_nav_extra')

            <div class="nav-section-label">{{ $isAdmin || $isDepartmentHead ? 'งานและคำขอ' : 'การสื่อสาร' }}</div>

            <a href="{{ route('notifications.index') }}"
                class="nav-item {{ request()->routeIs('notifications.*') ? 'active' : '' }}">
                <i class="bi bi-bell"></i>
                <span class="nav-item__label">การแจ้งเตือน</span>
                <span class="nav-item__count" data-notification-count data-sidebar-notification-count{{ $notificationCount === 0 ? ' hidden' : '' }}>{{ $notificationDisplayCount }}</span>
            </a>

            @if ($isAdmin || $isDepartmentHead)
                <a href="{{ route('admin.approvals.index') }}"
                    class="nav-item {{ request()->routeIs('admin.approvals.*') ? 'active' : '' }}">
                    <i class="bi bi-shield-check"></i>
                    <span class="nav-item__label">คำขออนุมัติ</span>
                    @if($approvalCounts['total'] > 0)
                        <span class="nav-item__count" data-approval-count>{{ $approvalCounts['total'] }}</span>
                    @endif
                </a>
            @endif
            @if ($isViewer)
                <a href="{{ route('meetings.index') }}" class="nav-item {{ request()->routeIs('meetings.*') ? 'active' : '' }}">
                    <i class="bi bi-calendar-event"></i>
                    <span class="nav-item__label">การประชุม</span>
                </a>
            @endif

            @if ($isAdmin || $isViewer)
                <div class="nav-section-label">องค์กร</div>

                <a href="{{ route('employees.index') }}"
                    class="nav-item {{ request()->routeIs('employees.*') ? 'active' : '' }}">
                    <i class="bi bi-people"></i>
                    <span class="nav-item__label">พนักงาน</span>
                </a>
                @if ($isAdmin)
                    <a href="{{ route('admin.departments.index') }}"
                        class="nav-item {{ request()->routeIs('admin.departments.*') ? 'active' : '' }}">
                        <i class="bi bi-diagram-3"></i>
                        <span class="nav-item__label">จัดการแผนก</span>
                    </a>
                    <a href="{{ route('admin.accounts.index') }}"
                        class="nav-item {{ request()->routeIs('admin.accounts.*') ? 'active' : '' }}">
                        <i class="bi bi-person-gear"></i>
                        <span class="nav-item__label">บัญชีระบบ</span>
                    </a>
                @endif
            @endif

            <div class="nav-section-label">ระบบ</div>

            @if ($isAdmin)
                {{-- บันทึกระบบกับถังขยะรวมเป็นเมนูเดียว เพราะทั้งคู่ตอบคำถามเดียวกันว่าใครทำอะไรกับข้อมูล --}}
                <a href="{{ route('admin.audit.index') }}"
                    class="nav-item {{ request()->routeIs('admin.audit.*') ? 'active' : '' }}">
                    <i class="bi bi-shield-lock"></i>
                    <span class="nav-item__label">Audit Log</span>
                </a>
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

            {{--
                ปุ่มออกจากระบบอยู่ที่ Topbar ถัดจากไอคอนแจ้งเตือน ไม่ใช่ที่นี่
                ท้าย Sidebar เหลือไว้เพื่อบอกว่า "กำลังใช้งานในนามใคร" อย่างเดียว
                และเมื่อ Sidebar ถูกย่อหรือปิดบนจอเล็ก ปุ่มออกจากระบบต้องยังกดได้อยู่
            --}}
        </div>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <header class="topbar">
        {{-- ปุ่มเดียวกันทำหน้าที่ย่อ/ขยาย Sidebar บนเดสก์ท็อป และเปิด/ปิด off-canvas บนจอเล็ก --}}
        <button type="button" class="mobile-menu-btn" aria-label="ย่อหรือขยายเมนู" title="ย่อหรือขยายเมนู" aria-controls="appSidebar" aria-expanded="false" data-sidebar-open>
            <i class="bi bi-list"></i>
        </button>
        <div class="ms-auto d-flex align-items-center gap-2">
            @php
                // ป้ายบทบาทมุมขวาบนเคยมีแค่สองสี (admin เป็นม่วง ที่เหลือเขียวหมด)
                // หัวหน้าแผนกกับพนักงานจึงหน้าตาเหมือนกัน และไม่บอกด้วยว่าอยู่แผนกไหน
                // ตอนนี้ใช้ชุดสีและคำเดียวกับ .employee-role บนการ์ดหน้าจัดการพนักงาน
                // เพื่อให้บทบาทเดียวกันอ่านได้เหมือนกันทุกหน้า
                $roleChipClass = match (true) {
                    $isAdmin => 'admin',
                    $isDepartmentHead => 'department-head',
                    $isViewer => 'viewer',
                    default => 'user',
                };
                $roleChipIcon = match ($roleChipClass) {
                    'admin' => 'bi-shield-check',
                    'department-head' => 'bi-person-badge',
                    'viewer' => 'bi-eye',
                    default => 'bi-person-check',
                };
                // admin และ viewer ไม่ผูกกับแผนก (UserController บังคับ department_id เป็น null)
                $roleChipDepartment = $isAdmin || $isViewer
                    ? null
                    : optional($currentUser?->department)->department_name;
                $roleChipText = $roleLabel.($roleChipDepartment ? ' '.$roleChipDepartment : '');
            @endphp
            {{--
                ป้ายบทบาทซ้ำกับท้าย Sidebar ซึ่งบอกทั้งชื่อ บทบาท และแผนกอยู่แล้วบนเดสก์ท็อป
                จึงแสดงเฉพาะจอเล็กที่ Sidebar ถูกยุบเป็น off-canvas และมองไม่เห็นท้ายเมนู
            --}}
            <span class="role-chip role-chip--mobile-only {{ $roleChipClass }}" aria-label="{{ $roleChipText }}" title="{{ $roleChipText }}">
                <i class="bi {{ $roleChipIcon }}"></i>
                <span class="role-chip__label">{{ $roleChipText }}</span>
            </span>
            <div class="dropdown">
                <button class="icon-btn" data-bs-toggle="dropdown" aria-expanded="false" title="แจ้งเตือน">
                    <i class="bi bi-bell-fill"></i>
                    <span class="notification-count" data-notification-count data-bell-notification-count{{ $notificationCount === 0 ? ' hidden' : '' }}>{{ $notificationDisplayCount }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end p-2 notification-menu">
                    <div class="d-flex align-items-center justify-content-between px-2 py-2">
                        <strong>การแจ้งเตือน</strong>
                        <span class="badge-soft {{ $notificationCount > 0 ? 'amber' : 'gray' }}" data-notification-summary>{{ $notificationCount }} รายการ</span>
                    </div>
                    <div data-notification-dropdown-list>
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
                    </div>

                    @if($systemNotifications->isEmpty())
                        <div class="text-center text-muted py-4 notification-dropdown-empty" data-notification-dropdown-empty>ไม่มีการแจ้งเตือน</div>
                    @elseif($systemNotifications->count() === 15)
                        <div class="notification-more">แสดงการแจ้งเตือนล่าสุด 15 รายการ</div>
                    @endif
                    <a href="{{ route('notifications.index') }}" class="notification-more d-block text-center">ดูการแจ้งเตือนทั้งหมด</a>
                </div>
            </div>

            {{-- ออกจากระบบเป็น mutation จึงต้องเป็น POST ที่มี CSRF ไม่ใช่ลิงก์ --}}
            <form method="POST" action="{{ route('logout') }}" class="topbar-logout">
                @csrf
                <button type="submit" class="icon-btn topbar-logout__button" title="ออกจากระบบ" aria-label="ออกจากระบบ">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    <span class="topbar-logout__label">ออกจากระบบ</span>
                </button>
            </form>
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
    @vite('resources/js/components/realtime-sync.js')
    @stack('scripts')
</body>

</html>
