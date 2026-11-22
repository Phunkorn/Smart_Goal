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

    @vite(['resources/css/components/layout.css', 'resources/css/pages/settings.css'])

    {{-- สรุปประจำวันมีเฉพาะหน้าแรกของวันที่ยังไม่รับทราบ (AppServiceProvider ส่ง $dailyBrief) --}}
    @if(! empty($dailyBrief))
        @vite('resources/css/components/daily-brief.css')
    @endif

    @stack('styles')
</head>

<body
    data-realtime-sync-url="{{ route('realtime.sync') }}"
    data-realtime-cursor="{{ app(\App\Services\NotificationService::class)->latestId(auth()->user()) }}"
    {{-- agent ที่ฟังเสียง Ticket ใหม่: แท็บที่ถูกซ่อน (อยู่แท็บอื่น/ย่อหน้าต่าง) ยังตรวจทุก 10 วินาที เสียงจึงดังได้ — ผู้ใช้อื่นหยุดตรวจเมื่อซ่อนเหมือนเดิม
         ไฟล์เสียงและเวลาที่เล่นวนต่อ Ticket มาจาก config/tickets.php (alert) --}}
    @if ($ticketAlertEnabled ?? false) data-realtime-background-interval="10000" data-ticket-alert-sound="{{ $ticketAlertSound }}" data-ticket-alert-seconds="{{ (int) config('tickets.alert.loop_seconds', 20) }}" @endif>
    @php
        $currentUser = auth()->user();
        $isViewer = $currentUser?->role === 'viewer';
        // ชื่อบทบาทมาจาก Support ตัวเดียวเสมอ เพื่อไม่ให้หน้าไหนลืมธง is_department_head
        $roleLabel = \App\Support\RoleLabel::for($currentUser);
        $routineAttention = app(\App\Services\RoutineAttentionService::class)->summary($currentUser);
        $notificationService = app(\App\Services\NotificationService::class);
        $systemNotifications = $notificationService->dropdown($currentUser);
        $notificationCount = $notificationService->unreadCount($currentUser);
        $notificationDisplayCount = $notificationService->displayCount($notificationCount);
        // เมนูตามสิทธิ์คำนวณครั้งเดียว แล้วส่งให้ทั้งเมนูแนวนอน (≥ 992px) และแถบเมนูด้านข้าง (< 992px)
        $navigation = \App\Support\TopNavigation::for($currentUser, request(), [
            'ticketsInstalled' => $ticketsInstalled ?? false,
            'ticketQueueCount' => $ticketQueueCount ?? 0,
            'shareRequestCount' => $shareRequestCount ?? 0,
            'approvalTotal' => $approvalCounts['total'] ?? 0,
            'notificationCount' => $notificationCount,
            'notificationDisplay' => $notificationDisplayCount,
        ]);
    @endphp

    {{--
        Topbar: แบรนด์ · เมนูหลัก · เครื่องมือ (ค้นหา งานประจำ แจ้งเตือน บัญชี)
        จอ ≥ 992px ใช้เมนูแนวนอน (layouts/components/top-navigation)
        จอ < 992px ซ่อนเมนูแนวนอน แล้วใช้ปุ่ม ☰ เปิดแถบเมนูด้านข้าง (layouts/components/mobile-sidebar)
        เมนูทั้งสองแบบวาดจาก $navigation ชุดเดียวกัน (App\Support\TopNavigation)
    --}}
    <header class="topbar">
        <button type="button" class="topbar-menu-btn" data-mobile-sidebar-open aria-controls="mobileSidebar"
            aria-expanded="false" aria-label="เปิดเมนู">
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>

        {{-- ชื่อระบบอยู่ที่นี่ที่เดียว --}}
        <div class="topbar-brand">
            <span class="topbar-brand__mark" aria-hidden="true"><i class="bi bi-bullseye"></i></span>
            <span class="topbar-brand__text">
                <span class="topbar-brand__name">Smart Goals</span>
                <span class="topbar-brand__subtitle">ระบบบริหารงานองค์กร</span>
            </span>
        </div>

        @include('layouts.components.top-navigation')

        <div class="topbar-utilities">
            @include('layouts.components.global-search')
            @unless($isViewer)
                <div class="dropdown routine-topbar" data-routine-topbar data-routine-status-url="{{ route('daily-logs.routine-status') }}">
                    <button type="button" class="icon-btn routine-topbar__button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                        title="งานประจำวันนี้" aria-label="งานประจำที่ต้องจัดการ {{ $routineAttention['total'] }} รายการ">
                        <i class="bi bi-alarm" aria-hidden="true"></i>
                        <span class="notification-count routine-topbar__count" data-routine-count @if($routineAttention['total'] === 0) hidden @endif>{{ $routineAttention['total'] }}</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end topbar-slide-menu routine-topbar__menu">
                        <div class="routine-topbar__head">
                            <strong>งานประจำวันนี้</strong>
                            <span data-routine-total>{{ $routineAttention['total'] }} รายการ</span>
                        </div>
                        <div class="routine-topbar__summary">
                            <span><b data-routine-waiting>{{ $routineAttention['waiting'] }}</b> รอเริ่ม</span>
                            <span><b data-routine-running>{{ $routineAttention['running'] }}</b> กำลังทำ</span>
                            <span class="is-overdue"><b data-routine-overdue>{{ $routineAttention['overdue'] }}</b> เกินเวลา</span>
                        </div>
                        @forelse($routineAttention['items'] as $routineLog)
                            @php
                                $routineIsOverdue = $routineLog->planned_end_at && now()->greaterThan($routineLog->planned_end_at);
                            @endphp
                            <a class="routine-topbar__item {{ $routineIsOverdue ? 'is-overdue' : '' }}"
                                href="{{ route('daily-logs.index', ['date' => $routineLog->work_date?->format('Y-m-d')]).'#work-log-'.$routineLog->id }}">
                                <i class="bi {{ $routineIsOverdue ? 'bi-exclamation-circle-fill' : ($routineLog->status === 'in_progress' ? 'bi-play-circle-fill' : 'bi-clock') }}" aria-hidden="true"></i>
                                <span><strong>{{ $routineLog->title }}</strong><small>{{ $routineLog->planned_start_at ? \App\Support\TodayWorkspace::businessNow($routineLog->planned_start_at)->format('H:i') : 'ไม่กำหนดเวลา' }} · {{ $routineLog->status === 'in_progress' ? 'กำลังทำ' : 'รอเริ่ม' }}</small></span>
                            </a>
                        @empty
                            <p class="routine-topbar__empty">ไม่มีงานประจำที่ต้องจัดการ</p>
                        @endforelse
                        <a class="routine-topbar__all" href="{{ route('daily-logs.index') }}">ไปจัดการงานประจำ <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
            @endunless
            <div class="dropdown">
                <button type="button" class="icon-btn" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="แจ้งเตือน" aria-label="การแจ้งเตือน">
                    <i class="bi bi-bell" aria-hidden="true"></i>
                    <span class="notification-count" data-notification-count data-bell-notification-count{{ $notificationCount === 0 ? ' hidden' : '' }}>{{ $notificationDisplayCount }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end topbar-slide-menu notification-menu" data-notification-menu>
                    <div class="notification-menu__head">
                        <strong>การแจ้งเตือน</strong>
                        <span class="badge-soft {{ $notificationCount > 0 ? 'amber' : 'gray' }}" data-notification-summary>{{ $notificationCount }} รายการ</span>
                        {{--
                            ปุ่มไอคอนใช้เส้นทางเดียวกับหน้าศูนย์การแจ้งเตือน (notifications.read-all
                            และ notifications.destroy-read) ไม่ได้สร้าง endpoint ชุดที่สอง
                            ปุ่มลบถามยืนยันด้วย SweetAlert ก่อนเสมอเพราะลบถาวร
                        --}}
                        <span class="notification-menu__actions">
                            <button type="button" class="notification-menu__action" data-notification-read-all
                                title="อ่านทั้งหมด" aria-label="ทำเครื่องหมายว่าอ่านทั้งหมด" @disabled($notificationCount === 0)>
                                <i class="bi bi-check2-all" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="notification-menu__action notification-menu__action--danger" data-notification-clear-read
                                title="ล้างรายการที่อ่านแล้ว" aria-label="ล้างรายการที่อ่านแล้ว">
                                <i class="bi bi-trash3" aria-hidden="true"></i>
                            </button>
                        </span>
                    </div>
                    <div data-notification-dropdown-list>
                    @if($systemNotifications->count() > 0)
                        <div class="px-2 pb-1 text-muted notification-section-title">การเปลี่ยนแปลงงาน</div>
                        @foreach($systemNotifications as $notice)
                            <div class="p-2 mb-2 notification-item notification-item--{{ $notice->category }} d-flex gap-2 align-items-start {{ $notice->read_at ? '' : 'is-new' }}" data-dropdown-notification-id="{{ $notice->id }}">
                                <span class="notification-item__icon" aria-hidden="true">
                                    @include('notifications.components.category-icon', ['category' => $notice->category])
                                </span>
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

            @include('layouts.components.user-dropdown')
        </div>
    </header>

    {{-- อยู่นอก header เพราะ backdrop-filter ของ Topbar จะเป็นที่ยึดของ position: fixed --}}
    @include('layouts.components.mobile-sidebar')

    <main class="main">
        @yield('content')
    </main>

    @include('settings.components.account-modals', ['user' => $currentUser])

    @if(! empty($dailyBrief))
        @include('daily-brief.modal', ['brief' => $dailyBrief])
    @endif

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    {{-- เมนูแนวนอน (คีย์บอร์ด และเลื่อนให้เห็นเมนูปัจจุบันบนจอแคบ) กับช่องค้นหา Ctrl/⌘ + K --}}
    @vite('resources/js/components/top-navigation.js')
    @vite('resources/js/components/mobile-sidebar.js')
    @vite('resources/js/components/global-search.js')
    @vite('resources/js/components/avatar-fallback.js')
    @vite('resources/js/components/realtime-sync.js')
    {{-- เสียงแจ้ง Ticket ใหม่ทุกหน้า — ฟัง event ของ realtime-sync ไม่ยิงคำขอเอง (เฉพาะ agent ที่เปิดรับแจ้งเตือน)
         ปุ่มเปิด/ปิดเสียงอยู่บนหน้ารับแจ้งปัญหา (tickets/index) ค่าที่เลือกใช้กับทุกหน้าและทุกแท็บของเบราว์เซอร์นี้ --}}
    @if ($ticketAlertEnabled ?? false)
        @vite('resources/js/components/ticket-alert.js')
    @endif
    @if(! empty($dailyBrief))
        @vite('resources/js/components/daily-brief.js')
    @endif
    @vite('resources/js/pages/settings/index.js')
    @stack('scripts')
</body>

</html>
