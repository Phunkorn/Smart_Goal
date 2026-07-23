@php
    $modalId = 'resetPasswordModal' . $employee->id;
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold"><i class="bi bi-key-fill me-2"></i>รีเซ็ตรหัสผ่าน</h5>
                    <div class="form-help">ตั้งรหัสผ่านชั่วคราวให้ {{ $employee->name }} — พนักงานต้องตั้งรหัสผ่านใหม่ของตัวเองในการเข้าสู่ระบบครั้งถัดไป</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form action="{{ route('employees.resetPassword', $employee->id) }}" method="POST">
                @csrf
                @method('PATCH')
                <div class="modal-body">
                    <label class="form-label fw-bold">รหัสผ่านชั่วคราว</label>
                    <div class="input-group">
                        <input type="text" name="password" id="resetPasswordInput{{ $employee->id }}"
                            class="form-control" minlength="12" required placeholder="อย่างน้อย 12 ตัวอักษร">
                        <button type="button" class="mini-btn" onclick="generateTempPassword({{ $employee->id }})">
                            <i class="bi bi-shuffle"></i> สุ่มรหัส
                        </button>
                    </div>
                    <div class="form-help">รหัสผ่านนี้จะมอบให้พนักงานนำไปกรอกในหน้าล็อกอิน ระบบจะบังคับให้ตั้งรหัสผ่านใหม่ของตัวเองทันที</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="mini-btn" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="mini-btn primary"><i class="bi bi-key-fill"></i> ตั้งรหัสผ่านชั่วคราว</button>
                </div>
            </form>
        </div>
    </div>
</div>
