@php
    $minLength = \App\Support\PasswordPolicy::MIN_LENGTH;
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งรหัสผ่านใหม่ | Smart Goals </title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    @vite('resources/css/pages/auth-setup-password.css')
</head>
<body>
    <main class="auth-layout">
        <section class="brand-panel">
            <img src="{{ asset('images/premiuum-care-logo.png') }}" alt="PremiumCare" class="brand-logo">
            <div class="brand-copy"><div class="brand-eyebrow"></div><h1>Smart Goals</h1><p class="brand-tagline">ระบบจัดการองค์กรและติดตามงาน</p></div>
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
                <div class="field"><label for="password">รหัสผ่านใหม่</label><div class="control"><i class="bi bi-lock"></i><input type="password" id="password" name="password" placeholder="อย่างน้อย {{ $minLength }} ตัวอักษร" minlength="{{ $minLength }}" required autocomplete="new-password" oninput="checkPassword()"><button type="button" class="icon-button" onclick="togglePassword('password', this)" aria-label="แสดงหรือซ่อนรหัสผ่าน"><i class="bi bi-eye-fill"></i></button></div></div>
                <div class="field"><label for="password_confirmation">ยืนยันรหัสผ่าน</label><div class="control"><i class="bi bi-lock-fill"></i><input type="password" id="password_confirmation" name="password_confirmation" placeholder="กรอกรหัสผ่านอีกครั้ง" required autocomplete="new-password" oninput="checkPassword()"><button type="button" class="icon-button" onclick="togglePassword('password_confirmation', this)" aria-label="แสดงหรือซ่อนรหัสผ่าน"><i class="bi bi-eye-fill"></i></button></div></div>
                <div class="rules" id="passwordRules" data-min-length="{{ $minLength }}" aria-live="polite">
                    <p class="rules-title">รหัสผ่านต้องมีครบทุกข้อ</p>
                    <div class="rule rule--wide" id="rule-length"><i class="bi bi-circle"></i> อย่างน้อย {{ $minLength }} ตัวอักษร</div>
                    <div class="rule" id="rule-lowercase"><i class="bi bi-circle"></i> ตัวพิมพ์เล็ก (a-z)</div>
                    <div class="rule" id="rule-uppercase"><i class="bi bi-circle"></i> ตัวพิมพ์ใหญ่ (A-Z)</div>
                    <div class="rule" id="rule-number"><i class="bi bi-circle"></i> ตัวเลข (0-9)</div>
                    <div class="rule" id="rule-symbol"><i class="bi bi-circle"></i> สัญลักษณ์ (! @ # $)</div>
                    <div class="rule rule--wide" id="rule-match"><i class="bi bi-circle"></i> รหัสผ่านทั้งสองช่องต้องตรงกัน</div>
                </div>
                <button type="submit" class="submit" id="submitBtn" disabled>บันทึกรหัสผ่าน</button>
            </form>
        </section>
    </main>
    <script>
        function togglePassword(inputId, button){const input=document.getElementById(inputId);const icon=button.querySelector('i');const hidden=input.type==='password';input.type=hidden?'text':'password';icon.classList.toggle('bi-eye-fill',!hidden);icon.classList.toggle('bi-eye-slash-fill',hidden)}
        function setRule(id,ok){const rule=document.getElementById(id);const icon=rule.querySelector('i');rule.classList.toggle('ok',ok);icon.className=ok?'bi bi-check-circle-fill':'bi bi-circle'}
        // ตัวช่วยเตือนเท่านั้น การบังคับจริงอยู่ที่ App\Support\PasswordPolicy ฝั่งเซิร์ฟเวอร์
        function checkPassword(){
            const minLength=Number(document.getElementById('passwordRules').dataset.minLength);
            const p=document.getElementById('password').value;
            const c=document.getElementById('password_confirmation').value;
            const checks={
                'rule-length':[...p].length>=minLength,
                'rule-lowercase':/\p{Ll}/u.test(p),
                'rule-uppercase':/\p{Lu}/u.test(p),
                'rule-number':/\p{N}/u.test(p),
                'rule-symbol':/[\p{Z}\p{S}\p{P}]/u.test(p),
                'rule-match':p.length>0&&p===c,
            };
            let allPassed=true;
            for(const [id,ok] of Object.entries(checks)){setRule(id,ok);allPassed=allPassed&&ok}
            document.getElementById('submitBtn').disabled=!allPassed;
        }
    </script>
</body>
</html>
