@extends('layouts.app')

@section('title', 'ตั้งค่า')

@push('styles')
<style>
    .settings-page { max-width:900px; margin:0 auto; }
    .settings-card { background:#fff; border:1px solid var(--border); border-radius:16px; padding:22px; box-shadow:var(--shadow-sm); }
    .settings-head { display:flex; gap:16px; align-items:center; margin-bottom:20px; }
    .settings-avatar { width:78px; height:78px; border-radius:22px; overflow:hidden; background:var(--accent-dim); color:var(--accent-strong); display:grid; place-items:center; font-size:22px; font-weight:800; flex:0 0 auto; }
    .settings-avatar img { width:100%; height:100%; object-fit:cover; }
    .readonly-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-bottom:18px; }
    .readonly-box { border:1px solid var(--border); border-radius:12px; padding:12px; background:var(--surface-2); }
    .readonly-box .label { color:var(--text-muted); font-size:12px; font-weight:800; margin-bottom:4px; }
    @media (max-width:700px){ .readonly-grid{grid-template-columns:1fr;} .settings-head{align-items:flex-start;flex-direction:column;} }
</style>
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
                <h2 style="font-size:20px;font-weight:800;margin:0;">{{ $user->name }}</h2>
                <div style="color:var(--text-muted);margin-top:4px;">{{ $user->email }}</div>
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
                    <div class="text-muted mt-1" style="font-size:12px;">ผู้ใช้ทั่วไปไม่สามารถเปลี่ยนแผนกหรือสิทธิ์ได้ ต้องให้ Admin แก้ให้</div>
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
