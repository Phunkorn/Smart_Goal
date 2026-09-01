@php($openPasswordModal = $errors->hasAny(['current_password', 'password']))

<div class="modal fade settings-password-modal" id="settingsPasswordModal" tabindex="-1"
    aria-labelledby="settingsPasswordModalTitle" aria-hidden="true" data-password-modal
    @if($openPasswordModal) data-open-on-load="true" @endif>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header settings-password-modal__header">
                <div class="settings-security__icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></div>
                <div class="settings-password-modal__heading">
                    <span class="settings-card__eyebrow">ความปลอดภัย</span>
                    <h2 class="modal-title" id="settingsPasswordModalTitle">ตั้งรหัสผ่านใหม่</h2>
                    <p>ยืนยันรหัสผ่านปัจจุบันก่อนตั้งรหัสผ่านใหม่</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form action="{{ route('settings.password.update') }}" method="POST" class="settings-password-form">
                @csrf
                @method('PATCH')
                <div class="modal-body">
                    <div class="settings-security__notice">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <span>เมื่อเปลี่ยนสำเร็จ ระบบจะนำบัญชีออกจากอุปกรณ์อื่นและให้ session ปัจจุบันใช้งานต่ออย่างปลอดภัย</span>
                    </div>
                    <div>
                        <label for="currentPassword" class="form-label">รหัสผ่านปัจจุบัน <span aria-hidden="true">*</span></label>
                        <input id="currentPassword" type="password" name="current_password"
                            class="form-control @error('current_password') is-invalid @enderror"
                            autocomplete="current-password" required>
                        @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="settings-password-grid">
                        <div>
                            <label for="newPassword" class="form-label">รหัสผ่านใหม่ <span aria-hidden="true">*</span></label>
                            <input id="newPassword" type="password" name="password"
                                class="form-control @error('password') is-invalid @enderror"
                                minlength="{{ \App\Support\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" required>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="newPasswordConfirmation" class="form-label">ยืนยันรหัสผ่านใหม่ <span aria-hidden="true">*</span></label>
                            <input id="newPasswordConfirmation" type="password" name="password_confirmation"
                                class="form-control" minlength="{{ \App\Support\PasswordPolicy::MIN_LENGTH }}" autocomplete="new-password" required>
                        </div>
                    </div>
                    <p class="settings-form__help">{{ \App\Support\PasswordPolicy::description() }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary settings-primary-button">
                        <i class="bi bi-key" aria-hidden="true"></i> เปลี่ยนรหัสผ่าน
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
