@php
    $modalId = 'resetPasswordModal'.$employee->id;
    $titleId = $modalId.'Title';
@endphp

<div class="modal fade employee-reset-modal" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $titleId }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content employee-reset-modal__content">
            <div class="modal-header employee-reset-modal__header">
                <div>
                    <span class="employee-reset-modal__eyebrow">Admin action</span>
                    <h2 class="modal-title" id="{{ $titleId }}">รีเซ็ตรหัสผ่าน</h2>
                    <p>ตั้งรหัสผ่านชั่วคราวให้ {{ $employee->name }}</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>

            <form action="{{ route('employees.resetPassword', $employee->id) }}" method="POST">
                @csrf
                @method('PATCH')
                <input type="hidden" name="_employee_form_modal" value="{{ $modalId }}">
                <div class="modal-body employee-reset-modal__body">
                    <div class="employee-reset-modal__notice">
                        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                        <span>บัญชีจะถูกนำออกจากทุกอุปกรณ์ และพนักงานต้องตั้งรหัสผ่านใหม่หลังเข้าสู่ระบบครั้งถัดไป</span>
                    </div>
                    <label for="resetPasswordInput{{ $employee->id }}" class="form-label">รหัสผ่านชั่วคราว</label>
                    <div class="employee-reset-modal__input-group">
                        <input type="text" name="password" id="resetPasswordInput{{ $employee->id }}"
                            class="form-control" minlength="12" autocomplete="off" required>
                        <button type="button" class="employee-button employee-button--secondary" data-generate-password="{{ $employee->id }}">
                            <i class="bi bi-shuffle" aria-hidden="true"></i>สุ่มรหัส
                        </button>
                    </div>
                    <p class="employee-form-help">อย่างน้อย 12 ตัวอักษร พร้อมตัวพิมพ์เล็ก ตัวพิมพ์ใหญ่ ตัวเลข และสัญลักษณ์</p>
                </div>
                <div class="modal-footer employee-form-modal__footer">
                    <button type="button" class="employee-button employee-button--secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="employee-button employee-button--primary">
                        <i class="bi bi-key" aria-hidden="true"></i>ตั้งรหัสผ่านชั่วคราว
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
