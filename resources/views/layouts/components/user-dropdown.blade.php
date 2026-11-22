<div class="dropdown topbar-account">
    <button type="button" class="topbar-account__trigger" id="topbarAccountTrigger" data-bs-toggle="dropdown" data-bs-display="static"
        aria-expanded="false" aria-label="เมนูบัญชีผู้ใช้" aria-controls="topbarAccountMenu">
        <span class="topbar-account__avatar" data-account-avatar>
            @if($currentUser->profile_image)
                <span>{{ mb_substr($currentUser->name, 0, 2) }}</span>
            @endif
            @include('components.user-avatar-content', ['user' => $currentUser, 'avatarLength' => 2])
        </span>
        <span class="topbar-account__identity">
            <span class="topbar-account__name" data-account-name>{{ $currentUser->name }}</span>
            <span class="topbar-account__role">{{ $roleLabel }}</span>
        </span>
        <i class="bi bi-chevron-down topbar-account__chevron" aria-hidden="true"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end topbar-slide-menu topbar-account__menu" id="topbarAccountMenu" aria-labelledby="topbarAccountTrigger">
        <li class="topbar-account__header">เข้าสู่ระบบในชื่อ <strong>{{ $currentUser->username }}</strong></li>
        <li><button type="button" class="dropdown-item" data-account-open="settingsProfileModal"><i class="bi bi-person" aria-hidden="true"></i>แก้ไขโปรไฟล์</button></li>
        <li><button type="button" class="dropdown-item" data-account-open="settingsPasswordModal" data-bs-target="#settingsPasswordModal"><i class="bi bi-key" aria-hidden="true"></i>เปลี่ยนรหัสผ่าน</button></li>
        <li><button type="button" class="dropdown-item" data-account-open="settingsModal"><i class="bi bi-gear" aria-hidden="true"></i>ตั้งค่าระบบ</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button type="button" class="dropdown-item text-danger" data-account-open="accountLogoutModal"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>ออกจากระบบ</button></li>
    </ul>
</div>
