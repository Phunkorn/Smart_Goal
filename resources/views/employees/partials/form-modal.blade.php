@php
    $isEdit = $mode === 'edit';
    $formAction = $isEdit ? route('employees.update', $employee->id) : route('employees.store');
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold "><i class="bi bi-person-plus-fill"></i>{{ $isEdit ? 'แก้ไขพนักงาน' : 'เพิ่มพนักงาน' }}</h5>
                    <div class="form-help">Admin และ Viewer ไม่ต้องเลือกแผนก ส่วนพนักงานต้องเลือกแผนก</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form action="{{ $formAction }}" method="POST" enctype="multipart/form-data" data-employee-form>
                @csrf
                @if($isEdit)
                    @method('PATCH')
                @endif
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ชื่อพนักงาน</label>
                            <input type="text" name="name" class="form-control" value="{{ old('name', $employee->name ?? '') }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">เบอร์โทรศัพท์</label>
                            <input type="text" name="phone" class="form-control" value="{{ old('phone', $employee->phone ?? '') }}" placeholder="08xxxxxxxx">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Email</label>
                            <input type="email" name="email" class="form-control" value="{{ old('email', $employee->email ?? '') }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">สิทธิ์การใช้งาน</label>
                            <select name="role" class="form-select" required data-user-role>
                                <option value="user" @selected(old('role', $employee->role ?? 'user') === 'user')>พนักงาน</option>
                                <option value="admin" @selected(old('role', $employee->role ?? '') === 'admin')>Admin</option>
                                <option value="viewer" @selected(old('role', $employee->role ?? '') === 'viewer')>ผู้เข้าชม</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">แผนก</label>
                            <select name="department_id" class="form-select" data-user-department>
                                <option value="">ไม่ต้องเลือกแผนก</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}" @selected(old('department_id', $employee->department_id ?? '') == $department->id)>{{ $department->department_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">สถานะบัญชี</label>
                            <select name="is_active" class="form-select" required>
                                <option value="1" @selected((string) old('is_active', $employee->is_active ?? true) === '1')>เปิดใช้งาน</option>
                                <option value="0" @selected((string) old('is_active', $employee->is_active ?? true) === '0')>ปิดใช้งาน</option>
                            </select>
                            <div class="form-help">บัญชีที่ปิดใช้งานจะถูกออกจากทุกอุปกรณ์</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">{{ $isEdit ? 'รหัสผ่านใหม่ (เว้นว่างได้)' : 'รหัสผ่านชั่วคราว' }}</label>
                            <input type="password" name="password" class="form-control" minlength="12" {{ $isEdit ? '' : 'required' }} autocomplete="new-password" placeholder="อย่างน้อย 12 ตัวอักษร">
                            <div class="form-help">ต้องมีตัวพิมพ์เล็ก ตัวพิมพ์ใหญ่ ตัวเลข และสัญลักษณ์</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ยืนยันรหัสผ่าน</label>
                            <input type="password" name="password_confirmation" class="form-control" minlength="12" {{ $isEdit ? '' : 'required' }} autocomplete="new-password" placeholder="กรอกรหัสผ่านอีกครั้ง">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">รูปภาพโปรไฟล์</label>
                            <input type="file" name="profile_image" class="form-control" accept="image/png,image/jpeg,image/webp" data-profile-input>
                            <img data-profile-preview class="profile-preview" alt="ตัวอย่างรูปโปรไฟล์">
                            @if($isEdit && $employee->profile_image)
                                <div class="form-help">มีรูปเดิมอยู่แล้ว หากไม่เลือกไฟล์ใหม่ ระบบจะใช้รูปเดิม</div>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="mini-btn" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="mini-btn primary"><i class="bi bi-save"></i>{{ $isEdit ? 'บันทึกการแก้ไข' : 'บันทึกพนักงาน' }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
