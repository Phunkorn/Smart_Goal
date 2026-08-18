<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ | Smart Goal By PremiumCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    @vite('resources/css/pages/auth-login.css')
</head>
<body>
    <main class="auth-layout">
        <section class="brand-panel" aria-label="Smart Goal By PremiumCare">
            <img src="{{ asset('images/premiuum-care-logo.png') }}" alt="PremiumCare" class="brand-logo">
            <div class="brand-copy">
                <div class="brand-eyebrow">PREMIUMCARE WORKFORCE</div>
                <h1>Smart Goal<br>By PremiumCare</h1>
            </div>
            <p class="brand-description">พื้นที่จัดการงานสำหรับทีม : เช็กงานที่ได้รับมอบหมาย<br>อัปเดตสถานะ และส่งงานตรงเวลา</p>
        </section>

        <section class="auth-card">
            <div class="card-head">
                <h2>เข้าสู่ระบบ</h2>
                <p>กรอกอีเมลและรหัสผ่านเพื่อเริ่มต้นการทำงาน</p>
            </div>

            @if ($errors->any())
                <div class="alert" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('login.submit') }}" data-login-form>
                @csrf
                <div class="field">
                    <label for="email">อีเมล</label>
                    <div class="control">
                        <i class="bi bi-person-fill"></i>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="name@company.co.th" required autofocus autocomplete="email">
                    </div>
                </div>

                <div class="field">
                    <label for="password">รหัสผ่าน</label>
                    <div class="control">
                        <i class="bi bi-lock-fill"></i>
                        <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน" required autocomplete="current-password">
                        <button type="button" class="icon-button" onclick="togglePassword('password', this)" aria-label="แสดงหรือซ่อนรหัสผ่าน"><i class="bi bi-eye-fill"></i></button>
                    </div>
                </div>

                <button type="submit" class="submit" data-submit>เข้าสู่ระบบ</button>
            </form>
        </section>
    </main>

    <script>
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            icon.classList.toggle('bi-eye-fill', !isHidden);
            icon.classList.toggle('bi-eye-slash-fill', isHidden);
        }
        document.querySelector('[data-login-form]').addEventListener('submit', function () {
            const button = this.querySelector('[data-submit]');
            button.disabled = true;
            button.textContent = 'กำลังเข้าสู่ระบบ…';
        });
    </script>
</body>
</html>
