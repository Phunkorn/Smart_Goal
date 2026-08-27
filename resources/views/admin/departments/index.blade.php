@extends('layouts.app')

@section('title', 'แผนกทั้งหมด')

@push('styles')
    @vite('resources/css/pages/admin-departments.css')
@endpush

@section('content')
<div class="departments-page">
    <header class="departments-page__head">
        <div>
            <h1>แผนกทั้งหมด</h1>
            <p>ภาพรวมแผนกและสมาชิกในองค์กร</p>
        </div>
        <button type="button" class="departments-create" data-bs-toggle="modal" data-bs-target="#createDepartmentModal">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> สร้างแผนกใหม่
        </button>
    </header>

    <div class="departments-grid">
        @forelse ($departments as $department)
            @php($canDelete = $department->users_count === 0 && $department->jobs_count === 0)
            <article class="department-card">
                <div class="department-card__head">
                    <span class="department-card__mark" aria-hidden="true">{{ $department->board_code }}</span>
                    <div class="department-card__identity">
                        <h2>{{ $department->department_name }}</h2>
                        <p>ข้อมูลล่าสุดจากระบบ Smart Goal</p>
                    </div>
                    <div class="department-card__manage">
                        <button type="button" class="department-card__icon-button" data-bs-toggle="modal"
                            data-bs-target="#editDepartmentModal{{ $department->id }}"
                            aria-label="แก้ไขแผนก {{ $department->department_name }}">
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                        </button>
                        <form method="POST" action="{{ route('admin.departments.destroy', $department) }}"
                            class="delete-department-form" data-department-name="{{ $department->department_name }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="department-card__icon-button department-card__icon-button--danger"
                                title="{{ $canDelete ? 'ลบแผนก' : 'ลบไม่ได้เนื่องจากยังมีข้อมูลเชื่อมโยง' }}"
                                aria-label="ลบแผนก {{ $department->department_name }}">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                </div>

                @unless ($canDelete)
                    <p class="department-card__locked">ลบไม่ได้เนื่องจากยังมีข้อมูลเชื่อมโยง</p>
                @endunless

                <div class="department-card__foot">
                    <p class="department-card__members">
                        <strong>{{ number_format($department->member_count) }}</strong> สมาชิก
                    </p>
                    <a class="department-card__detail" href="{{ route('admin.work-board.department', $department) }}">
                        ดูรายละเอียด
                    </a>
                </div>
            </article>
        @empty
            <div class="departments-empty">
                <h2>ยังไม่มีแผนกในระบบ</h2>
                <p>เมื่อสร้างแผนกแล้ว รายการจะแสดงที่นี่โดยอัตโนมัติ</p>
            </div>
        @endforelse
    </div>
</div>

<div class="modal fade" id="createDepartmentModal" tabindex="-1" aria-labelledby="createDepartmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="{{ route('admin.departments.store') }}">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title fs-5 fw-bold" id="createDepartmentModalLabel">สร้างแผนกใหม่</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <label for="createDepartmentName" class="form-label fw-bold">ชื่อแผนก</label>
                    <input type="text" id="createDepartmentName" name="department_name" class="form-control"
                        value="{{ old('department_name') }}" maxlength="255" required autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

@foreach ($departments as $department)
    <div class="modal fade" id="editDepartmentModal{{ $department->id }}" tabindex="-1"
        aria-labelledby="editDepartmentModalLabel{{ $department->id }}" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <form method="POST" action="{{ route('admin.departments.update', $department) }}">
                    @csrf
                    @method('PATCH')
                    <div class="modal-header">
                        <h2 class="modal-title fs-5 fw-bold" id="editDepartmentModalLabel{{ $department->id }}">แก้ไขชื่อแผนก</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body">
                        <label for="editDepartmentName{{ $department->id }}" class="form-label fw-bold">ชื่อแผนก</label>
                        <input type="text" id="editDepartmentName{{ $department->id }}" name="department_name"
                            class="form-control" value="{{ $department->department_name }}" maxlength="255" required autocomplete="off">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach
@endsection

@push('scripts')
<script>
    document.querySelectorAll('.delete-department-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const departmentName = form.dataset.departmentName ?? '';
            const confirmation = await Swal.fire({
                icon: 'warning',
                title: 'ยืนยันการลบแผนก',
                text: `ต้องการลบแผนก “${departmentName}” หรือไม่? การลบทำได้เฉพาะแผนกที่ไม่มีพนักงานและไม่มีข้อมูลงาน`,
                showCancelButton: true,
                confirmButtonText: 'ยืนยันลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
            });

            if (confirmation.isConfirmed) form.submit();
        });
    });

    @if (session('success'))
        Swal.fire({
            icon: 'success',
            title: 'สำเร็จ',
            text: @json(session('success')),
            confirmButtonText: 'ตกลง',
        });
    @endif

    @if ($errors->any())
        Swal.fire({
            icon: 'error',
            title: 'ดำเนินการไม่สำเร็จ',
            text: @json($errors->first()),
            confirmButtonText: 'ตกลง',
        });
    @endif
</script>
@endpush
