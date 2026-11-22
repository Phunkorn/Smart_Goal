{{--
    ปุ่มบัญชีบน Topbar แสดงเฉพาะรูปโปรไฟล์ (วงกลม ขนาดเท่าปุ่มไอคอนข้างเคียง)
    ชื่อและบทบาท/แผนกอยู่ในหน้าตั้งค่าบัญชี (settings/components/account-modals) และบรรทัด "เข้าสู่ระบบในชื่อ"
    ของเมนูนี้ — ชื่อสำหรับ screen reader มาจาก aria-label ของปุ่มกับบรรทัดนั้น
--}}
<div class="dropdown topbar-account">
    <button type="button" class="topbar-account__trigger" id="topbarAccountTrigger" data-bs-toggle="dropdown" data-bs-display="static"
        aria-expanded="false" aria-label="เมนูบัญชีผู้ใช้" aria-controls="topbarAccountMenu">
        <span class="topbar-account__avatar" data-account-avatar>
            @if($currentUser->profile_image)
                <span>{{ mb_substr($currentUser->name, 0, 2) }}</span>
            @endif
            @include('components.user-avatar-content', ['user' => $currentUser, 'avatarLength' => 2])
        </span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end topbar-slide-menu topbar-account__menu" id="topbarAccountMenu" aria-labelledby="topbarAccountTrigger">
        <li class="topbar-account__header">เข้าสู่ระบบในชื่อ <strong>{{ $currentUser->username }}</strong></li>
        <li><button type="button" class="dropdown-item" data-account-open="settingsProfileModal"><i class="bi bi-person" aria-hidden="true"></i>แก้ไขโปรไฟล์</button></li>
        <li><button type="button" class="dropdown-item" data-account-open="settingsPasswordModal" data-bs-target="#settingsPasswordModal"><i class="bi bi-key" aria-hidden="true"></i>เปลี่ยนรหัสผ่าน</button></li>
        <li><button type="button" class="dropdown-item" data-account-open="settingsModal"><i class="bi bi-telegram" aria-hidden="true"></i>การเชื่อม Telegram</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button type="button" class="dropdown-item text-danger" data-account-open="accountLogoutModal"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>ออกจากระบบ</button></li>
    </ul>
</div>
