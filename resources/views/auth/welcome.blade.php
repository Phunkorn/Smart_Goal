@php
    $user = auth()->user();
    $isAdmin = $user?->role === 'admin';
    $roleLabel = $isAdmin ? 'ผู้ดูแลระบบ' : 'ผู้ใช้งาน';
    $department = $user?->department?->department_name ?? 'ยังไม่ได้ระบุแผนก';
    $nextRoute = $isAdmin ? route('board.index') : route('mytasks.index');
    $nextLabel = $isAdmin ? 'เข้าสู่ระบบ' : 'ไปที่งานของฉัน';
@endphp

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ยินดีต้อนรับ | Smart Goal By PremiumCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    @vite('resources/css/pages/auth-welcome.css')
</head>
<body>
    <main class="welcome-shell">
        <section class="welcome-card">
            <div class="progress" aria-label="ขั้นตอนการเริ่มใช้งาน">
                <span class="step"></span>
                <span class="step"></span>
                <span class="step"></span>
            </div>

            <div class="hero">
                <div class="success-mark"><i class="bi bi-check2-circle"></i></div>
                <h1>ยินดีต้อนรับเข้าสู่ระบบจัดการองค์กร</h1>
                <p class="lead">คุณ {{ $user->name }} พร้อมเริ่มใช้งาน Smart Goal By PremiumCare แล้ว ตรวจสอบข้อมูลบัญชีและคำแนะนำสั้น ๆ ก่อนเข้าสู่ระบบจริง</p>
            </div>

            <div class="profile-grid">
                <div class="profile-item">
                    <div class="label"><i class="bi bi-person-badge"></i> ชื่อผู้ใช้งาน</div>
                    <div class="value">{{ $user->name }}</div>
                </div>
                <div class="profile-item">
                    <div class="label"><i class="bi bi-shield-check"></i> สิทธิ์การใช้งาน</div>
                    <div class="value" data-role-label>
                        {{ match(old('role', $employee->role ?? 'user')) {
                            'admin' => 'Admin',
                            'viewer' => 'ผู้เข้าชม',
                            default => 'พนักงาน',
                        } }}
                    </div>
                </div>
                <div class="profile-item">
                    <div class="label"><i class="bi bi-building"></i> แผนก</div>
                    <div class="value">{{ $department }}</div>
                </div>
            </div>

            <div class="guide">
                <div class="guide-item">
                    <div class="guide-icon"><i class="bi bi-list-check"></i></div>
                    <div class="guide-title">ดูงานที่ได้รับมอบหมาย</div>
                    <div class="guide-text">ตรวจชื่องาน รายละเอียด ผู้ร่วมงาน และกำหนดส่งจากหน้าใช้งานหลัก</div>
                </div>
                <div class="guide-item">
                    <div class="guide-icon"><i class="bi bi-arrow-repeat"></i></div>
                    <div class="guide-title">อัปเดตสถานะงาน</div>
                    <div class="guide-text">เปลี่ยนสถานะเมื่อเริ่มทำงาน ส่งตรวจ หรือทำงานเสร็จ เพื่อให้ทีมเห็นภาพรวมตรงกัน</div>
                </div>
                <div class="guide-item">
                    <div class="guide-icon"><i class="bi bi-people"></i></div>
                    <div class="guide-title">ทำงานร่วมกับทีม</div>
                    <div class="guide-text">ระบบจะแสดงผู้รับผิดชอบและทีมที่เกี่ยวข้อง ช่วยให้ติดตามงานได้ง่ายขึ้น</div>
                </div>
            </div>

            <div class="actions">
                <a href="{{ $nextRoute }}" class="button">
                    <i class="bi bi-arrow-right-circle"></i>
                    {{ $nextLabel }}
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="logout-button">
                        <i class="bi bi-box-arrow-right"></i>
                        ออกจากระบบ
                    </button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>

