{{--
    แม่แบบของฟอร์มที่สร้างขึ้นตอนใช้งาน (ตอบกลับ / แก้ไขความคิดเห็น) — <template> ไม่ถูกเรนเดอร์และไม่มีสคริปต์
    resources/js/pages/social/comments.js คัดลอกไปใช้ทีละชุด ฟอร์มตอบกลับมีได้ครั้งละหนึ่งฟอร์มต่อโพสต์
--}}
<template data-social-reply-template>
    <form class="social-comment-form social-comment-form--reply" data-social-reply-form novalidate>
        <span class="social-avatar social-avatar--xs" aria-hidden="true">
            @include('components.user-avatar-content', ['user' => $viewer, 'avatarLength' => 2])
        </span>
        <div class="social-comment-form__stack">
            <p class="social-comment-form__context">
                ตอบกลับ <strong data-social-reply-to></strong>
                <button type="button" class="social-comment__link" data-social-reply-cancel>ยกเลิก</button>
            </p>
            <div class="social-comment-form__field">
                <label class="visually-hidden" data-social-reply-label>เขียนการตอบกลับ</label>
                <textarea name="body" rows="1" maxlength="{{ (int) config('social.comment_max_length') }}"
                    placeholder="เขียนการตอบกลับ..." data-social-comment-input></textarea>
                <button type="submit" class="social-comment-form__send" aria-label="ส่งการตอบกลับ">
                    <i class="bi bi-send-fill" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </form>
</template>

<template data-social-edit-template>
    <form class="social-comment-edit" data-social-comment-edit-form novalidate>
        <label class="visually-hidden" data-social-edit-label>แก้ไขความคิดเห็น</label>
        <textarea name="body" rows="2" maxlength="{{ (int) config('social.comment_max_length') }}" data-social-comment-input></textarea>
        <div class="social-comment-edit__actions">
            <button type="button" class="btn btn-sm btn-light" data-social-edit-cancel>ยกเลิก</button>
            <button type="submit" class="btn btn-sm btn-primary">บันทึก</button>
        </div>
    </form>
</template>
