@extends('layouts.app')

@section('title', 'คำขออนุมัติ')

@push('styles')
    @vite('resources/css/pages/admin-approvals.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/admin/approvals.js')
@endpush

@section('content')
@php
    /*
     * แท็บเป็นลิงก์ ?approval_queue= จริง — server วาดเฉพาะคิวที่เลือก (AdminApprovalController)
     * "ทั้งหมด" เรียงทุกคิวรออนุมัติซ้อนกันเต็มความกว้าง ส่วน "พนักงานไปร่วมงานแผนกอื่น"
     * เป็นมุมมองติดตาม ไม่ใช่คำขอ จึงไม่ปนอยู่ใน "ทั้งหมด" และไม่ถูกนับในรอทั้งหมด
     */
    $queueTabs = [
        'all' => ['ทั้งหมด', $approvalCounts['total']],
        'assignment' => ['งานข้ามแผนก', $approvalCounts['assignments']],
        'collaborator' => ['ผู้ร่วมงานข้ามแผนก', $approvalCounts['collaborators']],
        'share' => ['ผู้ขอร่วมงานจากการแชร์งาน', $approvalCounts['shares']],
        'outgoing' => ['พนักงานไปร่วมงานแผนกอื่น', $outgoingCollaborations->count()],
    ];
    $showsQueue = fn (string $queue): bool => $approvalQueue === $queue || ($approvalQueue === 'all' && $queue !== 'outgoing');
@endphp
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
        <article class="admin-approvals-summary__card">
            <span>ผู้ขอร่วมงานจากการแชร์งาน</span>
            <strong>{{ $approvalCounts['shares'] }}</strong>
            <i class="bi bi-share-fill" aria-hidden="true"></i>
        </article>
    </section>

    <nav class="admin-approvals-tabs" aria-label="ประเภทคำขออนุมัติ">
        @foreach($queueTabs as $queue => [$label, $count])
            <a href="{{ route('admin.approvals.index', $queue === 'all' ? [] : ['approval_queue' => $queue]) }}"
                class="{{ $approvalQueue === $queue ? 'is-active' : '' }} {{ $queue === 'outgoing' ? 'admin-approvals-tabs__outgoing' : '' }}"
                @if($approvalQueue === $queue) aria-current="page" @endif>
                {{ $label }} <span>{{ $count }}</span>
            </a>
        @endforeach
    </nav>

    <div class="admin-approvals-queues">
        @if($showsQueue('assignment'))
            @include('admin.approvals.components.assignment-queue')
        @endif
        @if($showsQueue('collaborator'))
            @include('admin.approvals.components.collaborator-queue')
        @endif
        @if($showsQueue('share'))
            @include('admin.approvals.components.share-queue')
        @endif
        @if($showsQueue('outgoing'))
            @include('admin.approvals.components.outgoing-queue')
        @endif
    </div>
</div>
@endsection
