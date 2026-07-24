<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งรหัสผ่านใหม่ | Smart Goal By PremiumCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    @vite('resources/css/pages/auth-setup-password.css')
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

