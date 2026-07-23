<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ | Smart Goal By PremiumCare</title>
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
            --danger: #d92d20;
            --danger-soft: #fef3f2;
            --shadow: 0 24px 70px rgba(30, 41, 59, .12);
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Sarabun', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(91, 71, 224, .16), transparent 34rem),
                linear-gradient(135deg, #f7f8fc 0%, #edf1fb 100%);
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .auth-shell {
            width: min(100%, 460px);
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-bottom: 22px;
            color: var(--ink);
        }

        .brand-mark {
            width: 112px;
            height: auto;
            max-height: 70px;
            object-fit: contain;
            flex: 0 0 auto;
        }

        .brand-name {
            font-size: 18px;
            font-weight: 700;
            line-height: 1;
        }

        .brand-sub {
            font-size: 11px;
            letter-spacing: .08em;
            color: var(--muted);
            text-transform: uppercase;
            margin-top: 3px;
        }

        .auth-card {
            background: var(--panel);
            border: 1px solid rgba(228, 232, 242, .9);
            border-radius: 22px;
            box-shadow: var(--shadow);
            padding: 30px;
        }

        .card-head {
            text-align: center;
            margin-bottom: 24px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 10px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 14px;
        }

        h1 {
            font-size: 25px;
            margin: 0 0 8px;
            letter-spacing: 0;
        }

        .lead {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .alert {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 12px 14px;
            border-radius: 14px;
            margin-bottom: 18px;
            background: var(--danger-soft);
            color: var(--danger);
            border: 1px solid #fecaca;
            font-size: 14px;
            line-height: 1.55;
        }

        .field {
            margin-bottom: 16px;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .control {
            min-height: 50px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            padding: 0 14px;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .control:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(91, 71, 224, .12);
        }

        .control i {
            color: #8a94a6;
            font-size: 18px;
        }

        input {
            width: 100%;
            border: 0;
            outline: 0;
            background: transparent;
            color: var(--ink);
            font: inherit;
            font-size: 15px;
        }

        input::placeholder {
            color: #a3abb9;
        }

        .icon-button {
            border: 0;
            background: transparent;
            color: #7b8496;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            cursor: pointer;
            display: grid;
            place-items: center;
        }

        .icon-button:hover {
            background: #f3f5fa;
            color: var(--ink);
        }

        .form-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin: 2px 0 20px;
            color: var(--muted);
            font-size: 13px;
        }

        .remember {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .remember input {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
        }

        .help-text {
            color: var(--primary-dark);
            font-weight: 700;
            text-decoration: none;
        }

        .submit {
            width: 100%;
            height: 50px;
            border: 0;
            border-radius: 14px;
            background: var(--primary);
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 14px 28px rgba(91, 71, 224, .24);
        }

        .submit:hover {
            background: var(--primary-dark);
        }

        .support {
            margin: 20px 0 0;
            color: var(--muted);
            text-align: center;
            font-size: 13px;
            line-height: 1.7;
        }

        @media (max-width: 520px) {
            body { padding: 16px; }
            .auth-card { padding: 24px 18px; border-radius: 18px; }
            .brand {
                gap: 10px;
                align-items: flex-start;
            }
            .brand-mark {
                width: 86px;
                max-height: 56px;
            }
            h1 { font-size: 22px; }
            .form-row { align-items: flex-start; flex-direction: column; }
        }
    </style>
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
