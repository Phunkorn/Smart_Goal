@extends('layouts.app')

@section('title', $board->title)

@push('styles')
    @vite('resources/css/pages/workspace.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/workspace/index.js')
@endpush

@section('content')
{{--
    หน้าวาดของกระดานหนึ่งใบ

    ข้อความไทยทุกคำบนแถบเครื่องมือมาจาก App\Support\WorkspaceDesign ผ่าน Blade
    และ JSON island ไม่ใช่เขียนซ้ำในไฟล์ .js

    ในเฟสนี้การบันทึกยังเป็นการกดปุ่มเอง การบันทึกอัตโนมัติพร้อมการตรวจเวอร์ชัน
    ฝั่งหน้าจอจะเข้ามาในเฟสถัดไป endpoint ฝั่งเซิร์ฟเวอร์พร้อมแล้ว
--}}
@php
    $visibility = \App\Support\WorkspaceDesign::visibility($board->visibility);

    // ประกอบ payload ไว้ก่อนแล้วค่อยส่งให้ @json เป็นตัวแปรเดียว เพราะ @json ที่รับ
    // array literal หลายบรรทัดจะทำให้ Blade แปลงวงเล็บผิดตอน compile
    $boardIsland = [
        'id' => $board->id,
        'title' => $board->title,
        'visibility' => $board->visibility,
        'departmentId' => $board->department_id,
        'capabilities' => $capabilities,
        // เวอร์ชันที่หน้านี้โหลดมา ฝั่งเบราว์เซอร์ต้องส่งกลับมาทุกครั้งที่บันทึก
        // เพื่อให้เซิร์ฟเวอร์รู้ว่ามีใครบันทึกแทรกไปก่อนหรือไม่
        'version' => $documentVersion,
    ];

    $routesIsland = [
        'document' => route('workspace.boards.document', $board),
        'save' => route('workspace.boards.document.save', $board),
        'attachments' => $capabilities['canEdit']
            ? route('workspace.boards.attachments.store', $board)
            : null,
        'update' => $capabilities['canManageSettings']
            ? route('workspace.boards.update', $board)
            : null,
        'destroy' => $capabilities['canDelete']
            ? route('workspace.boards.destroy', $board)
            : null,
        'department' => route('workspace.department', $board->department_id),
    ];
@endphp

<div class="ws-board" data-workspace-board data-board-id="{{ $board->id }}">
    <script type="application/json" id="workspace-design">@json($design)</script>
    <script type="application/json" id="workspace-board">@json($boardIsland)</script>
    <script type="application/json" id="workspace-document">@json($document)</script>
    <script type="application/json" id="workspace-routes">@json($routesIsland)</script>

    <header class="ws-board__header">
        <div class="ws-board__identity">
            <nav class="ws-breadcrumb" aria-label="breadcrumb">
                <a href="{{ route('workspace.index') }}">กระดานไอเดีย</a>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <a href="{{ route('workspace.department', $board->department_id) }}">
                    {{ $board->department?->department_name ?? 'ไม่ระบุแผนก' }}
                </a>
            </nav>
            <h1 class="ws-board__title">{{ $board->title }}</h1>
        </div>

        <div class="ws-board__status">
            <span class="wsb-save wsb-save--teal" data-workspace-save-state data-state="saved">
                <i class="bi bi-cloud-check" aria-hidden="true"></i>
                <span data-workspace-save-label>{{ \App\Support\WorkspaceDesign::SAVE_STATES['saved']['label'] }}</span>
            </span>

            <button type="button" class="ws-btn ws-btn--ghost" data-workspace-refresh
                title="โหลดฉบับล่าสุดจากเซิร์ฟเวอร์">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                รีเฟรช
            </button>

            <span class="ws-chip ws-chip--{{ $visibility['tone'] }}">
                <i class="bi {{ $visibility['icon'] }}" aria-hidden="true"></i>
                {{ $visibility['label'] }}
            </span>

            {{--
                ปุ่มจัดการกระดานอยู่บนหน้าวาดด้วย ไม่ใช่เฉพาะในหน้ารายการ
                เพราะคนที่กำลังวาดอยู่คือคนที่รู้ว่าควรเปลี่ยนชื่อเมื่อไร
                การบังคับให้ย้อนกลับไปหน้ารายการเพื่อเปลี่ยนชื่อเป็นการขัดจังหวะ

                เงื่อนไขมาจาก policy ฝั่งเซิร์ฟเวอร์ ไม่ใช่การเช็ค role ใน Blade
            --}}
            @if ($capabilities['canManageSettings'])
                <button type="button" class="ws-btn ws-btn--ghost" data-workspace-board-settings
                    title="เปลี่ยนชื่อและการมองเห็น">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                    แก้ไขชื่อ
                </button>
            @endif

            @if ($capabilities['canDelete'])
                <button type="button" class="ws-btn ws-btn--ghost ws-btn--danger" data-workspace-board-delete
                    title="ลบกระดานนี้">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                    ลบกระดาน
                </button>
            @endif

            @unless ($capabilities['canEdit'])
                {{-- ป้ายนี้อธิบายว่าทำไมแถบเครื่องมือถึงใช้ไม่ได้ ผู้ใช้ที่มาจากแผนกอื่น
                     จะได้ไม่คิดว่าหน้าเสีย --}}
                <span class="ws-chip ws-chip--gray">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                    ดูอย่างเดียว
                </span>
            @endunless
        </div>
    </header>

    @include('workspace.components.board-toolbar')
    @include('workspace.components.board-stage')

    {{-- กล่องใบเดียวกับหน้ารายการ ควบคุมโดย board-settings.js --}}
    @include('workspace.components.board-settings-modal')
</div>
@endsection
