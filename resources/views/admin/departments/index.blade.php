@extends('layouts.app')

@section('title', 'จัดการแผนก')

@section('content')
<div class="container-fluid px-0">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h2 fw-bold mb-1">จัดการแผนก</h1>
            <p class="text-muted mb-0">เพิ่ม แก้ไข และตรวจสอบการใช้งานแผนกในระบบ Smart Goal</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createDepartmentModal">
            <i class="bi bi-plus-lg me-1"></i> เพิ่มแผนก
        </button>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-1"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> ไม่สามารถบันทึกข้อมูลได้</div>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="px-4 py-3">ชื่อแผนก</th>
                            <th scope="col" class="text-center py-3">พนักงาน</th>
                            <th scope="col" class="text-center py-3">WorkOrder</th>
                            <th scope="col" class="text-end px-4 py-3">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($departments as $department)
                            @php($canDelete = $department->users_count === 0 && $department->jobs_count === 0)
                            <tr>
                                <td class="px-4">
                                    <div class="fw-bold">{{ $department->department_name }}</div>
                                    <small class="text-muted">รหัสแผนก #{{ $department->id }}</small>
                                </td>
                                <td class="text-center">
                                    <span class="badge text-bg-light border">{{ $department->users_count }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge text-bg-light border">{{ $department->jobs_count }}</span>
                                </td>
                                <td class="text-end px-4">
                                    <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                            data-bs-target="#editDepartmentModal{{ $department->id }}"
                                            aria-label="แก้ไขแผนก {{ $department->department_name }}">
                                            <i class="bi bi-pencil-square"></i> แก้ไข
                                        </button>
                                        <form method="POST" action="{{ route('admin.departments.destroy', $department) }}"
                                            onsubmit="return confirm('ยืนยันการลบแผนกนี้?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" @disabled(! $canDelete)
                                                title="{{ $canDelete ? 'ลบแผนก' : 'ยังมีพนักงานหรือข้อมูลงานเชื่อมโยงอยู่' }}"
                                                aria-label="ลบแผนก {{ $department->department_name }}">
                                                <i class="bi bi-trash"></i> ลบ
                                            </button>
                                        </form>
                                    </div>
                                    @unless ($canDelete)
                                        <small class="d-block text-muted mt-1">ลบไม่ได้เนื่องจากยังมีข้อมูลเชื่อมโยง</small>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-5">
                                    <i class="bi bi-diagram-3 fs-2 d-block mb-2"></i>
                                    ยังไม่มีแผนกในระบบ
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="createDepartmentModal" tabindex="-1" aria-labelledby="createDepartmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="{{ route('admin.departments.store') }}">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title fs-5 fw-bold" id="createDepartmentModalLabel">
                        <i class="bi bi-plus-circle me-1"></i> เพิ่มแผนก
                    </h2>
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
                        <h2 class="modal-title fs-5 fw-bold" id="editDepartmentModalLabel{{ $department->id }}">
                            <i class="bi bi-pencil-square me-1"></i> แก้ไขชื่อแผนก
                        </h2>
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
