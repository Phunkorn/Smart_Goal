@php
    $minLength = \App\Support\PasswordPolicy::MIN_LENGTH;
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ตั้งรหัสผ่านใหม่ | Smart Goals</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    @vite(['resources/css/pages/auth-setup-password.css', 'resources/js/pages/auth/experience.js'])
</head>
<body class="auth-setup-password">
    @include('auth.partials.backdrop')

    <main class="auth-layout">
        @include('auth.partials.brand', [
            'heading' => 'Smart Goals',
            'tagline' => 'อีกขั้นเดียวก่อนเริ่มงาน ตั้งรหัสผ่านที่คุณจำได้และคนอื่นเดาไม่ได้',
        ])

        <div class="auth-stage auth-rise" data-auth-rise data-width="narrow">
            <section class="auth-card auth-card__enter" data-auth-card>
                <div class="card-pill"><span><i class="bi bi-stars" aria-hidden="true"></i> เข้าใช้งานครั้งแรก</span></div>
                <div class="card-head">
                    <h2>ตั้งรหัสผ่านส่วนตัว</h2>
                    <p>เพื่อความปลอดภัย กรุณาตั้งรหัสผ่านใหม่ก่อนเริ่มใช้งาน</p>
                </div>

                @if ($errors->any())
                    <div class="alert" role="alert">
                        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                        <span>{{ $errors->first() }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.update.first') }}" data-auth-form>
                    @csrf
                    <div class="field">
                        <label for="password">รหัสผ่านใหม่</label>
                        <div class="control" id="controlPassword">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input type="password" id="password" name="password" placeholder="อย่างน้อย {{ $minLength }} ตัวอักษร" minlength="{{ $minLength }}" required autocomplete="new-password">
                            <button type="button" class="icon-button" data-toggle-password="password" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye-fill" aria-hidden="true"></i></button>
                        </div>
                    </div>

                    <div class="field">
                        <label for="password_confirmation">ยืนยันรหัสผ่าน</label>
                        <div class="control" id="controlConfirmation">
                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                            <input type="password" id="password_confirmation" name="password_confirmation" placeholder="กรอกรหัสผ่านอีกครั้ง" required autocomplete="new-password">
                            <button type="button" class="icon-button" data-toggle-password="password_confirmation" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye-fill" aria-hidden="true"></i></button>
                        </div>
                        <div class="field-msg" id="confirmationMessage">รหัสผ่านทั้งสองช่องยังไม่ตรงกัน</div>
                    </div>

                    <div class="meter" aria-hidden="true"><i id="passwordMeter"></i></div>

                    <div class="rules" id="passwordRules" data-min-length="{{ $minLength }}" aria-live="polite">
                        <p class="rules-title">รหัสผ่านต้องมีครบทุกข้อ</p>
                        <div class="rules-list">
                            <div class="rule rule--wide" id="rule-length"><i class="bi bi-circle"></i> อย่างน้อย {{ $minLength }} ตัวอักษร</div>
                            <div class="rule" id="rule-lowercase"><i class="bi bi-circle"></i> ตัวพิมพ์เล็ก (a-z)</div>
                            <div class="rule" id="rule-uppercase"><i class="bi bi-circle"></i> ตัวพิมพ์ใหญ่ (A-Z)</div>
                            <div class="rule" id="rule-number"><i class="bi bi-circle"></i> ตัวเลข (0-9)</div>
                            <div class="rule" id="rule-symbol"><i class="bi bi-circle"></i> สัญลักษณ์ (! @ # $)</div>
                            <div class="rule rule--wide" id="rule-match"><i class="bi bi-circle"></i> รหัสผ่านทั้งสองช่องต้องตรงกัน</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn--block" id="submitBtn" data-auth-submit data-loading-label="กำลังบันทึก" disabled><span>บันทึกรหัสผ่าน</span></button>
                </form>
            </section>
        </div>
    </main>

    @include('auth.partials.toast')

</body>
</html>
