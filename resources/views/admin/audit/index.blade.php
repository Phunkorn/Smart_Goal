@extends('layouts.app')

@section('title', 'Audit Log')

@push('styles')
    @vite(['resources/css/pages/admin-audit.css', 'resources/js/pages/admin/audit.js'])
@endpush

@php
    $tabs = [
        'overview' => ['ภาพรวม', 'bi-speedometer2'],
        'activity' => ['กิจกรรมผู้ใช้', 'bi-person-lines-fill'],
        'trash' => ['ถังขยะ', 'bi-trash3'],
    ];

    // ลิงก์แท็บต้องพาตัวกรองปัจจุบันไปด้วย ไม่งั้นการสลับแท็บจะล้างสิ่งที่ผู้ใช้กรองไว้
    $tabUrl = fn (string $key) => route('admin.audit.index', array_merge(request()->query(), ['tab' => $key]));
@endphp

@section('content')
<div class="audit-page">
    <section class="audit-head">
        <div class="audit-head__copy">
            <span class="audit-kicker"><i class="bi bi-shield-lock" aria-hidden="true"></i> บันทึกตรวจสอบ</span>
            <h1>Audit Log</h1>
            <p>ตรวจสอบการเข้าออกระบบ การเปลี่ยนแปลงข้อมูล และรายการที่ถูกลบ ไว้ในที่เดียว</p>
        </div>
        @if ($tab === 'trash')
            <div class="audit-head__actions">
                <a class="audit-btn" href="{{ route('admin.trash.export', request()->query()) }}">
                    <i class="bi bi-filetype-csv" aria-hidden="true"></i> ส่งออก CSV
                </a>
            </div>
        @endif
    </section>

    <nav class="audit-tabs" aria-label="มุมมองบันทึกตรวจสอบ">
        @foreach ($tabs as $key => [$label, $icon])
            <a href="{{ $tabUrl($key) }}"
               class="audit-tab {{ $tab === $key ? 'is-active' : '' }}"
               @if ($tab === $key) aria-current="page" @endif>
                <i class="bi {{ $icon }}" aria-hidden="true"></i>
                <span>{{ $label }}</span>
            </a>
        @endforeach
    </nav>

    @include('admin.audit.partials.filters')

    @switch($tab)
        @case('activity')
            @include('admin.audit.partials.activity')
            @break
        @case('trash')
            @include('admin.audit.partials.trash')
            @break
        @default
            @include('admin.audit.partials.overview')
    @endswitch
</div>
@endsection
