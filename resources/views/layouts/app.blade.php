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

    <style>
        :root {
            /* ---- Light, executive-friendly palette (Premium Care blue) ---- */
            --bg: #F5F7FC;
            --surface: #FFFFFF;
            --surface-2: #EEF2FA;
            --border: #DDE4F2;
            --text: #16233D;
            --text-muted: #64748B;

            --accent: #1E4FD6;
            --accent-dim: #E7EDFC;
            --accent-strong: #16389E;

            --brand-red: #E8352B;
            --brand-red-dim: #FDEAE9;

            --green: #00C875;
            --green-dim: #E3FAEF;
            --amber: #FDAB3D;
            --amber-dim: #FFF2DF;
            --red: #E2445C;
            --red-dim: #FCE9EC;
            --blue: #579BFC;
            --blue-dim: #EAF3FF;
            --gray: #9699A6;
            --gray-dim: #F0F1F5;

            --radius: 12px;
            --radius-sm: 8px;
            --sidebar-w: 252px;
            --shadow-sm: 0 1px 2px rgba(22, 35, 61, .05), 0 1px 6px rgba(22, 35, 61, .05);
            --shadow-md: 0 4px 16px rgba(22, 35, 61, .10);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: 'Sarabun', sans-serif;
            font-size: .92rem;
            -webkit-font-smoothing: antialiased;
        }

        .mono {
            font-family: 'JetBrains Mono', monospace;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* ---------- Sidebar ---------- */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: var(--sidebar-w);
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            z-index: 40;
            transition: transform .22s ease;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: 1.25rem 1.25rem 1rem;
            border-bottom: 1px solid var(--border);
        }

        .sidebar-close,
        .sidebar-backdrop {
            display: none;
        }

        .mobile-menu-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            font-size: 1.15rem;
            flex: 0 0 auto;
        }

        .mobile-menu-btn:hover {
            background: var(--surface-2);
        }

        .brand-logo {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            object-fit: contain;
            background: #fff;
            border: 1px solid var(--border);
            padding: 4px;
            flex: 0 0 auto;
        }

        .sidebar-brand .name {
            font-weight: 700;
            font-size: .95rem;
            letter-spacing: .2px;
            color: var(--text);
            line-height: 1.2;
        }

        .sidebar-brand .sub {
            font-size: .66rem;
            color: var(--text-muted);
            font-family: 'JetBrains Mono', monospace;
            letter-spacing: .6px;
        }

        .nav-section-label {
            font-size: .66rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            padding: 1.1rem 1.25rem .4rem;
            font-weight: 600;
        }

        .sidebar-nav {
            padding: .25rem .75rem;
            flex: 1;
            overflow-y: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .6rem .75rem;
            margin-bottom: .15rem;
            border-radius: 8px;
            color: var(--text-muted);
            font-size: .88rem;
            font-weight: 500;
            border-left: 3px solid transparent;
            transition: background .15s ease, color .15s ease;
        }

        .nav-item i {
            font-size: 1.02rem;
            width: 20px;
            text-align: center;
        }

        .nav-item:hover {
            background: var(--surface-2);
            color: var(--text);
        }

        .nav-item.active {
            background: var(--accent-dim);
            color: var(--accent-strong);
            border-left: 3px solid var(--accent);
            font-weight: 600;
        }

        .nav-item .soon {
            margin-left: auto;
            font-size: .6rem;
            font-family: 'JetBrains Mono', monospace;
            background: var(--surface-2);
            color: var(--text-muted);
            padding: .1rem .4rem;
            border-radius: 20px;
            border: 1px solid var(--border);
        }

        .sidebar-foot {
            border-top: 1px solid var(--border);
            padding: .9rem 1.1rem;
            display: flex;
            align-items: center;
            gap: .6rem;
        }

        .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--accent-dim);
            color: var(--accent-strong);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .78rem;
            flex-shrink: 0;
            overflow: hidden;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .sidebar-foot .who {
            font-size: .82rem;
            font-weight: 600;
            color: var(--text);
        }

        .sidebar-foot .role {
            font-size: .7rem;
            color: var(--text-muted);
        }

        /* ---------- Topbar ---------- */
        .topbar {
            margin-left: var(--sidebar-w);
            height: 64px;
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0 1.75rem;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 255, 255, .9);
            backdrop-filter: blur(6px);
            position: sticky;
            top: 0;
            z-index: 30;
            transition: margin-left .22s ease;
        }

        .topbar .search {
            flex: 1;
            max-width: 380px;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: .45rem .8rem;
            display: flex;
            align-items: center;
            gap: .5rem;
            color: var(--text-muted);
            font-size: .85rem;
        }

        .topbar .search input {
            background: transparent;
            border: 0;
            outline: 0;
            color: var(--text);
            width: 100%;
            font-family: 'Sarabun', sans-serif;
            font-size: .85rem;
        }

        .topbar .search input::placeholder {
            color: var(--text-muted);
        }

        .icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-muted);
            position: relative;
        }

        .icon-btn:hover {
            background: var(--surface-2);
            color: var(--text);
        }

        .icon-btn .ping {
            position: absolute;
            top: 6px;
            right: 7px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--red);
            box-shadow: 0 0 0 2px var(--surface);
        }

        .icon-btn .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--red);
            color: #fff;
            border: 2px solid var(--surface);
            font-size: .68rem;
            line-height: 1;
            font-weight: 850;
        }

        /* ---------- Main ---------- */
        .main {
            margin-left: var(--sidebar-w);
            padding: 1.75rem;
            transition: margin-left .22s ease;
        }

        body.sidebar-collapsed .sidebar {
            transform: translateX(-105%);
        }

        body.sidebar-collapsed .topbar,
        body.sidebar-collapsed .main {
            margin-left: 0;
        }

        .page-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 1.4rem;
            flex-wrap: wrap;
            gap: .75rem;
        }

        .page-head h1 {
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0;
            color: var(--text);
        }

        .page-head .eyebrow {
            font-family: 'JetBrains Mono', monospace;
            font-size: .7rem;
            color: var(--accent);
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: .3rem;
            display: block;
            font-weight: 600;
        }

        .page-head p {
            color: var(--text-muted);
            margin: .25rem 0 0;
            font-size: .85rem;
        }

        /* ---------- Shared components ---------- */
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
        }

        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .panel-head h2 {
            font-size: .95rem;
            font-weight: 700;
            margin: 0;
            color: var(--text);
        }

        .panel-head .meta {
            font-size: .78rem;
            color: var(--accent);
            font-weight: 600;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.1rem 1.25rem;
            box-shadow: var(--shadow-sm);
        }

        .stat-card .label {
            font-size: .78rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .stat-card .value {
            font-size: 1.75rem;
            font-weight: 700;
            font-family: 'JetBrains Mono', monospace;
            margin-top: .2rem;
            color: var(--text);
        }

        .stat-card .delta {
            font-size: .74rem;
            margin-top: .35rem;
            display: inline-flex;
            align-items: center;
            gap: .25rem;
        }

        .status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            flex-shrink: 0;
            display: inline-block;
        }

        .status-dot.working {
            background: var(--green);
            animation: pulse-green 2s infinite;
        }

        .status-dot.meeting {
            background: var(--blue);
        }

        .status-dot.idle {
            background: var(--amber);
        }

        .status-dot.leave {
            background: var(--gray);
        }

        @keyframes pulse-green {
            0% {
                box-shadow: 0 0 0 0 rgba(0, 200, 117, .45);
            }

            70% {
                box-shadow: 0 0 0 6px rgba(0, 200, 117, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(0, 200, 117, 0);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .status-dot.working {
                animation: none;
            }
        }

        .badge-soft {
            font-size: .7rem;
            font-weight: 700;
            padding: .3rem .6rem;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            white-space: nowrap;
        }

        .badge-soft.green {
            background: var(--green-dim);
            color: #00875A;
        }

        .badge-soft.amber {
            background: var(--amber-dim);
            color: #B4690E;
        }

        .badge-soft.red {
            background: var(--red-dim);
            color: #C8264B;
        }

        .badge-soft.blue {
            background: var(--blue-dim);
            color: #1A66D6;
        }

        .badge-soft.gray {
            background: var(--gray-dim);
            color: #5C616E;
        }

        .badge-soft.accent {
            background: var(--accent-dim);
            color: var(--accent-strong);
        }

        .role-chip {
            height: 36px;
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            border-radius: 999px;
            padding: 0 .85rem;
            font-size: .78rem;
            font-weight: 700;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            white-space: nowrap;
        }

        .role-chip.admin {
            background: var(--accent-dim);
            color: var(--accent-strong);
            border-color: #D8D2FA;
        }

        .role-chip.user {
            background: var(--green-dim);
            color: #00875A;
            border-color: #B7EBD0;
        }

        .notification-menu {
            width: 360px;
            max-height: 360px;
            overflow: auto;
            border-radius: 14px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
        }

        .notification-item {
            border: 1px solid var(--border);
            border-radius: 12px;
            color: inherit;
            text-decoration: none;
        }

        .notification-item.is-new {
            border-color: #C7D2FE;
            background: #F5F3FF;
        }

        .notification-body {
            flex: 1;
            min-width: 0;
            color: inherit;
            text-decoration: none;
        }

        .notification-new {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: .12rem .45rem;
            background: var(--red);
            color: #fff;
            font-size: .68rem;
            font-weight: 850;
            margin-left: .35rem;
        }

        .notification-delete {
            border: 0;
            background: transparent;
            color: var(--text-muted);
            width: 28px;
            height: 28px;
            border-radius: 8px;
        }

        .notification-delete:hover {
            background: var(--red-dim);
            color: #C8264B;
        }

        .notification-more {
            background: var(--surface-2);
            color: var(--text-muted);
            border-radius: 10px;
            padding: .55rem .75rem;
            font-size: .78rem;
            font-weight: 700;
            text-align: center;
        }

        .table-clean {
            width: 100%;
            border-collapse: collapse;
        }

        .table-clean th {
            text-align: left;
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--text-muted);
            font-weight: 700;
            padding: .65rem .6rem;
            border-bottom: 1px solid var(--border);
        }

        .table-clean td {
            padding: .75rem .6rem;
            border-bottom: 1px solid var(--border);
            font-size: .86rem;
            vertical-align: middle;
        }

        .table-clean tr:last-child td {
            border-bottom: none;
        }

        .table-clean tr.row-link {
            position: relative;
        }

        .table-clean tr.row-link:hover {
            background: var(--surface-2);
            cursor: pointer;
        }

        .table-clean tr.row-link td:first-child {
            border-left: 3px solid transparent;
        }

        .table-clean tr.row-link.s-working td:first-child {
            border-left-color: var(--green);
        }

        .table-clean tr.row-link.s-meeting td:first-child {
            border-left-color: var(--blue);
        }

        .table-clean tr.row-link.s-idle td:first-child {
            border-left-color: var(--amber);
        }

        .table-clean tr.row-link.s-leave td:first-child {
            border-left-color: var(--gray);
        }

        .ticket-id {
            font-family: 'JetBrains Mono', monospace;
            font-size: .74rem;
            color: var(--accent-strong);
            font-weight: 600;
        }

        .btn-accent {
            background: var(--accent);
            border: 1px solid var(--accent);
            color: #fff;
            font-weight: 600;
            font-size: .85rem;
            border-radius: 8px;
            padding: .55rem 1.1rem;
            box-shadow: 0 2px 8px rgba(91, 71, 224, .25);
        }

        .btn-accent:hover {
            background: var(--accent-strong);
            border-color: var(--accent-strong);
            color: #fff;
        }

        .btn-outline-line {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
            font-size: .85rem;
            border-radius: 8px;
            padding: .55rem 1rem;
        }

        .btn-outline-line:hover {
            background: var(--surface-2);
            color: var(--text);
        }

        .chip {
            font-size: .74rem;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: .32rem .75rem;
        }

        .chip.active {
            background: var(--accent-dim);
            border-color: var(--accent);
            color: var(--accent-strong);
        }

        .search {}

        .search i {
            color: var(--text-muted);
        }

        .search input {
            border: 0;
            outline: 0;
            background: transparent;
            width: 100%;
            font-family: 'Sarabun', sans-serif;
            font-size: .85rem;
            color: var(--text);
        }

        .search input::placeholder {
            color: var(--text-muted);
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 8px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #CBD0E0;
        }

        @media (max-width: 991px) {
            :root { --sidebar-w: 0px; }

            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: min(84vw, 310px);
                min-height: 100dvh;
                height: 100dvh;
                transform: translateX(-105%);
                border-right: 1px solid var(--border);
                border-bottom: 0;
                box-shadow: 20px 0 45px rgba(26, 31, 54, .16);
                transition: transform .22s ease;
                z-index: 1050;
            }

            .topbar,
            .main {
                margin-left: 0;
            }

            .topbar {
                height: 58px;
                padding: 0 .85rem;
                gap: .65rem;
            }

            .sidebar-nav {
                display: block;
                overflow-y: auto;
                padding: .75rem;
            }

            .nav-section-label,
            .sidebar-foot {
                display: flex;
            }

            .nav-item {
                margin-bottom: .25rem;
                white-space: normal;
                min-height: 42px;
            }

            .sidebar-brand {
                padding: .85rem .85rem .85rem 1rem;
                justify-content: space-between;
            }

            .sidebar-brand > div {
                min-width: 0;
            }

            .sidebar-close,
            .mobile-menu-btn {
                display: flex;
            }

            .sidebar-close {
                width: 36px;
                height: 36px;
                border-radius: 10px;
                align-items: center;
                justify-content: center;
                border: 1px solid var(--border);
                background: var(--surface);
                color: var(--text-muted);
                flex: 0 0 auto;
            }

            .mobile-menu-btn {
                width: 40px;
                height: 40px;
                border-radius: 10px;
                align-items: center;
                justify-content: center;
                border: 1px solid var(--border);
                background: var(--surface);
                color: var(--text);
                font-size: 1.15rem;
                flex: 0 0 auto;
            }

            .sidebar-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, .34);
                z-index: 1040;
                opacity: 0;
                pointer-events: none;
                transition: opacity .2s ease;
            }

            body.sidebar-open {
                overflow: hidden;
            }

            body.sidebar-open .sidebar {
                transform: translateX(0);
            }

            body.sidebar-open .sidebar-backdrop {
                display: block;
                opacity: 1;
                pointer-events: auto;
            }

            .topbar .search {
                max-width: none;
                min-width: 0;
                flex: 1;
            }

            .role-chip {
                max-width: 44px;
                padding: 0 .65rem;
                overflow: hidden;
            }

            .role-chip i {
                margin-right: .2rem;
            }
        }
    </style>

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
                <button type="submit" class="icon-btn" title="ออกจากระบบ" style="width:32px;height:32px;">
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
                        <div class="px-2 pb-1 text-muted" style="font-size:.72rem;font-weight:700;">คำขอเปิดงานรออนุมัติ</div>
                        @foreach($pendingApprovalJobs as $pendingJob)
                            <div class="p-2 mb-2 notification-item">
                                <div style="font-weight:700;font-size:.86rem;">{{ $pendingJob->job_topic }}</div>
                                <div style="font-size:.75rem;color:var(--text-muted);margin:.15rem 0 .55rem;">
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
                        <div class="px-2 pb-1 text-muted" style="font-size:.72rem;font-weight:700;">คำขอลบงาน</div>
                        @foreach($pendingDeleteJobs as $deleteJob)
                            <div class="p-2 mb-2 notification-item">
                                <div style="font-weight:700;font-size:.86rem;">{{ $deleteJob->job_topic }}</div>
                                <div style="font-size:.75rem;color:var(--text-muted);margin:.15rem 0 .55rem;">
                                    ผู้ขอ: {{ optional($deleteJob->deleteRequester)->name ?? '-' }}
                                </div>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('tasks.show', $deleteJob->job_id) }}" class="btn btn-sm btn-outline-secondary">ดู</a>
                                    <form method="POST" action="{{ route('admin.tasks.deleteRequest.approve', $deleteJob->job_id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">อนุมัติลบ</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    @endif

                    @if($systemNotifications->count() > 0)
                        <div class="px-2 pb-1 text-muted" style="font-size:.72rem;font-weight:700;">การเปลี่ยนแปลงงาน</div>
                        @foreach($systemNotifications as $notice)
                            <div class="p-2 mb-2 notification-item d-flex gap-2 align-items-start {{ $notice->read_at ? '' : 'is-new' }}">
                                <a href="{{ $notice->work_order_id ? route('tasks.show', $notice->work_order_id) : '#' }}" class="notification-body">
                                    <div style="font-weight:700;font-size:.86rem;">
                                        {{ $notice->title }}
                                        @if(! $notice->read_at)
                                            <span class="notification-new">ใหม่</span>
                                        @endif
                                    </div>
                                    <div style="font-size:.75rem;color:var(--text-muted);margin-top:.15rem;">
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
                        <div class="px-2 pb-1 text-muted" style="font-size:.72rem;font-weight:700;">คำเชิญร่วมงาน</div>
                        @foreach($pendingInvitations as $inviteJob)
                            <div class="p-2 mb-2 notification-item">
                                <div style="font-weight:700;font-size:.86rem;">{{ $inviteJob->job_topic }}</div>
                                <div style="font-size:.75rem;color:var(--text-muted);margin:.15rem 0 .55rem;">
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
                        <div class="px-2 pb-1 text-muted" style="font-size:.72rem;font-weight:700;">งานใกล้ครบกำหนด</div>
                        @foreach($dueSoonJobs as $dueJob)
                            <a href="{{ route('tasks.show', $dueJob->job_id) }}" class="d-block p-2 mb-2 notification-item">
                                <div style="font-weight:700;font-size:.86rem;">{{ $dueJob->job_topic }}</div>
                                <div style="font-size:.75rem;color:var(--text-muted);margin-top:.15rem;">
                                    กำหนดส่ง {{ $dueJob->job_due_at->locale('th')->isoFormat('D MMM YYYY HH:mm') }}
                                </div>
                            </a>
                        @endforeach
                    @endif

                    @if($decisionJobs->count() > 0)
                        <div class="px-2 pb-1 text-muted" style="font-size:.72rem;font-weight:700;">ผลการอนุมัติงาน</div>
                        @foreach($decisionJobs as $decisionJob)
                            <a href="{{ route('tasks.show', $decisionJob->job_id) }}" class="d-block p-2 mb-2 notification-item">
                                <div style="font-weight:700;font-size:.86rem;">{{ $decisionJob->job_topic }}</div>
                                <div style="font-size:.75rem;color:{{ $decisionJob->approval_status === 'approved' ? '#00875A' : '#C8264B' }};margin-top:.15rem;">
                                    {{ $decisionJob->approval_status === 'approved' ? 'อนุมัติแล้ว' : 'ถูกปฏิเสธ' }}
                                </div>
                            </a>
                        @endforeach
                    @endif

                    @if($notificationCount === 0)
                        <div class="text-center text-muted py-4" style="font-size:.85rem;">ไม่มีคำขอเปิดงานที่รออนุมัติ</div>
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