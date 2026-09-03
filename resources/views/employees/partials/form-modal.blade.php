@php
    $isEdit = $mode === 'edit';
    $accountContext = $accountContext ?? 'employee';
    $isSystemAccount = $accountContext === 'system';
    $formAction = $isEdit
        ? route($isSystemAccount ? 'admin.accounts.update' : 'employees.update', $employee->id)
        : route($isSystemAccount ? 'admin.accounts.store' : 'employees.store');
    $useOldValues = old('_employee_form_modal') === $modalId;
    $value = fn (string $field, mixed $default = '') => $useOldValues ? old($field, $default) : $default;
    $selectedRole = $value('role', $employee->role ?? ($isSystemAccount ? 'admin' : 'user'));
    if (! $useOldValues && ($employee?->is_department_head ?? false)) $selectedRole = 'department_head';
    $selectedDepartment = $value('department_id', $employee->department_id ?? '');
    $selectedStatus = (string) $value('is_active', $employee->is_active ?? true);
    $titleId = $modalId.'Title';
@endphp

<div class="modal fade employee-form-modal {{ $isEdit ? 'employee-form-modal--edit' : 'employee-form-modal--create' }} {{ $isSystemAccount ? 'employee-form-modal--system' : 'employee-form-modal--employee' }}" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $titleId }}" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content employee-form-modal__content">
            <div class="modal-header employee-form-modal__header">
                <div class="employee-form-modal__heading">
                    <span class="employee-form-modal__icon" aria-hidden="true">
                        <i class="bi {{ $isEdit ? 'bi-pencil-square' : 'bi-person-plus' }}"></i>
                    </span>
                    <div>
                        <h2 class="modal-title" id="{{ $titleId }}">{{ $isEdit ? ($isSystemAccount ? 'แก้ไขบัญชีระบบ' : 'แก้ไขพนักงาน') : ($isSystemAccount ? 'เพิ่มบัญชีระบบ' : 'เพิ่มพนักงาน') }}</h2>
                        <p>{{ $isEdit ? 'ปรับข้อมูลบัญชี สิทธิ์ และสถานะการใช้งาน' : ($isSystemAccount ? 'สร้างบัญชี Admin หรือผู้เข้าชม' : 'สร้างบัญชีและกำหนดสิทธิ์เริ่มต้นให้พนักงาน') }}</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>

            <form action="{{ $formAction }}" method="POST" enctype="multipart/form-data" data-employee-form>
                @csrf
                @if($isEdit) @method('PATCH') @endif
                <input type="hidden" name="_employee_form_modal" value="{{ $modalId }}">

                <div class="modal-body employee-form-modal__body">
                    <section class="employee-form-section employee-form-section--account" aria-labelledby="{{ $modalId }}AccountTitle">
                        <div class="employee-form-section__header">
                            <h3 id="{{ $modalId }}AccountTitle">ข้อมูลบัญชี</h3>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label for="{{ $modalId }}Name" class="form-label">{{ $isSystemAccount ? 'ชื่อผู้ใช้งาน' : 'ชื่อพนักงาน' }}</label>
                                <input id="{{ $modalId }}Name" type="text" name="name"
                                    class="form-control {{ $useOldValues && $errors->has('name') ? 'is-invalid' : '' }}"
                                    value="{{ $value('name', $employee->name ?? '') }}" autocomplete="name" required>
                                @if($useOldValues) @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                            </div>
                            <div class="col-md-6">
                                <label for="{{ $modalId }}Username" class="form-label">บัญชีผู้ใช้งาน</label>
                                <input id="{{ $modalId }}Username" type="text" name="username"
                                    class="form-control {{ $useOldValues && $errors->has('username') ? 'is-invalid' : '' }}"
                                    value="{{ $value('username', $employee->username ?? '') }}" minlength="3" maxlength="50"
                                    pattern="[A-Za-z0-9._-]+" autocomplete="off" placeholder="เช่น user2" aria-describedby="{{ $modalId }}UsernameHelp" required>
                                @if($useOldValues) @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                <p class="employee-form-help" id="{{ $modalId }}UsernameHelp">ใช้ตัวอักษรอังกฤษ ตัวเลข จุด ขีดกลาง หรือขีดล่าง ระบบจะบันทึกเป็นตัวพิมพ์เล็ก</p>
                            </div>
                        </div>
                    </section>

                    <section class="employee-form-section employee-form-section--contact" aria-labelledby="{{ $modalId }}ContactTitle">
                        <div class="employee-form-section__header">
                            <h3 id="{{ $modalId }}ContactTitle">ข้อมูลติดต่อ</h3>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label for="{{ $modalId }}Phone" class="form-label">เบอร์โทรศัพท์</label>
                                <input id="{{ $modalId }}Phone" type="tel" name="phone"
                                    class="form-control {{ $useOldValues && $errors->has('phone') ? 'is-invalid' : '' }}"
                                    value="{{ $value('phone', $employee->phone ?? '') }}" autocomplete="tel" placeholder="08xxxxxxxx">
                                @if($useOldValues) @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                            </div>
                            <div class="col-md-6">
                                <label for="{{ $modalId }}Email" class="form-label">Email <span>(ไม่บังคับ)</span></label>
                                <input id="{{ $modalId }}Email" type="email" name="email"
                                    class="form-control {{ $useOldValues && $errors->has('email') ? 'is-invalid' : '' }}"
                                    value="{{ $value('email', $employee->email ?? '') }}" autocomplete="email">
                                @if($useOldValues) @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                            </div>
                        </div>
                    </section>

                    <section class="employee-form-section employee-form-section--access" aria-labelledby="{{ $modalId }}AccessTitle">
                        <div class="employee-form-section__header">
                            <h3 id="{{ $modalId }}AccessTitle">สิทธิ์และองค์กร</h3>
                        </div>
                        <div class="row g-2">
                            <div class="{{ $isSystemAccount ? 'col-md-6' : 'col-md-4' }}">
                                <label for="{{ $modalId }}Role" class="form-label">สิทธิ์การใช้งาน</label>
                                <select id="{{ $modalId }}Role" name="role" class="form-select" required data-user-role>
                                    @if($isSystemAccount)
                                        <option value="admin" @selected($selectedRole === 'admin')>Admin</option>
                                        <option value="viewer" @selected($selectedRole === 'viewer')>ผู้เข้าชม</option>
                                    @else
                                        <option value="user" @selected($selectedRole === 'user')>พนักงาน</option>
                                        <option value="department_head" @selected($selectedRole === 'department_head')>หัวหน้าแผนก</option>
                                    @endif
                                </select>
                            </div>
                            @unless($isSystemAccount)
                            <div class="col-md-4">
                                <label for="{{ $modalId }}Department" class="form-label">แผนก</label>
                                <select id="{{ $modalId }}Department" name="department_id" class="form-select" data-user-department>
                                    <option value="">เลือกแผนก</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}" @selected($selectedDepartment == $department->id)>{{ $department->department_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @endunless
                            <div class="{{ $isSystemAccount ? 'col-md-6' : 'col-md-4' }}">
                                <label for="{{ $modalId }}Status" class="form-label">สถานะบัญชี</label>
                                <select id="{{ $modalId }}Status" name="is_active" class="form-select" required>
                                    <option value="1" @selected($selectedStatus === '1')>เปิดใช้งาน</option>
                                    <option value="0" @selected($selectedStatus === '0')>ปิดใช้งาน</option>
                                </select>
                            </div>
                        </div>
                        <p class="employee-form-help">{{ $isSystemAccount ? 'Admin จัดการระบบได้เต็มรูปแบบ ส่วนผู้เข้าชมดูข้อมูลภาพรวมได้โดยแก้ไขไม่ได้' : 'พนักงานและหัวหน้าแผนกต้องมีแผนก บัญชีที่ปิดใช้งานจะถูกนำออกจากระบบทุกอุปกรณ์' }}</p>
                    </section>

                    @if(! $isEdit)
                        <section class="employee-form-section employee-form-section--temporary-password" aria-labelledby="{{ $modalId }}PasswordTitle">
                            <div class="employee-form-section__header">
                                <h3 id="{{ $modalId }}PasswordTitle">รหัสผ่านชั่วคราว</h3>
                            </div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label for="{{ $modalId }}Password" class="form-label">รหัสผ่านชั่วคราว</label>
                                    <input id="{{ $modalId }}Password" type="password" name="password"
                                        class="form-control {{ $useOldValues && $errors->has('password') ? 'is-invalid' : '' }}"
                                        autocomplete="new-password" aria-describedby="{{ $modalId }}PasswordHelp" required>
                                    @if($useOldValues) @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                </div>
                            </div>
                            <p class="employee-form-help" id="{{ $modalId }}PasswordHelp">
                                {{ $isSystemAccount ? 'ใช้สำหรับเข้าสู่ระบบครั้งแรก ผู้ใช้จะต้องตั้งรหัสผ่านใหม่หลังเข้าสู่ระบบ' : 'ใช้สำหรับเข้าสู่ระบบครั้งแรก พนักงานจะต้องตั้งรหัสผ่านใหม่หลังเข้าสู่ระบบ' }}
                            </p>
                        </section>
                    @endif

                    <section class="employee-form-section employee-form-section--profile" aria-labelledby="{{ $modalId }}ProfileTitle">
                        <div class="employee-form-section__header">
                            <h3 id="{{ $modalId }}ProfileTitle">รูปภาพโปรไฟล์</h3>
                        </div>
                        <label for="{{ $modalId }}ProfileImage" class="form-label">เลือกไฟล์รูปภาพ</label>
                        <input id="{{ $modalId }}ProfileImage" type="file" name="profile_image"
                            class="form-control {{ $useOldValues && $errors->has('profile_image') ? 'is-invalid' : '' }}"
                            accept="image/png,image/jpeg,image/webp" data-profile-input>
                        @if($useOldValues) @error('profile_image')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                        <img data-profile-preview class="employee-profile-preview" alt="ตัวอย่างรูปโปรไฟล์">
                        <p class="employee-form-help">รองรับ JPG, PNG หรือ WebP ขนาดไม่เกิน 4 MB{{ $isEdit && $employee->profile_image ? ' หากไม่เลือกไฟล์ใหม่ ระบบจะใช้รูปเดิม' : '' }}</p>
                    </section>
                </div>

                <div class="modal-footer employee-form-modal__footer">
                    <button type="button" class="employee-button employee-button--secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="employee-button employee-button--primary">
                        <i class="bi bi-save" aria-hidden="true"></i>{{ $isEdit ? 'บันทึกการแก้ไข' : ($isSystemAccount ? 'บันทึกบัญชีระบบ' : 'บันทึกพนักงาน') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
