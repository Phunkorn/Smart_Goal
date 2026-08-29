@extends('layouts.app')

@section('title', 'บอร์ดทุกแผนก')

@push('styles')
    @vite('resources/css/pages/work-board.css')
@endpush

@section('content')
<div class="work-board-page">
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
            <p>{{ number_format($departments->count()) }} แผนกในระบบ</p>
        </div>

        <div class="wb-department-grid">
            @forelse($departments as $department)
                <article class="wb-department-card wb-dept-{{ $department->board_tone }}">
                    <div class="wb-department-card__head">
                        <span class="wb-department-mark" aria-hidden="true">{{ $department->board_code }}</span>
                        <div class="wb-department-card__identity">
                            <h3>{{ $department->department_name }}</h3>
                            <span>ข้อมูลล่าสุดจากระบบ Smart Goal</span>
                        </div>
                    </div>
                    <div class="wb-department-card__foot">
                        <p class="wb-department-card__members"><strong>{{ number_format($department->member_count) }}</strong> สมาชิก</p>
                        <a class="wb-detail-link" href="{{ route('work-board.department', $department) }}">ดูรายละเอียด <i class="bi bi-arrow-right"></i></a>
                    </div>
                </article>
            @empty
                <div class="wb-department-empty">
                    <div class="wb-empty"><i class="bi bi-building"></i><h3>ยังไม่มีข้อมูลแผนก</h3><p>เมื่อเพิ่มแผนกแล้ว รายการจะแสดงที่นี่โดยอัตโนมัติ</p></div>
                </div>
            @endforelse
        </div>
    </section>
</div>
@endsection
