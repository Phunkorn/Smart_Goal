@extends('layouts.app')

@section('title', 'พนักงาน')

@push('styles')
    <style>
        .employee-page {
            max-width: 1440px;
            margin: 0 auto;
        }

        .employee-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-end;
            margin-bottom: 18px;
        }

        .employee-head h1 {
            margin: 0;
            font-size: 30px;
            font-weight: 850;
        }

        .employee-head p {
            margin: 6px 0 0;
            color: var(--text-muted);
        }

        .role-summary {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .summary-pill {
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 999px;
            padding: 8px 12px;
            font-weight: 850;
            color: var(--text-main);
        }

        .employee-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 16px;
        }

        .employee-search {
            min-width: 260px;
            flex: 1;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0 12px;
            min-height: 42px;
            background: var(--surface-2);
            color: var(--text-muted);
        }

        .employee-search input {
            width: 100%;
            border: 0;
            outline: 0;
            background: transparent;
            font: inherit;
        }

        .dept-chip {
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 8px 12px;
            background: #fff;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
        }

        .dept-chip.active {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
        }

        .employee-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .employee-card {
            display: grid;
            gap: 14px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            color: inherit;
            box-shadow: var(--shadow-sm);
        }

        .employee-profile {
            display: flex;
            gap: 12px;
            align-items: center;
            min-width: 0;
        }

        .employee-avatar {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: var(--accent-dim);
            color: var(--accent-strong);
            display: grid;
            place-items: center;
            font-weight: 800;
            overflow: hidden;
            flex: 0 0 auto;
        }

        .employee-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .employee-name {
            font-weight: 850;
            font-size: 16px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .employee-sub {
            color: var(--text-muted);
            font-size: 13px;
            margin-top: 2px;
        }

        .employee-meta {
            display: grid;
            gap: 8px;
            color: var(--text-muted);
            font-size: 13px;
        }

        .employee-meta span {
            display: flex;
            gap: 8px;
            align-items: center;
            min-width: 0;
        }

        .role-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: max-content;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 12px;
            font-weight: 800;
        }

        .role-pill.admin {
            background: var(--accent-dim);
            color: var(--accent-strong);
        }

        .role-pill.user {
            background: var(--green-dim);
            color: #00875A;
        }

        .role-pill.viewer {
            background: var(--blue-dim);
            color: #1A66D6;
        }

        .work-now {
            border-top: 1px solid var(--border);
            padding-top: 12px;
            font-size: 13px;
        }

        .work-label {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .work-title {
            font-weight: 700;
            color: var(--text);
            line-height: 1.5;
        }

        .card-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-add-employee,
        .mini-btn {
            min-height: 20px;
            border: 0;
            border-radius: 10px;
            padding: 0 13px;
            font-weight: 100;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-add-employee {
            background: var(--accent);
            color: #fff;
        }

        .mini-btn {
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text-main);
        }

        .mini-btn.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        .mini-btn.danger {
            background: #fff1f2;
            border-color: #fecdd3;
            color: #be123c;
        }

        .form-help {
            color: var(--text-muted);
            font-size: 12px;
            margin-top: 6px;
        }

        .profile-preview {
            width: 96px;
            height: 96px;
            border-radius: 18px;
            border: 1px dashed var(--border);
            background: var(--surface-2);
            display: none;
            object-fit: cover;
            margin-top: 10px;
        }

        @media (max-width: 1200px) {
            .employee-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .employee-head {
                align-items: flex-start;
                flex-direction: column;
            }

            .employee-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
                        <div style="min-width:0;">
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
                        <button type="button" class="mini-btn" data-bs-toggle="modal"
                            data-bs-target="#editUserModal{{ $employee->id }}"
                            style="background:#eaf3ff;border-color:#bfdbfe;color:#1a66d6;"><i class="bi bi-pencil-square"></i>
                            แก้ไข</button>
                        <button type="button" class="mini-btn" data-bs-toggle="modal"
                            data-bs-target="#resetPasswordModal{{ $employee->id }}"
                            style="background:#fffbeb;border-color:#fde68a;color:#b45309;"><i class="bi bi-key-fill"></i>
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
