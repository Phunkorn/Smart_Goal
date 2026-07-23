<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งรหัสผ่านใหม่ | Smart Goal By PremiumCare</title>
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
            --warning: #b54708;
            --warning-soft: #fffaeb;
            --danger: #d92d20;
            --danger-soft: #fef3f2;
            --shadow: 0 24px 70px rgba(30, 41, 59, .12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Sarabun', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top right, rgba(7, 148, 85, .13), transparent 28rem),
                radial-gradient(circle at bottom left, rgba(91, 71, 224, .14), transparent 34rem),
                linear-gradient(135deg, #f8fafc 0%, #eef2fb 100%);
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .auth-shell {
            width: min(100%, 500px);
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

        .progress {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin-bottom: 24px;
        }

        .step {
            height: 7px;
            border-radius: 999px;
            background: #e7eaf3;
        }

        .step.done,
        .step.active {
            background: var(--primary);
        }

        .card-head {
            text-align: center;
            margin-bottom: 22px;
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

        .notice,
        .alert {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 12px 14px;
            border-radius: 14px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.55;
        }

        .notice {
            background: var(--warning-soft);
            color: var(--warning);
            border: 1px solid #fedf89;
        }

        .alert {
            background: var(--danger-soft);
            color: var(--danger);
            border: 1px solid #fecaca;
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

        .rules {
            display: grid;
            gap: 8px;
            margin: 12px 0 18px;
        }

        .rule {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 13px;
        }

        .rule i {
            color: #b3bac8;
        }

        .rule.ok {
            color: var(--success);
        }

        .rule.ok i {
            color: var(--success);
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
            margin-top: 4px;
        }

        .submit:hover {
            background: var(--primary-dark);
        }

        .submit:disabled {
            background: #b7afea;
            cursor: not-allowed;
            box-shadow: none;
        }

        @media (max-width: 520px) {
            body {
                padding: 16px;
            }

            .auth-card {
                padding: 24px 18px;
                border-radius: 18px;
            }

            .brand {
                gap: 10px;
                align-items: flex-start;
            }

            .brand-mark {
                width: 86px;
                max-height: 56px;
            }

            h1 {
                font-size: 22px;
            }
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
            <div class="progress" aria-label="ขั้นตอนการเริ่มใช้งาน">
                <span class="step done"></span>
                <span class="step active"></span>
                <span class="step"></span>
            </div>

            <div class="card-head">
                <div class="eyebrow"><i class="bi bi-key-fill"></i> เข้าใช้งานครั้งแรก</div>
                <h1>ตั้งรหัสผ่านส่วนตัว</h1>
                <p class="lead">เพื่อความปลอดภัย กรุณาสร้างรหัสผ่านใหม่ก่อนเริ่มใช้งาน <br> Smart Goal By PremiumCare</p>
            </div>


            @if ($errors->any())
                <div class="alert" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('password.update.first') }}">
                @csrf

                <div class="field">
                    <label for="password">รหัสผ่านใหม่</label>
                    <div class="control">
                        <i class="bi bi-lock"></i>
                        <input type="password" id="password" name="password" placeholder="อย่างน้อย 8 ตัวอักษร"
                            required autocomplete="new-password" oninput="checkPassword()">
                        <button type="button" class="icon-button" onclick="togglePassword('password', this)"
                            aria-label="แสดงหรือซ่อนรหัสผ่าน">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="field">
                    <label for="password_confirmation">ยืนยันรหัสผ่าน</label>
                    <div class="control">
                        <i class="bi bi-lock-fill"></i>
                        <input type="password" id="password_confirmation" name="password_confirmation"
                            placeholder="กรอกรหัสผ่านอีกครั้ง" required autocomplete="new-password"
                            oninput="checkPassword()">
                        <button type="button" class="icon-button"
                            onclick="togglePassword('password_confirmation', this)" aria-label="แสดงหรือซ่อนรหัสผ่าน">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="rules" aria-live="polite">
                    <div class="rule" id="rule-length"><i class="bi bi-circle"></i> อย่างน้อย 12 ตัวอักษร</div>
                    <div class="rule" id="rule-complexity"><i class="bi bi-circle"></i> มีตัวพิมพ์เล็ก ตัวพิมพ์ใหญ่ ตัวเลข และสัญลักษณ์</div>
                    <div class="rule" id="rule-match"><i class="bi bi-circle"></i> รหัสผ่านทั้งสองช่องต้องตรงกัน</div>
                </div>

                <button type="submit" class="submit" id="submitBtn" disabled>บันทึกรหัสผ่านและไปต่อ</button>
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

        function setRule(id, ok) {
            const rule = document.getElementById(id);
            const icon = rule.querySelector('i');
            rule.classList.toggle('ok', ok);
            icon.className = ok ? 'bi bi-check-circle-fill' : 'bi bi-circle';
        }

        function checkPassword() {
            const password = document.getElementById('password').value;
            const confirmation = document.getElementById('password_confirmation').value;
            const hasLength = password.length >= 12;
            const hasComplexity = /[a-z]/.test(password) && /[A-Z]/.test(password) && /\d/.test(password) && /[^A-Za-z0-9]/.test(password);
            const isMatched = password.length > 0 && password === confirmation;

            setRule('rule-length', hasLength);
            setRule('rule-complexity', hasComplexity);
            setRule('rule-match', isMatched);
            document.getElementById('submitBtn').disabled = !(hasLength && hasComplexity && isMatched);
        }
    </script>
</body>

</html>
