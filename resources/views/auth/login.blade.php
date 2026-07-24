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
    <main class="auth-shell">

        <section class="auth-card">
                <div class="brand" aria-label="Smart Goal By PremiumCare">
            <img src="{{ asset('images/premiuum-care-logo.png') }}" alt="PremiumCare" class="brand-mark">
            <div>
                <div class="brand-name">Smart Goal By PremiumCare</div>
                <div class="brand-sub">PremiumCare Workforce</div>
            </div>
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
                        <i class="bi bi-envelope"></i>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="name@company.co.th" required autofocus autocomplete="email">
                    </div>
                </div>

                <div class="field">
                    <label for="password">รหัสผ่าน</label>
                    <div class="control">
                        <i class="bi bi-lock"></i>
                        <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน" required autocomplete="current-password">
                        <button type="button" class="icon-button" onclick="togglePassword('password', this)" aria-label="แสดงหรือซ่อนรหัสผ่าน">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <label class="remember" for="remember">
                        <input type="checkbox" id="remember" name="remember" value="1">
                        จดจำการเข้าสู่ระบบ
                    </label>
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
            icon.classList.toggle('bi-eye', !isHidden);
            icon.classList.toggle('bi-eye-slash', isHidden);
        }

        document.querySelector('[data-login-form]').addEventListener('submit', function () {
            const button = this.querySelector('[data-submit]');
            button.disabled = true;
            button.textContent = 'กำลังเข้าสู่ระบบ…';
        });
    </script>
</body>
</html>

