@extends('layouts.app')

@section('title', $isAdminViewer ?? true ? 'คำขออนุมัติ' : 'งานข้ามแผนกของทีม')

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
     *
     * บทบาทของหน้านี้ต่างกันตาม role และต้องสื่อให้ตรง:
     *
     *   admin        — กล่องขาเข้าของคำขอที่ไม่มีหัวหน้าแผนกรับผิดชอบ เรียงทุกคิวใน "ทั้งหมด"
     *                  เหมือนเดิมทุกอย่าง รวมถึงการ์ดสรุปและสถานะว่าง
     *
     *   หัวหน้าแผนก  — หลังจากผู้เข้าร่วมโปรเจกต์ไม่ต้องอนุมัติอีกแล้ว หน้านี้คือหน้า
     *                  "ติดตามงานข้ามแผนกของทีม" เป็นหลัก คิวอนุมัติที่ว่างจึงไม่ถูกวาดเลย
     *                  (ไม่ใช่วาดการ์ดว่างทิ้งไว้ให้เข้าใจผิดว่ายังต้องมากดอะไร) และจะโผล่
     *                  ขึ้นมาพร้อมแถบแจ้งเตือนด้านบนเฉพาะเมื่อมีของค้างจริง
     *
     * ไม่มีแท็บ "ผู้ร่วมงานข้ามแผนก" อีกแล้ว — ผู้เข้าร่วมโปรเจกต์เข้าร่วมได้ทันที
     * ไม่ว่าจะข้ามแผนกหรือไม่ (CollaboratorInvitationService)
     */
    $pendingTotal = $approvalCounts['total'];
    $trackingCount = $outgoingCollaborations->count();
    $hasPendingInQueue = ['assignment' => $approvalCounts['assignments'] > 0, 'share' => $approvalCounts['shares'] > 0];

    // หัวหน้าแผนกใช้หน้านี้เป็นหน้าติดตาม จึงยกแท็บติดตามขึ้นมาก่อน และซ่อนคิวอนุมัติที่ว่าง
    $queueTabs = $isAdminViewer
        ? [
            'all' => ['ทั้งหมด', $pendingTotal],
            'assignment' => ['งานข้ามแผนก', $approvalCounts['assignments']],
            'share' => ['ผู้ขอร่วมงานจากการแชร์งาน', $approvalCounts['shares']],
            'outgoing' => ['งานข้ามแผนกของทีม', $trackingCount],
        ]
        : array_filter([
            'outgoing' => ['งานข้ามแผนกของทีม', $trackingCount],
            'assignment' => $hasPendingInQueue['assignment'] ? ['งานข้ามแผนกรออนุมัติ', $approvalCounts['assignments']] : null,
            'share' => $hasPendingInQueue['share'] ? ['ผู้ขอร่วมงานจากการแชร์งาน', $approvalCounts['shares']] : null,
        ]);

    $selectedQueue = fn (string $queue): bool => $approvalQueue === $queue
        || ($approvalQueue === 'all' && $queue !== 'outgoing');

    /*
     * คิวอนุมัติที่ว่างยังถูกวาดให้ admin (สถานะว่างเป็นข้อมูลของกล่องขาเข้า) แต่ไม่วาด
     * ให้หัวหน้าแผนก เพราะหน้าของเขาไม่ใช่กล่องขาเข้าแล้ว ส่วนแท็บติดตามต้องวาดเสมอ
     * แม้ยังไม่มีใครไปร่วมงานข้ามแผนก เพราะนั่นคือเนื้อหาหลักของหน้า
     */
    $showsQueue = fn (string $queue): bool => $selectedQueue($queue)
        && ($isAdminViewer || $queue === 'outgoing' || $hasPendingInQueue[$queue]);

    $tracksOnly = ! $isAdminViewer && $pendingTotal === 0;
@endphp
<div class="admin-approvals-page">
    <header class="admin-approvals-header">
        @if($isAdminViewer)
            <span class="admin-approvals-eyebrow">ADMIN APPROVALS</span>
            <h1>คำขออนุมัติ</h1>
            <p>คำขอข้ามแผนกทั้งหมดที่รอการตัดสินใจ — ขอบเขต {{ $approvalScopeLabel }}</p>
        @else
            <span class="admin-approvals-eyebrow">CROSS-DEPARTMENT TRACKING</span>
            <h1>งานข้ามแผนกของทีม</h1>
            <p>
                ติดตามว่าพนักงาน{{ $approvalScopeLabel }}กำลังเข้าร่วมโปรเจกต์ของแผนกใด
                — การเข้าร่วมเสร็จทันทีตั้งแต่หัวหน้าโปรเจกต์กดเพิ่ม คุณไม่ต้องกดอนุมัติ
            </p>
        @endif
    </header>

    @if($isAdminViewer)
        <section class="admin-approvals-summary" aria-label="สรุปคำขออนุมัติ">
            <article class="admin-approvals-summary__card admin-approvals-summary__card--total">
                <span>รอทั้งหมด</span>
                <strong>{{ $pendingTotal }}</strong>
                <i class="bi bi-inbox-fill" aria-hidden="true"></i>
            </article>
            <article class="admin-approvals-summary__card">
                <span>งานข้ามแผนก</span>
                <strong>{{ $approvalCounts['assignments'] }}</strong>
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
            </article>
            <article class="admin-approvals-summary__card">
                <span>ผู้ขอร่วมงานจากการแชร์งาน</span>
                <strong>{{ $approvalCounts['shares'] }}</strong>
                <i class="bi bi-share-fill" aria-hidden="true"></i>
            </article>
        </section>
    @elseif($pendingTotal > 0)
        {{--
            ของค้างของหัวหน้าแผนกเป็นกรณียกเว้น ไม่ใช่เนื้อหาหลักของหน้า จึงบอกด้วยแถบเดียว
            พร้อมลิงก์ไปที่คิวนั้นตรง ๆ แทนการ์ดสรุปสามใบที่ปกติเป็นศูนย์ทั้งแถว
        --}}
        <aside class="admin-approvals-pending" role="status">
            <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
            <p>มี <strong>{{ $pendingTotal }}</strong> รายการรอคุณตัดสินใจ</p>
            <a href="{{ route('admin.approvals.index', ['approval_queue' => $hasPendingInQueue['assignment'] ? 'assignment' : 'share']) }}">
                ดูคำขอ <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </aside>
    @endif

    {{-- แท็บเดียวไม่ต้องมีแถบแท็บ เพราะไม่มีอะไรให้สลับไป --}}
    @if(count($queueTabs) > 1)
        <nav class="admin-approvals-tabs" aria-label="ประเภทคำขออนุมัติ">
            @foreach($queueTabs as $queue => [$label, $count])
                <a href="{{ route('admin.approvals.index', $queue === 'all' ? [] : ['approval_queue' => $queue]) }}"
                    class="{{ $approvalQueue === $queue ? 'is-active' : '' }} {{ $queue === 'outgoing' ? 'admin-approvals-tabs__outgoing' : '' }}"
                    @if($approvalQueue === $queue) aria-current="page" @endif>
                    {{ $label }} <span>{{ $count }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    <div class="admin-approvals-queues">
        @if($showsQueue('assignment'))
            @include('admin.approvals.components.assignment-queue')
        @endif
        @if($showsQueue('share'))
            @include('admin.approvals.components.share-queue')
        @endif
        @if($showsQueue('outgoing'))
            {{-- หัวเรื่องของหน้าเป็นหัวเรื่องของการติดตามอยู่แล้ว จึงไม่วาดซ้ำในการ์ด --}}
            @include('admin.approvals.components.outgoing-queue', ['showsOutgoingHeading' => ! $tracksOnly])
        @endif
    </div>
</div>
@endsection
