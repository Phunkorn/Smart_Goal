@php
    $user = auth()->user();
    $isAdmin = $user?->role === 'admin';
    $roleLabel = $isAdmin ? 'ผู้ดูแลระบบ' : ($user?->role === 'viewer' ? 'ผู้เข้าชม' : 'พนักงาน');
    $department = $user?->department?->department_name ?? 'ยังไม่ได้ระบุแผนก';
    $nextRoute = $isAdmin ? route('board.index') : route('mytasks.index');
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ยินดีต้อนรับ | Smart Goal By PremiumCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    @vite('resources/css/pages/auth-welcome.css')
</head>
<body>
<main class="welcome-layout">
    <section class="brand-panel">
        <img src="{{ asset('images/premiuum-care-logo.png') }}" alt="PremiumCare" class="brand-logo">
        <div class="brand-copy"><div class="brand-eyebrow">PREMIUMCARE WORKFORCE</div><h1>Smart Goal<br>By PremiumCare</h1></div>
        <p class="brand-description">พื้นที่จัดการงานสำหรับทีม : เช็กงานที่ได้รับมอบหมาย<br>อัปเดตสถานะ และส่งงานตรงเวลา</p>
    </section>
    <section class="welcome-card">
        <div class="hero"><div class="success-pill"><i class="bi bi-check-lg"></i> ยินดีต้อนรับ</div><h2>ยินดีต้อนรับเข้าสู่พื้นที่จัดการงานสำหรับทีม</h2><p>คุณ {{ $user->name }} พร้อมเริ่มใช้งานแล้ว ตรวจสอบข้อมูลบัญชีของคุณ และคำแนะนำสั้นๆ ก่อนเข้าสู่ระบบ</p></div>
        <div class="profile-grid">
            <div class="profile-item"><div class="label"><i class="bi bi-person-badge"></i> ชื่อผู้ใช้</div><div class="value">{{ $user->name }}</div></div>
            <div class="profile-item"><div class="label"><i class="bi bi-building"></i> แผนก</div><div class="value">{{ $department }}</div></div>
            <div class="profile-item"><div class="label"><i class="bi bi-shield-check"></i> สิทธิ์การใช้งาน</div><div class="value">{{ $roleLabel }}</div></div>
        </div>
        <div class="guide">
            <div class="guide-item"><div class="guide-icon"><i class="bi bi-list-check"></i></div><div class="guide-title">ดูงานที่ได้รับมอบหมาย</div><div class="guide-text">ตรวจสอบงาน รายละเอียด ผู้ร่วมงาน และกำหนดส่งงานจากหน้าหลักของคุณ</div></div>
            <div class="guide-item"><div class="guide-icon"><i class="bi bi-arrow-repeat"></i></div><div class="guide-title">อัปเดตสถานะงาน</div><div class="guide-text">เปลี่ยนสถานะเมื่อเริ่มทำงาน ส่งตรวจ หรือทำงานเสร็จ เพื่อให้ทีมเห็นภาพรวมตรงกัน</div></div>
            <div class="guide-item"><div class="guide-icon"><i class="bi bi-people"></i></div><div class="guide-title">ทำงานร่วมกับทีม</div><div class="guide-text">ระบบจะแสดงผู้รับผิดชอบและทีมที่เกี่ยวข้องในแต่ละงาน ช่วยให้ติดตามงานได้ง่ายขึ้น</div></div>
        </div>
        <div class="actions">
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="logout-button"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</button></form>
            <a href="{{ $nextRoute }}" class="button">เข้าสู่ระบบ <i class="bi bi-box-arrow-in-right"></i></a>
        </div>
    </section>
</main>
</body>
</html>
