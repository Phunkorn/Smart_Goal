<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เข้าสู่ระบบ | Smart Goals</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    @vite(['resources/css/pages/auth-login.css', 'resources/js/pages/auth/experience.js'])
</head>
<body class="auth-login">
    @include('auth.partials.backdrop')

    <main class="auth-layout">
        @include('auth.partials.brand', [
            'heading' => 'Smart Goals',
            'tagline' => 'ระบบจัดการองค์กรและติดตามงาน ที่ทำให้ทุกคนในทีมเห็นภาพเดียวกัน',
        ])

        <div class="auth-stage auth-rise" data-auth-rise data-width="narrow">
            <section class="auth-card auth-card__enter" data-auth-card>
                <div class="card-head">
                    <h2>เข้าสู่ระบบ</h2>
                    <p>กรอกชื่อผู้ใช้และรหัสผ่านเพื่อเริ่มต้นการทำงาน</p>
                </div>

                @if ($errors->any())
                    <div class="alert" role="alert">
                        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                        <span>{{ $errors->first() }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('login.submit') }}" data-login-form data-auth-form>
                    @csrf
                    <div class="field">
                        <label for="username">ชื่อผู้ใช้</label>
                        <div class="control">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <input type="text" id="username" name="username" value="{{ old('username') }}" placeholder="ชื่อผู้ใช้" required autofocus autocomplete="username">
                        </div>
                    </div>

                    <div class="field">
                        <label for="password">รหัสผ่าน</label>
                        <div class="control">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน" required autocomplete="current-password">
                            <button type="button" class="icon-button" data-toggle-password="password" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye-fill" aria-hidden="true"></i></button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn--block" data-submit data-auth-submit data-loading-label="กำลังตรวจสอบ"><span>เข้าสู่ระบบ</span></button>
                </form>

                </section>
        </div>
    </main>

    @include('auth.partials.toast')
</body>
</html>
