@extends('layouts.app')

@section('title', 'กระดานไอเดีย · '.$department->department_name)

@push('styles')
    @vite('resources/css/pages/workspace.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/workspace/board-list.js')
@endpush

@section('content')
{{--
    กระดานทั้งหมดของหนึ่งแผนก

    คนนอกแผนกเปิดหน้านี้ได้ แต่จะเห็นเฉพาะกระดานที่ตั้งเป็น "ทั้งองค์กร" และไม่มี
    ปุ่มสร้าง เพราะ $canCreate มาจาก policy ฝั่งเซิร์ฟเวอร์ ไม่ใช่การเช็ค role
    ใน Blade ส่วนปุ่มแก้ไข/ลบของแต่ละใบอยู่ใน board-card ซึ่งเช็คทีละกระดาน
--}}
<div class="ws-page" data-workspace-list>
    <script type="application/json" id="workspace-list-routes">@json(['store' => route('workspace.boards.store')])</script>

    <header class="ws-page__header">
        <div>
            <nav class="ws-breadcrumb" aria-label="breadcrumb">
                <a class="ws-breadcrumb__back" href="{{ route('workspace.index') }}">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    กระดานไอเดีย
                </a>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span>{{ $department->department_name }}</span>
            </nav>
            <h1 class="ws-page__title">{{ $department->department_name }}</h1>
            <p class="ws-page__subtitle">
                @if ($isOwnDepartment)
                    ทุกคนในแผนกนี้เข้ามาวาดและแก้ไขกระดานร่วมกันได้
                @else
                    คุณเปิดดูกระดานของแผนกนี้ได้ แต่แก้ไขไม่ได้
                @endif
            </p>
        </div>

        @if ($canCreate)
            <button type="button" class="ws-btn ws-btn--primary"
                data-workspace-create
                data-department-id="{{ $department->id }}"
                data-department-name="{{ $department->department_name }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                สร้างกระดานใหม่
            </button>
        @endif
    </header>

    @if ($boards->isNotEmpty())
        <p class="ws-summary">
            <strong>กระดานทั้งหมด</strong>
            <span>{{ $boards->count() }} กระดาน</span>
        </p>
    @endif

    <div class="ws-grid ws-grid--boards">
        @forelse ($boards as $board)
            @include('workspace.components.board-card', ['board' => $board])
        @empty
            <p class="ws-empty">
                @if ($isOwnDepartment)
                    ยังไม่มีกระดานในแผนกนี้ กดปุ่ม "สร้างกระดานใหม่" เพื่อเริ่มระดมสมอง
                @else
                    แผนกนี้ยังไม่มีกระดานที่เปิดให้แผนกอื่นดู
                @endif
            </p>
        @endforelse

        {{-- ทางลัดสร้างกระดาน ใช้ hook และปุ่มจัดการเดียวกับปุ่มบนหัวหน้า (board-list.js) --}}
        @if ($canCreate && $boards->isNotEmpty())
            <button type="button" class="ws-board-new"
                data-workspace-create
                data-department-id="{{ $department->id }}"
                data-department-name="{{ $department->department_name }}">
                <span class="ws-board-new__icon" aria-hidden="true"><i class="bi bi-plus-lg"></i></span>
                <span class="ws-board-new__title">สร้างกระดานใหม่</span>
                <span class="ws-board-new__hint">เริ่มต้นไอเดียของคุณ</span>
            </button>
        @endif
    </div>

    @include('workspace.components.board-settings-modal')
</div>
@endsection
