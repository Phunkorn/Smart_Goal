{{--
    แถบเริ่มโพสต์บนสุดของฟีด — ทุกปุ่มเปิด modal สร้างโพสต์ใบเดียวกัน (social.partials.post-modal)
    "รูปภาพ" เปิดตัวเลือกไฟล์ทันที "ความรู้สึก" เปิดตัวเลือกอีโมจิทันที
--}}
<section class="social-card social-composer" aria-label="สร้างโพสต์">
    <div class="social-composer__row">
        <span class="social-avatar" aria-hidden="true">
            @include('components.user-avatar-content', ['user' => $viewer, 'avatarLength' => 2])
        </span>
        <button type="button" class="social-composer__prompt" data-social-compose="text">
            คุณคิดอะไรอยู่...
        </button>
    </div>
    <div class="social-composer__actions">
        <button type="button" class="social-composer__action" data-social-compose="image">
            <i class="bi bi-images social-composer__icon social-composer__icon--image" aria-hidden="true"></i>
            <span>รูปภาพ</span>
        </button>
        <button type="button" class="social-composer__action" data-social-compose="emoji">
            <i class="bi bi-emoji-smile social-composer__icon social-composer__icon--emoji" aria-hidden="true"></i>
            <span>ความรู้สึก</span>
        </button>
    </div>
</section>
