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
    <main class="auth-layout">
        <section class="brand-panel">
            <img src="{{ asset('images/premiuum-care-logo.png') }}" alt="PremiumCare" class="brand-logo">
            <div class="brand-copy"><div class="brand-eyebrow">PREMIUMCARE WORKFORCE</div><h1>Smart Goal<br>By PremiumCare</h1></div>
            <p class="brand-description">พื้นที่จัดการงานสำหรับทีม : เช็กงานที่ได้รับมอบหมาย<br>อัปเดตสถานะ และส่งงานตรงเวลา</p>
        </section>

        <section class="auth-card">
            <div class="card-head">
                <div class="eyebrow"><i class="bi bi-key-fill"></i> เข้าใช้งานครั้งแรก</div>
                <h2>ตั้งรหัสผ่านส่วนตัว</h2>
                <p>เพื่อความปลอดภัย กรุณาตั้งรหัสผ่านใหม่ก่อนเริ่มใช้งาน</p>
            </div>
            @if ($errors->any())
                <div class="alert" role="alert"><i class="bi bi-exclamation-circle-fill"></i><span>{{ $errors->first() }}</span></div>
            @endif
            <form method="POST" action="{{ route('password.update.first') }}">
                @csrf
                <div class="field"><label for="password">รหัสผ่านใหม่</label><div class="control"><i class="bi bi-lock"></i><input type="password" id="password" name="password" placeholder="อย่างน้อย 8 ตัวอักษร" required autocomplete="new-password" oninput="checkPassword()"><button type="button" class="icon-button" onclick="togglePassword('password', this)" aria-label="แสดงหรือซ่อนรหัสผ่าน"><i class="bi bi-eye-fill"></i></button></div></div>
                <div class="field"><label for="password_confirmation">ยืนยันรหัสผ่าน</label><div class="control"><i class="bi bi-lock-fill"></i><input type="password" id="password_confirmation" name="password_confirmation" placeholder="กรอกรหัสผ่านอีกครั้ง" required autocomplete="new-password" oninput="checkPassword()"><button type="button" class="icon-button" onclick="togglePassword('password_confirmation', this)" aria-label="แสดงหรือซ่อนรหัสผ่าน"><i class="bi bi-eye-fill"></i></button></div></div>
                <div class="rules" aria-live="polite"><div class="rule" id="rule-length"><i class="bi bi-circle"></i> อย่างน้อย 12 ตัวอักษร</div><div class="rule" id="rule-complexity"><i class="bi bi-circle"></i> มีตัวพิมพ์เล็ก ตัวพิมพ์ใหญ่ ตัวเลข และสัญลักษณ์</div><div class="rule" id="rule-match"><i class="bi bi-circle"></i> รหัสผ่านทั้งสองช่องต้องตรงกัน</div></div>
                <button type="submit" class="submit" id="submitBtn" disabled>บันทึกรหัสผ่าน</button>
            </form>
        </section>
    </main>
    <script>
        function togglePassword(inputId, button){const input=document.getElementById(inputId);const icon=button.querySelector('i');const hidden=input.type==='password';input.type=hidden?'text':'password';icon.classList.toggle('bi-eye-fill',!hidden);icon.classList.toggle('bi-eye-slash-fill',hidden)}
        function setRule(id,ok){const rule=document.getElementById(id);const icon=rule.querySelector('i');rule.classList.toggle('ok',ok);icon.className=ok?'bi bi-check-circle-fill':'bi bi-circle'}
        function checkPassword(){const p=document.getElementById('password').value;const c=document.getElementById('password_confirmation').value;const length=p.length>=12;const complex=/[a-z]/.test(p)&&/[A-Z]/.test(p)&&/\d/.test(p)&&/[^A-Za-z0-9]/.test(p);const match=p.length>0&&p===c;setRule('rule-length',length);setRule('rule-complexity',complex);setRule('rule-match',match);document.getElementById('submitBtn').disabled=!(length&&complex&&match)}
    </script>
</body>
</html>
