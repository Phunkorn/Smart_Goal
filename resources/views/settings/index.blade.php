@extends('layouts.app')

@section('title', 'ตั้งค่า')

@push('styles')
    @vite('resources/css/pages/settings.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/settings/index.js')
@endpush

@section('content')
<div class="settings-page" data-settings-page>
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
                        {{-- แปลงบทบาทที่ Support ตัวเดียว ตารางแปลงเดิมที่นี่มีแต่ค่าใน users.role
                             จึงแสดงหัวหน้าแผนกเป็น "พนักงาน" ทั้งที่แถบบนของหน้าเดียวกันแสดงถูก --}}
                        <strong>{{ \App\Support\RoleLabel::for($user) }}</strong>
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
                <div class="settings-security__summary">
                    <div class="settings-security__icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></div>
                    <div>
                        <span class="settings-card__eyebrow">ความปลอดภัย</span>
                        <h2 id="password-settings-title">รหัสผ่าน</h2>
                        <p>ตั้งรหัสผ่านใหม่และรักษาความปลอดภัยของบัญชีคุณ</p>
                    </div>
                </div>
                <button type="button" class="btn btn-outline-primary settings-security__trigger"
                    data-bs-toggle="modal" data-bs-target="#settingsPasswordModal">
                    <i class="bi bi-key" aria-hidden="true"></i>
                    ตั้งรหัสผ่านใหม่
                </button>
            </div>
        </section>
    </div>

    @include('settings.components.password-modal')
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
@elseif($errors->any() && ! $errors->hasAny(['current_password', 'password']))
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
