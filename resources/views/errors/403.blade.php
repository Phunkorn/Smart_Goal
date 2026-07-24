@extends('layouts.app')

@section('title', 'ไม่มีสิทธิ์เข้าถึง')

@push('styles')
    @vite('resources/css/pages/errors.css')
@endpush

@section('content')
    <div class="panel text-center error-card">
        <div class="badge-soft red mb-3 error-badge">
            <i class="bi bi-shield-lock-fill"></i> จำกัดสิทธิ์
        </div>
        <h1 class="error-title">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h1>
        <p class="error-copy">
            หน้านี้ใช้สำหรับผู้ดูแลระบบหรือผู้บริหารเท่านั้น หากต้องดูงานที่ได้รับมอบหมาย ให้เปิดหน้างานของฉัน
        </p>
        <a href="{{ route('mytasks.index') }}" class="btn-accent d-inline-flex align-items-center gap-2">
            <i class="bi bi-briefcase-fill"></i> ไปที่งานของฉัน
        </a>
    </div>
@endsection
