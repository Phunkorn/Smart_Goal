@extends('layouts.app')

@section('title', 'พนักงาน')

@push('styles')
    @vite('resources/css/pages/employees.css')
@endpush

@section('content')
    @php
        $roleMeta = [
            'admin' => ['label' => 'Admin', 'icon' => 'bi-shield-check', 'class' => 'admin'],
            'user' => ['label' => 'พนักงาน', 'icon' => 'bi-person-check', 'class' => 'user'],
            'viewer' => ['label' => 'ผู้เข้าชม', 'icon' => 'bi-eye', 'class' => 'viewer'],
        ];
        $currentDeptId = request()->filled('department_id') ? (int) request('department_id') : null;
        $filteredEmployees = $currentDeptId ? $employees->where('department_id', $currentDeptId)->values() : $employees;
    @endphp

    <div class="employee-page">
        <div class="employee-head">
            <div>
                <h1>พนักงาน</h1>
                <p>จัดการบัญชี สิทธิ์ แผนก และดูงานที่แต่ละคนรับผิดชอบอยู่</p>
                <div class="role-summary">
                    <span class="summary-pill"><i class="bi bi-shield-check"></i> Admin {{ $roleCounts['admin'] ?? 0 }}</span>
                    <span class="summary-pill"><i class="bi bi-eye"></i> Viewer {{ $roleCounts['viewer'] ?? 0 }}</span>
                    <span class="summary-pill"><i class="bi bi-person-check"></i> พนักงาน
                        {{ $roleCounts['user'] ?? 0 }}</span>
                    <span class="summary-pill"><i class="bi bi-people"></i> ทั้งหมด {{ $employees->count() }}</span>
                </div>
            </div>

            @if ($canManageEmployees)
                <button type="button" class="btn-add-employee" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="bi bi-person-plus-fill"></i> เพิ่มพนักงาน
                </button>
            @endif
        </div>

        <div class="employee-toolbar">
            <label class="employee-search">
                <i class="bi bi-search"></i>
                <input type="search" id="employeeSearchInput" placeholder="ค้นหาชื่อ อีเมล เบอร์โทร หรือแผนก"
                    oninput="filterEmployees(this.value)">
            </label>
            <a href="{{ route('employees.index') }}" class="dept-chip {{ !$currentDeptId ? 'active' : '' }}">ทั้งหมด
                {{ $employees->count() }}</a>
            @foreach ($departments as $department)
                <a href="{{ route('employees.index', ['department_id' => $department->id]) }}"
                    class="dept-chip {{ $currentDeptId === $department->id ? 'active' : '' }}">
                    {{ $department->department_name }} {{ $employees->where('department_id', $department->id)->count() }}
                </a>
            @endforeach
        </div>

        <div class="employee-grid">
            @forelse($filteredEmployees as $employee)
                @php
                    $latestJob = $employee->jobs->first();
                    $role = $roleMeta[$employee->role] ?? $roleMeta['user'];
                    $searchText = Str::lower(
                        $employee->name .
                            ' ' .
                            $employee->email .
                            ' ' .
                            $employee->phone .
                            ' ' .
                            optional($employee->department)->department_name .
                            ' ' .
                            $role['label'],
                    );
                @endphp
                <article class="employee-card" data-search="{{ $searchText }}">
                    <div class="employee-profile">
                        <div class="employee-avatar">
                            @if ($employee->profile_image)
                                <img src="{{ route('media.show', ['path' => $employee->profile_image]) }}"
                                    alt="{{ $employee->name }}">
                            @else
                                {{ mb_substr($employee->name, 0, 2) }}
                            @endif
                        </div>
                        <div class="employee-info">
                            <div class="employee-name">{{ $employee->name }}</div>
                            <div class="employee-sub">{{ optional($employee->department)->department_name ?? 'ไม่มีแผนก' }}
                            </div>
                        </div>
                    </div>

                    <span class="role-pill {{ $role['class'] }}"><i
                            class="bi {{ $role['icon'] }}"></i>{{ $role['label'] }}</span>

                    <div class="employee-meta">
                        <span><i class="bi bi-envelope"></i>{{ $employee->email }}</span>
                        <span><i class="bi bi-telephone"></i>{{ $employee->phone ?: '-' }}</span>
                    </div>

                    <div class="work-now">
                        <div class="work-label">กำลังทำอยู่</div>
                        <div class="work-title">{{ $latestJob?->job_topic ?? 'ยังไม่มีงานที่มอบหมาย' }}</div>
                    </div>

                    <div class="card-actions">
                        <a href="{{ route('employees.show', $employee->id) }}" class="mini-btn primary"><i
                                class="bi bi-eye"></i> ดู</a>
                        @if ($canManageEmployees)
                        <button type="button" class="mini-btn employee-action-edit" data-bs-toggle="modal"
                            data-bs-target="#editUserModal{{ $employee->id }}"
                            ><i class="bi bi-pencil-square"></i>
                            แก้ไข</button>
                        <button type="button" class="mini-btn employee-action-reset" data-bs-toggle="modal"
                            data-bs-target="#resetPasswordModal{{ $employee->id }}"
                            ><i class="bi bi-key-fill"></i>
                            รีเซ็ตรหัสผ่าน</button>
                            @if ($employee->id !== auth()->id())
                                <form method="POST" action="{{ route('employees.destroy', $employee->id) }}"
                                    class="delete-user-form">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="mini-btn danger"><i class="bi bi-trash"></i> ลบ</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </article>
            @empty
                <div class="panel text-center p-4">ยังไม่มีพนักงานในเงื่อนไขนี้</div>
            @endforelse
        </div>
    </div>

    @if ($canManageEmployees)
        @include('employees.partials.form-modal', [
            'modalId' => 'createUserModal',
            'mode' => 'create',
            'employee' => null,
        ])

        @foreach ($employees as $employee)
            @include('employees.partials.form-modal', [
                'modalId' => 'editUserModal' . $employee->id,
                'mode' => 'edit',
                'employee' => $employee,
            ])
            @include('employees.partials.reset-password-modal', [
                'employee' => $employee,
            ])
        @endforeach
    @endif
@endsection

@push('scripts')
    <script>
        function generateTempPassword(employeeId) {
            const words = ['SmartGoal', 'PremiumCare', 'SecureTeam'];
            const word = words[Math.floor(Math.random() * words.length)];
            const digits = crypto.getRandomValues(new Uint32Array(1))[0].toString().slice(-5);
            const input = document.getElementById('resetPasswordInput' + employeeId);
            const password = word + '!' + digits;
            if (input) input.value = password;
        }

        function filterEmployees(value) {
            const keyword = value.trim().toLowerCase();
            document.querySelectorAll('.employee-card[data-search]').forEach((card) => {
                card.style.display = card.dataset.search.includes(keyword) ? '' : 'none';
            });
        }

        function syncEmployeeRoleFields(scope) {
            const role = scope.querySelector('[data-user-role]');
            const department = scope.querySelector('[data-user-department]');
            if (!role || !department) return;
            const needsDepartment = role.value === 'user';
            department.disabled = !needsDepartment;
            department.required = needsDepartment;
            if (!needsDepartment) department.value = '';
        }

        document.querySelectorAll('[data-employee-form]').forEach((form) => {
            syncEmployeeRoleFields(form);
            form.querySelector('[data-user-role]')?.addEventListener('change', () => syncEmployeeRoleFields(form));
            form.querySelector('[data-profile-input]')?.addEventListener('change', function() {
                const preview = form.querySelector('[data-profile-preview]');
                const file = this.files?.[0];
                if (!preview || !file) {
                    if (preview) preview.style.display = 'none';
                    return;
                }
                preview.src = URL.createObjectURL(file);
                preview.style.display = 'block';
            });
        });

        document.querySelectorAll('.delete-user-form').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const confirm = await Swal.fire({
                    icon: 'warning',
                    title: 'ยืนยันลบพนักงาน?',
                    text: 'บัญชีนี้จะถูกลบออกจากระบบ',
                    showCancelButton: true,
                    confirmButtonText: 'ลบ',
                    cancelButtonText: 'ยกเลิก'
                });
                if (confirm.isConfirmed) form.submit();
            });
        });

        @if (session('success'))
            Swal.fire({
                icon: 'success',
                title: 'สำเร็จ',
                text: @json(session('success')),
                confirmButtonText: 'ตกลง'
            });
        @endif

        @if ($errors->any())
            Swal.fire({
                icon: 'error',
                title: 'ไม่สำเร็จ',
                html: @json($errors->first()),
                confirmButtonText: 'ตกลง'
            });
        @endif
    </script>
@endpush



