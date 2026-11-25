@extends('layouts.app')

@section('title', 'บอร์ดทุกแผนก')

@push('styles')
    @vite('resources/css/pages/work-board.css')
@endpush

@section('content')
<div class="work-board-page work-board-directory-page">
    <nav class="wb-breadcrumb" aria-label="breadcrumb"><span>บอร์ดงาน</span><i class="bi bi-chevron-right"></i><strong>บอร์ดทุกแผนก</strong></nav>

    <header class="wb-page-head">
        <div>
            <h1>บอร์ดทุกแผนก</h1>
            <p>ภาพรวมแผนกและสมาชิกในองค์กร</p>
        </div>
    </header>

    <section aria-labelledby="wb-departments-title">
        <div class="wb-section-head">
            <h2 id="wb-departments-title">แผนกทั้งหมด</h2>
            <p>{{ number_format($departments->count()) }} แผนก · {{ number_format($departments->sum('member_count')) }} สมาชิก</p>
        </div>

        <div class="wb-department-grid">
            @forelse($departments as $department)
                @include('work-board.components.department-card', ['department' => $department])
            @empty
                <div class="wb-department-empty">
                    <div class="wb-empty"><i class="bi bi-building"></i><h3>ยังไม่มีข้อมูลแผนก</h3><p>เมื่อเพิ่มแผนกแล้ว รายการจะแสดงที่นี่โดยอัตโนมัติ</p></div>
                </div>
            @endforelse
        </div>
    </section>
</div>
@endsection
