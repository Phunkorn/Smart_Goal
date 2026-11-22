<div class="settings-page account-settings" data-settings-page data-settings-url="{{ route('settings.index') }}">
    @include('settings.components.profile-modal', ['errors' => request()->routeIs('settings.index') ? $errors : new \Illuminate\Support\ViewErrorBag])
    @include('settings.components.password-modal', ['errors' => request()->routeIs('settings.index') ? $errors : new \Illuminate\Support\ViewErrorBag])

    <x-account-modal id="settingsModal" title="ตั้งค่าระบบ" size="lg"
        :data-open-on-load="request()->routeIs('settings.index') && ! $errors->hasAny(['name', 'phone', 'profile_image', 'current_password', 'password']) ? 'true' : null">
        <div class="modal-body">
            <div class="alert {{ session('success') ? 'alert-success' : ($errors->has('telegram') ? 'alert-danger' : 'd-none') }}" role="status" aria-live="polite" data-account-feedback>{{ session('success') ?: $errors->first('telegram') }}</div>
            <section class="settings-card mb-3" aria-labelledby="accountSettingsIdentity">
                <div class="settings-card__header">
                    <h3 id="accountSettingsIdentity" data-account-name>{{ $user->name }}</h3>
                    <p>{{ $user->email ?: '@'.$user->username }}</p>
                    <p>{{ \App\Support\RoleLabel::for($user) }} · {{ optional($user->department)->department_name ?? 'ไม่ได้ระบุแผนก' }}</p>
                    <p>การเปลี่ยนสิทธิ์และแผนกต้องดำเนินการโดย Admin</p>
                    <button type="button" class="btn btn-outline-primary mt-2" data-account-open="settingsProfileModal">แก้ไขโปรไฟล์</button>
                </div>
            </section>
            <section class="settings-card mb-3" aria-labelledby="password-settings-title">
                <div class="settings-card__header">
                    <h3 id="password-settings-title">ความปลอดภัย</h3>
                    <p>จัดการรหัสผ่านและออกจากระบบบนอุปกรณ์อื่น</p>
                    <button type="button" class="btn btn-outline-primary mt-2" data-account-open="settingsPasswordModal">เปลี่ยนรหัสผ่าน</button>
                </div>
            </section>
            @if($user->role !== 'viewer')
                <div data-account-telegram>@include('settings.components.telegram-card')</div>
            @endif
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button></div>
    </x-account-modal>

    <x-account-modal id="accountLogoutModal" title="ออกจากระบบ" size="sm">
        <form action="{{ route('logout') }}" method="POST" data-account-logout>
            @csrf
            <div class="modal-body">คุณต้องการออกจากระบบใช่หรือไม่?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-danger">ออกจากระบบ</button>
            </div>
        </form>
    </x-account-modal>
</div>
