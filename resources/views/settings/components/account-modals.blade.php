<div class="settings-page account-settings" data-settings-page data-settings-url="{{ route('settings.index') }}">
    @include('settings.components.profile-modal', ['errors' => request()->routeIs('settings.index') ? $errors : new \Illuminate\Support\ViewErrorBag])
    @include('settings.components.password-modal', ['errors' => request()->routeIs('settings.index') ? $errors : new \Illuminate\Support\ViewErrorBag])

    {{--
        modal การเชื่อม Telegram — มีเฉพาะการ์ด Telegram (settings.components.telegram-card)
        ข้อมูลโปรไฟล์และรหัสผ่านอยู่ใน settingsProfileModal / settingsPasswordModal ที่เปิดจากเมนูบัญชีโดยตรง
        id ยังเป็น settingsModal เพราะสคริปต์ (settings/index.js) และ route หน้า /settings เปิดด้วย id นี้
    --}}
    <x-account-modal id="settingsModal" title="การเชื่อม Telegram" size="lg"
        :data-open-on-load="request()->routeIs('settings.index') && ! $errors->hasAny(['name', 'phone', 'profile_image', 'current_password', 'password']) ? 'true' : null">
        <div class="modal-body">
            <div class="alert {{ session('success') ? 'alert-success' : ($errors->has('telegram') ? 'alert-danger' : 'd-none') }}" role="status" aria-live="polite" data-account-feedback>{{ session('success') ?: $errors->first('telegram') }}</div>
            @if($user->role !== 'viewer')
                <div data-account-telegram>@include('settings.components.telegram-card')</div>
            @else
                {{-- viewer ไม่เป็นผู้รับการแจ้งเตือน (TelegramSettingsController) จึงไม่มีการ์ด Telegram ให้เชื่อม --}}
                <p class="settings-security__notice mb-0">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <span>บัญชีสิทธิ์อ่านอย่างเดียวไม่รับการแจ้งเตือนผ่าน Telegram</span>
                </p>
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
