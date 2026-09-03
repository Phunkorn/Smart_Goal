@extends('layouts.app')

@section('title', 'คำขออนุมัติ')

@push('styles')
    @vite('resources/css/pages/admin-approvals.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/admin/approvals.js')
@endpush

@section('content')
<div class="admin-approvals-page">
    <header class="admin-approvals-header">
        <span class="admin-approvals-eyebrow">{{ $isAdminViewer ? 'ADMIN APPROVALS' : 'DEPARTMENT APPROVALS' }}</span>
        <h1>คำขออนุมัติ</h1>
        <p>
            @if($isAdminViewer)
                คำขอข้ามแผนกทั้งหมดที่รอการตัดสินใจ — ขอบเขต {{ $approvalScopeLabel }}
            @else
                คำขอที่ขอส่งงานเข้ามาที่{{ $approvalScopeLabel }} คุณเป็นผู้ตัดสินว่าจะรับหรือไม่
            @endif
        </p>
    </header>

    <section class="admin-approvals-summary" aria-label="สรุปคำขออนุมัติ">
        <article class="admin-approvals-summary__card admin-approvals-summary__card--total">
            <span>รอทั้งหมด</span>
            <strong>{{ $approvalCounts['total'] }}</strong>
            <i class="bi bi-inbox-fill" aria-hidden="true"></i>
        </article>
        <article class="admin-approvals-summary__card">
            <span>งานข้ามแผนก</span>
            <strong>{{ $approvalCounts['assignments'] }}</strong>
            <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
        </article>
        <article class="admin-approvals-summary__card">
            <span>ผู้ร่วมงานข้ามแผนก</span>
            <strong>{{ $approvalCounts['collaborators'] }}</strong>
            <i class="bi bi-people-fill" aria-hidden="true"></i>
        </article>
    </section>

    <nav class="admin-approvals-tabs" aria-label="ประเภทคำขออนุมัติ">
        <a href="#assignment-approval-queue">งานข้ามแผนก <span>{{ $approvalCounts['assignments'] }}</span></a>
        <a href="#collaborator-approval-queue">ผู้ร่วมงานข้ามแผนก <span>{{ $approvalCounts['collaborators'] }}</span></a>
    </nav>

    @include('admin.approvals.components.assignment-queue')
    @include('admin.approvals.components.collaborator-queue')
</div>
@endsection
