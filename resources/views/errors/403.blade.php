@extends('layouts.app')

@section('title', 'ไม่มีสิทธิ์เข้าถึง')

@section('content')
    <div class="panel text-center" style="max-width:560px;margin:3rem auto;padding:2.5rem 2rem;">
        <div class="badge-soft red mb-3" style="font-size:.78rem;">
            <i class="bi bi-shield-lock-fill"></i> จำกัดสิทธิ์
        </div>
        <h1 style="font-size:1.35rem;font-weight:800;margin-bottom:.6rem;">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h1>
        <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:1.4rem;">
            หน้านี้ใช้สำหรับผู้ดูแลระบบหรือผู้บริหารเท่านั้น หากต้องดูงานที่ได้รับมอบหมาย ให้เปิดหน้างานของฉัน
        </p>
        <a href="{{ route('mytasks.index') }}" class="btn-accent d-inline-flex align-items-center gap-2">
            <i class="bi bi-briefcase-fill"></i> ไปที่งานของฉัน
        </a>
    </div>
@endsection
