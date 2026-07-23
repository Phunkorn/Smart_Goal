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
    <style>
        :root {
            --bg: #f4f6fb;
            --panel: #ffffff;
            --ink: #172033;
            --muted: #667085;
            --line: #e4e8f2;
            --primary: #5b47e0;
            --primary-dark: #4733c9;
            --primary-soft: #efecff;
            --success: #079455;
            --success-soft: #ecfdf3;
            --shadow: 0 24px 70px rgba(30, 41, 59, .12);
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Sarabun', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(91, 71, 224, .15), transparent 32rem),
                radial-gradient(circle at bottom right, rgba(7, 148, 85, .12), transparent 30rem),
                linear-gradient(135deg, #f8fafc 0%, #eef2fb 100%);
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .welcome-shell {
            width: min(100%, 720px);
        }

        .welcome-card {
            background: var(--panel);
            border: 1px solid rgba(228, 232, 242, .9);
            border-radius: 24px;
            box-shadow: var(--shadow);
            padding: 34px;
        }

        .progress {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin-bottom: 28px;
        }

        .step {
            height: 7px;
            border-radius: 999px;
            background: var(--primary);
        }

        .hero {
            text-align: center;
            max-width: 560px;
            margin: 0 auto 26px;
        }

        .success-mark {
            width: 66px;
            height: 66px;
            border-radius: 22px;
            display: grid;
            place-items: center;
            margin: 0 auto 18px;
            background: var(--success-soft);
            color: var(--success);
            font-size: 32px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 28px;
            letter-spacing: 0;
        }

        .lead {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.75;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin: 26px 0;
        }

        .profile-item {
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            background: #fbfcff;
        }

        .profile-item .label {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .profile-item .value {
            font-size: 15px;
            font-weight: 700;
            line-height: 1.45;
            word-break: break-word;
        }

        .guide {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 26px;
        }

        .guide-item {
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 16px;
            min-height: 132px;
        }

        .guide-icon {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: var(--primary-soft);
            color: var(--primary-dark);
            margin-bottom: 12px;
        }

        .guide-title {
            font-weight: 700;
            margin-bottom: 6px;
        }

        .guide-text {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.65;
        }

        .actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .button,
        .logout-button {
            min-height: 48px;
            border-radius: 14px;
            padding: 0 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .button {
            border: 0;
            background: var(--primary);
            color: #fff;
            box-shadow: 0 14px 28px rgba(91, 71, 224, .24);
        }

        .button:hover {
            background: var(--primary-dark);
        }

        .logout-button {
            border: 1px solid var(--line);
            background: #fff;
            color: var(--muted);
        }

        @media (max-width: 700px) {
            body { padding: 16px; }
            .welcome-card { padding: 24px 18px; border-radius: 20px; }
            .profile-grid,
            .guide { grid-template-columns: 1fr; }
            h1 { font-size: 23px; }
        }
    </style>
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
