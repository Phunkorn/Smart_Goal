@extends('layouts.app')

@php
    $accountContext = $accountContext ?? 'employee';
    $isSystemAccounts = $accountContext === 'system';
@endphp

@section('title', $isSystemAccounts ? 'บัญชีระบบ' : 'พนักงาน')

@push('styles')
    @vite('resources/css/pages/employees.css')
@endpush

@section('content')
    @php
        $roleMeta = [
            'admin' => ['label' => 'Admin', 'icon' => 'bi-shield-check', 'class' => 'admin'],
            'user' => ['label' => 'พนักงาน', 'icon' => 'bi-person-check', 'class' => 'user'],
            'department_head' => ['label' => 'หัวหน้าแผนก', 'icon' => 'bi-person-badge', 'class' => 'department-head'],
            'viewer' => ['label' => 'ผู้เข้าชม', 'icon' => 'bi-eye', 'class' => 'viewer'],
        ];
        $currentDeptId = request()->filled('department_id') ? (int) request('department_id') : null;
        $filteredEmployees = ! $isSystemAccounts && $currentDeptId
            ? $employees->where('department_id', $currentDeptId)->values()
            : $employees;
    @endphp

    <div class="employee-page" data-employee-page
        data-account-context="{{ $accountContext }}"
        data-success-message="{{ session('success') }}"
        data-error-message="{{ $errors->first() }}"
        data-open-modal="{{ old('_employee_form_modal') }}">
        <header class="employee-page__header">
            <div>
                <span class="eyebrow">{{ $isSystemAccounts ? 'การกำกับสิทธิ์ระบบ' : 'จัดการบุคลากร' }}</span>
                <h1>{{ $isSystemAccounts ? 'บัญชีระบบ' : 'พนักงาน' }}</h1>
                <p>{{ $isSystemAccounts ? 'จัดการบัญชีผู้ดูแลระบบและผู้เข้าชม โดยแยกออกจากบัญชีพนักงาน' : 'ดูข้อมูลบัญชี สิทธิ์ แผนก และงานที่แต่ละคนรับผิดชอบ' }}</p>
            </div>

            @if ($canManageEmployees)
                <button type="button" class="employee-button employee-button--primary" data-bs-toggle="modal"
                    data-bs-target="#createUserModal">
                    <i class="bi bi-person-plus" aria-hidden="true"></i>
                    {{ $isSystemAccounts ? 'เพิ่มบัญชีระบบ' : 'เพิ่มพนักงาน' }}
                </button>
            @endif
        </header>

        <section class="employee-toolbar" aria-label="ค้นหาและกรองพนักงาน">
            <label class="employee-search" for="employeeSearchInput">
                <span>ค้นหา</span>
                <span class="employee-search__control">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="employeeSearchInput" data-employee-search
                        placeholder="ชื่อ บัญชีผู้ใช้งาน อีเมล เบอร์โทร หรือแผนก" autocomplete="off">
                </span>
            </label>

            @unless($isSystemAccounts)
            <nav class="employee-department-filter" aria-label="กรองตามแผนก">
                <span class="employee-department-filter__label">แผนก</span>
                <div class="employee-department-filter__options">
                    <a href="{{ route('employees.index') }}" class="employee-filter-chip {{ !$currentDeptId ? 'is-active' : '' }}"
                        @if(!$currentDeptId) aria-current="page" @endif>
                        ทั้งหมด <span>{{ $employees->count() }}</span>
                    </a>
                    @foreach ($departments as $department)
                        <a href="{{ route('employees.index', ['department_id' => $department->id]) }}"
                            class="employee-filter-chip {{ $currentDeptId === $department->id ? 'is-active' : '' }}"
                            @if($currentDeptId === $department->id) aria-current="page" @endif>
                            {{ $department->department_name }}
                            <span>{{ $employees->where('department_id', $department->id)->count() }}</span>
                        </a>
                    @endforeach
                </div>
            </nav>
            @endunless
        </section>

        <div class="employee-results-meta" aria-live="polite">
            <span>แสดง <strong data-employee-visible-count>{{ $filteredEmployees->count() }}</strong> บัญชี</span>
        </div>

        <div class="employee-grid" data-employee-grid>
            @forelse($filteredEmployees as $employee)
                @php
                    $role = $employee->isDepartmentHead()
                        ? $roleMeta['department_head']
                        : ($roleMeta[$employee->role] ?? $roleMeta['user']);
                    $searchText = Str::lower(implode(' ', array_filter([
                        $employee->name,
                        $employee->username,
                        $employee->email,
                        $employee->phone,
                        optional($employee->department)->department_name,
                        $role['label'],
                    ])));
                @endphp
                <article class="employee-card" data-employee-card data-employee-role="{{ $employee->role }}" data-search="{{ $searchText }}">
                    <div class="employee-card__header">
                        <div class="employee-profile">
                            <div class="employee-avatar">
                                @if ($employee->profile_image)
                                    <img src="{{ route('media.profile', $employee) }}"
                                        alt="รูปโปรไฟล์ของ {{ $employee->name }}">
                                @else
                                    {{ mb_substr($employee->name, 0, 2) }}
                                @endif
                            </div>
                            <div class="employee-profile__identity">
                                <h2 title="{{ $employee->name }}">{{ $employee->name }}</h2>
                                @if($isSystemAccounts)
                                    <p>{{ $role['label'] }}</p>
                                @else
                                    <p class="employee-department"><i class="bi bi-building" aria-hidden="true"></i><span>แผนก:</span> {{ optional($employee->department)->department_name ?? 'ไม่ได้ระบุ' }}</p>
                                @endif
                            </div>
                        </div>
                        <span class="employee-status {{ $employee->is_active ? 'is-active' : 'is-inactive' }}">
                            <span aria-hidden="true"></span>{{ $employee->is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}
                        </span>
                    </div>

                    <div class="employee-card__badges">
                        <span class="employee-role employee-role--{{ $role['class'] }}">
                            <i class="bi {{ $role['icon'] }}" aria-hidden="true"></i>{{ $role['label'] }}
                        </span>
                        @if($employee->must_change_password)
                            <span class="employee-password-state"><i class="bi bi-key" aria-hidden="true"></i>รอตั้งรหัสผ่านใหม่</span>
                        @endif
                    </div>

                    <dl class="employee-meta">
                        <div><dt><i class="bi bi-person-badge" aria-hidden="true"></i>บัญชีผู้ใช้งาน</dt><dd>{{ $employee->username }}</dd></div>
                        <div><dt><i class="bi bi-envelope" aria-hidden="true"></i>Email</dt><dd>{{ $employee->email ?: 'ไม่ได้ระบุ' }}</dd></div>
                        <div><dt><i class="bi bi-telephone" aria-hidden="true"></i>โทรศัพท์</dt><dd>{{ $employee->phone ?: 'ไม่ได้ระบุ' }}</dd></div>
                    </dl>

                    @if ($canManageEmployees)
                        <div class="employee-card__actions">
                            <button type="button" class="employee-action employee-action--edit" data-bs-toggle="modal"
                                data-bs-target="#editUserModal{{ $employee->id }}">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>แก้ไข
                            </button>
                            <button type="button" class="employee-action employee-action--reset" data-bs-toggle="modal"
                                data-bs-target="#resetPasswordModal{{ $employee->id }}">
                                <i class="bi bi-key" aria-hidden="true"></i>รีเซ็ตรหัสผ่าน
                            </button>
                            @if ($employee->id !== auth()->id())
                                <form method="POST" action="{{ route($isSystemAccounts ? 'admin.accounts.destroy' : 'employees.destroy', $employee->id) }}"
                                    class="employee-delete-form" data-employee-name="{{ $employee->name }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="employee-action employee-action--delete">
                                        <i class="bi bi-trash" aria-hidden="true"></i>ลบ
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <div class="employee-empty-state">
                    <i class="bi bi-people" aria-hidden="true"></i>
                    <h2>{{ $isSystemAccounts ? 'ยังไม่มีบัญชีระบบ' : 'ยังไม่มีพนักงานในเงื่อนไขนี้' }}</h2>
                    <p>{{ $isSystemAccounts ? 'เพิ่มบัญชี Admin หรือผู้เข้าชมเพื่อเริ่มต้นใช้งาน' : 'ลองเลือกแผนกอื่น หรือเพิ่มบัญชีพนักงานใหม่' }}</p>
                </div>
            @endforelse
        </div>

        <div class="employee-empty-state" data-employee-search-empty hidden>
            <i class="bi bi-search" aria-hidden="true"></i>
            <h2>{{ $isSystemAccounts ? 'ไม่พบบัญชีระบบที่ค้นหา' : 'ไม่พบพนักงานที่ค้นหา' }}</h2>
            <p>ลองตรวจคำค้น หรือค้นหาด้วยชื่อ บัญชีผู้ใช้งาน อีเมล หรือเบอร์โทร</p>
        </div>
    </div>

    @if ($canManageEmployees)
        @include('employees.partials.form-modal', [
            'modalId' => 'createUserModal',
            'mode' => 'create',
            'employee' => null,
            'accountContext' => $accountContext,
        ])

        @foreach ($employees as $employee)
            @include('employees.partials.form-modal', [
                'modalId' => 'editUserModal' . $employee->id,
                'mode' => 'edit',
                'employee' => $employee,
                'accountContext' => $accountContext,
            ])
            @include('employees.partials.reset-password-modal', [
                'employee' => $employee,
                'accountContext' => $accountContext,
            ])
        @endforeach
    @endif
@endsection

@push('scripts')
    @vite('resources/js/pages/employees/index.js')
@endpush
