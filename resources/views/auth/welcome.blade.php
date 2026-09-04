@php
    $user = auth()->user();
    $isAdmin = $user?->role === 'admin';
    $roleLabel = \App\Support\RoleLabel::for($user);
    $department = $user?->department?->department_name ?? 'ยังไม่ได้ระบุแผนก';
    $nextRoute = $isAdmin ? route('board.index') : route('mytasks.index');
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ยินดีต้อนรับ | Smart Goal By PremiumCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    @vite(['resources/css/pages/auth-welcome.css', 'resources/js/pages/auth/experience.js'])
</head>
<body class="auth-welcome">
    @include('auth.partials.backdrop')

    <main class="auth-layout">
        @include('auth.partials.brand', [
            'heading' => "Smart Goal\nBy PremiumCare",
            'tagline' => 'พื้นที่ทำงานของคุณพร้อมแล้ว เริ่มจากงานที่ได้รับมอบหมายวันนี้',
        ])

        <div class="auth-stage auth-rise" data-auth-rise data-width="wide">
            <section class="auth-card auth-card__enter" data-auth-card>
                <div class="card-pill card-pill--good"><span><i class="bi bi-check-lg" aria-hidden="true"></i> ยินดีต้อนรับ</span></div>
                <div class="card-head">
                    <h2>ยินดีต้อนรับเข้าสู่พื้นที่จัดการงานสำหรับทีม</h2>
                    <p>คุณ <b>{{ $user->name }}</b> พร้อมเริ่มใช้งานแล้ว ตรวจสอบข้อมูลบัญชีของคุณ และคำแนะนำสั้น ๆ ก่อนเข้าสู่ระบบ</p>
                </div>

                <div class="welcome-facts">
                    <div class="welcome-fact">
                        <div class="welcome-fact__label"><i class="bi bi-person-badge" aria-hidden="true"></i> ชื่อผู้ใช้</div>
                        <div class="welcome-fact__value">{{ $user->name }}</div>
                    </div>
                    <div class="welcome-fact">
                        <div class="welcome-fact__label"><i class="bi bi-building" aria-hidden="true"></i> แผนก</div>
                        <div class="welcome-fact__value">{{ $department }}</div>
                    </div>
                    <div class="welcome-fact">
                        <div class="welcome-fact__label"><i class="bi bi-shield-check" aria-hidden="true"></i> สิทธิ์การใช้งาน</div>
                        <div class="welcome-fact__value">{{ $roleLabel }}</div>
                    </div>
                </div>

                <div class="welcome-tiles">
                    <div class="welcome-tile">
                        <div class="welcome-tile__badge"><i class="bi bi-list-check" aria-hidden="true"></i></div>
                        <h3>ดูงานที่ได้รับมอบหมาย</h3>
                        <p>ตรวจสอบงาน รายละเอียด ผู้ร่วมงาน และกำหนดส่งงานจากหน้าหลักของคุณ</p>
                    </div>
                    <div class="welcome-tile">
                        <div class="welcome-tile__badge"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></div>
                        <h3>อัปเดตสถานะงาน</h3>
                        <p>เปลี่ยนสถานะเมื่อเริ่มทำงาน ส่งตรวจ หรือทำงานเสร็จ เพื่อให้ทีมเห็นภาพรวมตรงกัน</p>
                    </div>
                    <div class="welcome-tile">
                        <div class="welcome-tile__badge"><i class="bi bi-people" aria-hidden="true"></i></div>
                        <h3>ทำงานร่วมกับทีม</h3>
                        <p>ระบบแสดงผู้รับผิดชอบและทีมที่เกี่ยวข้องในแต่ละงาน ช่วยให้ติดตามงานได้ง่ายขึ้น</p>
                    </div>
                </div>

                <div class="welcome-actions">
                    <form method="POST" action="{{ route('logout') }}" data-auth-form>
                        @csrf
                        <button type="submit" class="btn btn--ghost" data-auth-submit data-loading-label="กำลังออกจากระบบ"><span><i class="bi bi-box-arrow-right" aria-hidden="true"></i> ออกจากระบบ</span></button>
                    </form>
                    <a href="{{ $nextRoute }}" class="btn"><span>เข้าสู่ระบบ <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i></span></a>
                </div>
            </section>
        </div>
    </main>

    @include('auth.partials.toast')
</body>
</html>
