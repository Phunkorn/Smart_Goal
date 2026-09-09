@extends('layouts.app')

@section('title', 'กระดานไอเดีย')

@push('styles')
    @vite('resources/css/pages/workspace.css')
@endpush

@push('scripts')
    @vite('resources/js/pages/workspace/board-list.js')
@endpush

@section('content')
{{--
    หน้ารวมกระดานไอเดีย — เลือกแผนกก่อนเข้าไปดูกระดาน

    กระดานผูกกับแผนกเพราะกติกาคือ "คนในแผนกเดียวกันแก้ได้ทุกคน แผนกอื่นดูได้
    อย่างเดียว" การเปิดหน้านี้ให้เห็นทุกแผนกจึงไม่ใช่การรั่วของข้อมูล จำนวน
    กระดานที่แสดงถูกกรองด้วย WorkspaceBoardQueryService::visibleQuery() แล้ว
    กระดานที่ตั้งเป็น "เฉพาะแผนก" จึงไม่ถูกนับให้คนนอกแผนกเห็น
--}}
<div class="ws-page" data-workspace-list>
    <script type="application/json" id="workspace-list-routes">@json(['store' => route('workspace.boards.store')])</script>

    <header class="ws-page__header">
        <div>
            <h1 class="ws-page__title">กระดานไอเดีย</h1>
            <p class="ws-page__subtitle">
                พื้นที่วาดเปล่าสำหรับระดมสมอง แต่ละแผนกมีกระดานของตัวเอง
                แผนกอื่นเข้ามาดูได้แต่แก้ไม่ได้
            </p>
        </div>

        @if ($canCreate && $ownDepartment)
            <button type="button" class="ws-btn ws-btn--primary"
                data-workspace-create
                data-department-id="{{ $ownDepartment->id }}"
                data-department-name="{{ $ownDepartment->department_name }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                สร้างกระดานใหม่
            </button>
        @endif
    </header>

    {{--
        ไม่มีส่วน "แก้ไขล่าสุด" อีกแล้ว

        หน้านี้ตอบคำถามเดียวคือ "จะเข้ากระดานของแผนกไหน" การเอากระดานล่าสุด
        มาวางไว้ข้างบนทำให้มีทางเข้าสองทางไปที่เดียวกัน ทั้งที่กระดานทุกใบ
        แสดงอยู่ในหน้าของแผนกนั้นเรียงตามเวลาแก้ไขล่าสุดอยู่แล้ว
    --}}
    <section class="ws-section" aria-labelledby="wsDepartmentHeading">
        <h2 class="ws-section__title" id="wsDepartmentHeading">กระดานตามแผนก</h2>

        <div class="ws-grid">
            @forelse ($summaries as $summary)
                @php($department = $summary['department'])
                <a class="ws-dept-card {{ $ownDepartment && $ownDepartment->id === $department->id ? 'ws-dept-card--own' : '' }}"
                    href="{{ route('workspace.department', $department) }}">
                    <span class="ws-dept-card__icon" aria-hidden="true"><i class="bi bi-easel"></i></span>
                    <span class="ws-dept-card__body">
                        <span class="ws-dept-card__name">{{ $department->department_name }}</span>
                        <span class="ws-dept-card__count">{{ $summary['board_count'] }} กระดาน</span>
                    </span>
                    @if ($ownDepartment && $ownDepartment->id === $department->id)
                        <span class="ws-badge ws-badge--own">แผนกของฉัน</span>
                    @endif
                </a>
            @empty
                <p class="ws-empty">ยังไม่มีแผนกในระบบ</p>
            @endforelse
        </div>
    </section>

    @include('workspace.components.board-settings-modal')
</div>
@endsection
