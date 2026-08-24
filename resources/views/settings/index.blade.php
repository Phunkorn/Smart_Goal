@extends('layouts.app')

@section('title', 'ตั้งค่า')

@push('styles')
    @vite('resources/css/pages/settings.css')
@endpush

@section('content')
<div class="settings-page">
    <header class="settings-page__header">
        <div>
            <span class="eyebrow">บัญชีของฉัน</span>
            <h1>ตั้งค่าบัญชี</h1>
            <p>จัดการข้อมูลส่วนตัวและความปลอดภัยของบัญชีคุณ</p>
        </div>
    </header>

    <div class="settings-page__layout">
        <section class="settings-card" aria-labelledby="profile-settings-title">
            <div class="settings-card__header">
                <div class="settings-profile">
                    <div class="settings-profile__avatar">
                        @if($user->profile_image)
                            <img src="{{ route('media.profile', $user) }}" alt="รูปโปรไฟล์ของ {{ $user->name }}">
                        @else
                            {{ mb_substr($user->name, 0, 2) }}
                        @endif
                    </div>
                    <div class="settings-profile__identity">
                        <span class="settings-card__eyebrow">ข้อมูลส่วนตัว</span>
                        <h2 id="profile-settings-title">{{ $user->name }}</h2>
                        <p>{{ $user->email ?: '@'.$user->username }}</p>
                    </div>
                </div>
            </div>

            <div class="settings-account-meta" aria-label="ข้อมูลสิทธิ์และแผนก">
                <div class="settings-account-meta__item">
                    <i class="bi bi-person-badge" aria-hidden="true"></i>
                    <div>
                        <span>สิทธิ์การใช้งาน</span>
                        <strong>{{ ['admin' => 'ผู้ดูแลระบบ', 'viewer' => 'ผู้เข้าชม', 'user' => 'พนักงาน'][$user->role] ?? 'พนักงาน' }}</strong>
                    </div>
                </div>
                <div class="settings-account-meta__item">
                    <i class="bi bi-diagram-3" aria-hidden="true"></i>
                    <div>
                        <span>แผนก</span>
                        <strong>{{ optional($user->department)->department_name ?? 'ไม่ได้ระบุ' }}</strong>
                    </div>
                </div>
            </div>

            <form action="{{ route('settings.update') }}" method="POST" enctype="multipart/form-data" class="settings-form">
                @csrf
                @method('PATCH')

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="settingsName" class="form-label">ชื่อ</label>
                        <input id="settingsName" type="text" name="name" value="{{ old('name', $user->name) }}"
                            class="form-control @error('name') is-invalid @enderror" autocomplete="name" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="settingsPhone" class="form-label">เบอร์โทรศัพท์</label>
                        <input id="settingsPhone" type="tel" name="phone" value="{{ old('phone', $user->phone) }}"
                            class="form-control @error('phone') is-invalid @enderror" autocomplete="tel">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="settingsProfileImage" class="form-label">รูปภาพโปรไฟล์</label>
                        <input id="settingsProfileImage" type="file" name="profile_image"
                            class="form-control @error('profile_image') is-invalid @enderror"
                            accept="image/png,image/jpeg,image/webp">
                        @error('profile_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <p class="settings-form__help">รองรับ JPG, PNG หรือ WebP ขนาดไม่เกิน 4 MB การเปลี่ยนสิทธิ์และแผนกต้องดำเนินการโดย Admin</p>
                    </div>
                </div>

                <div class="settings-card__actions">
                    <button type="submit" class="btn btn-primary settings-primary-button">
                        <i class="bi bi-save" aria-hidden="true"></i>
                        บันทึกข้อมูล
                    </button>
                </div>
            </form>
        </section>

        <section class="settings-card settings-security" aria-labelledby="password-settings-title">
            <div class="settings-card__header settings-security__header">
                <div class="settings-security__icon" aria-hidden="true">
                    <i class="bi bi-shield-lock"></i>
                </div>
                <div>
                    <span class="settings-card__eyebrow">ความปลอดภัย</span>
                    <h2 id="password-settings-title">เปลี่ยนรหัสผ่าน</h2>
                    <p>ยืนยันรหัสผ่านปัจจุบันก่อนตั้งรหัสผ่านใหม่</p>
                </div>
            </div>

            <div class="settings-security__notice">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>เมื่อเปลี่ยนสำเร็จ ระบบจะนำบัญชีออกจากอุปกรณ์อื่นและให้ session ปัจจุบันใช้งานต่ออย่างปลอดภัย</span>
            </div>

            <form action="{{ route('settings.password.update') }}" method="POST" class="settings-form settings-password-form">
                @csrf
                @method('PATCH')

                <div>
                    <label for="currentPassword" class="form-label">รหัสผ่านปัจจุบัน <span aria-hidden="true">*</span></label>
                    <input id="currentPassword" type="password" name="current_password"
                        class="form-control @error('current_password') is-invalid @enderror"
                        autocomplete="current-password" required>
                    @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="settings-password-grid">
                    <div>
                        <label for="newPassword" class="form-label">รหัสผ่านใหม่ <span aria-hidden="true">*</span></label>
                        <input id="newPassword" type="password" name="password"
                            class="form-control @error('password') is-invalid @enderror"
                            minlength="12" autocomplete="new-password" required>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="newPasswordConfirmation" class="form-label">ยืนยันรหัสผ่านใหม่ <span aria-hidden="true">*</span></label>
                        <input id="newPasswordConfirmation" type="password" name="password_confirmation"
                            class="form-control" minlength="12" autocomplete="new-password" required>
                    </div>
                </div>

                <p class="settings-form__help">อย่างน้อย 12 ตัวอักษร และต้องมีตัวพิมพ์เล็ก ตัวพิมพ์ใหญ่ ตัวเลข และสัญลักษณ์</p>

                <div class="settings-card__actions">
                    <button type="submit" class="btn btn-primary settings-primary-button">
                        <i class="bi bi-key" aria-hidden="true"></i>
                        เปลี่ยนรหัสผ่าน
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>

@if(session('success'))
    <script>
        window.Swal?.fire({
            icon: 'success',
            title: 'สำเร็จ',
            text: @json(session('success')),
            confirmButtonText: 'ตกลง'
        });
    </script>
@elseif($errors->any())
    <script>
        window.Swal?.fire({
            icon: 'error',
            title: 'ไม่สามารถบันทึกได้',
            text: @json($errors->first()),
            confirmButtonText: 'ตรวจสอบอีกครั้ง'
        });
    </script>
@endif
@endsection
