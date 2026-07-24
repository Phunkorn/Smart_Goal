@extends('layouts.app')

@section('title', 'ตั้งค่า')

@push('styles')
    @vite('resources/css/pages/settings.css')
@endpush

@section('content')
<div class="settings-page">
    <div class="page-head">
        <div>
            <span class="eyebrow">บัญชีของฉัน</span>
            <h1>ตั้งค่าโปรไฟล์</h1>
            <p>แก้ไขชื่อ เบอร์โทรศัพท์ และรูปภาพโปรไฟล์ของคุณ</p>
        </div>
    </div>

    <section class="settings-card">
        <div class="settings-head">
            <div class="settings-avatar">
                @if($user->profile_image)
                    <img src="{{ route('media.show', ['path' => $user->profile_image]) }}" alt="{{ $user->name }}">
                @else
                    {{ mb_substr($user->name, 0, 2) }}
                @endif
            </div>
            <div>
                <h2 class="settings-user-name">{{ $user->name }}</h2>
                <div class="settings-user-email">{{ $user->email }}</div>
            </div>
        </div>

        <div class="readonly-grid">
            <div class="readonly-box">
                <div class="label">สิทธิ์การใช้งาน</div>
                <strong>{{ ['admin' => 'ผู้ดูแลระบบ', 'viewer' => 'ผู้เข้าชม', 'user' => 'พนักงาน'][$user->role] ?? 'พนักงาน' }}</strong>
            </div>
            <div class="readonly-box">
                <div class="label">แผนก</div>
                <strong>{{ optional($user->department)->department_name ?? '-' }}</strong>
            </div>
        </div>

        <form action="{{ route('settings.update') }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PATCH')

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">ชื่อ</label>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">เบอร์โทรศัพท์</label>
                    <input type="text" name="phone" value="{{ old('phone', $user->phone) }}" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold">รูปภาพโปรไฟล์</label>
                    <input type="file" name="profile_image" class="form-control" accept="image/png,image/jpeg,image/webp">
                    <div class="text-muted mt-1 settings-admin-note">ผู้ใช้ทั่วไปไม่สามารถเปลี่ยนแผนกหรือสิทธิ์ได้ ต้องให้ Admin แก้ให้</div>
                </div>
            </div>

            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึกข้อมูล</button>
            </div>
        </form>
    </section>
</div>

@if(session('success'))
    <script>
        Swal.fire({ icon: 'success', title: 'สำเร็จ', text: @json(session('success')), confirmButtonText: 'ตกลง' });
    </script>
@endif
@endsection


