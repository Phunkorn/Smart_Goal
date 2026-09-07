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

    @if ($recentBoards->isNotEmpty())
        <section class="ws-section" aria-labelledby="wsRecentHeading">
            <h2 class="ws-section__title" id="wsRecentHeading">แก้ไขล่าสุด</h2>

            <div class="ws-recent">
                @foreach ($recentBoards as $board)
                    @php($visibility = \App\Support\WorkspaceDesign::visibility($board->visibility))
                    <a class="ws-recent__item" href="{{ route('workspace.boards.show', $board) }}">
                        <span class="ws-recent__name">{{ $board->title }}</span>
                        <span class="ws-recent__meta">
                            <i class="bi {{ $visibility['icon'] }}" aria-hidden="true"></i>
                            {{ $board->department?->department_name ?? 'ไม่ระบุแผนก' }}
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

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
