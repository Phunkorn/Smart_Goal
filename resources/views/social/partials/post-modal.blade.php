{{--
    modal สร้าง/แก้ไขโพสต์ใบเดียว — Bootstrap Modal เป็นเจ้าของ backdrop การล็อกการเลื่อน โฟกัส และ Escape
    resources/js/pages/social/composer.js สลับโหมด (สร้าง / แก้ไข) และส่งฟอร์มแบบ AJAX

    ตัวเลือกผู้ที่มองเห็นโพสต์และตัวเลือกอีโมจิเป็น popover ภายใน modal (ไม่ใช่ modal ซ้อน) ใช้
    resources/js/pages/social/popover.js ตัวเดียวกัน ปิดด้วยการคลิกนอกกล่องและ Escape
    โดย Escape ขณะเปิด popover จะปิดเฉพาะ popover ไม่ปิด modal
--}}
<div class="modal fade social-modal" id="socialPostModal" tabindex="-1" aria-labelledby="socialPostModalTitle"
    aria-hidden="true" data-social-post-modal data-store-url="{{ route('social.posts.store') }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable social-modal__dialog">
        <form class="modal-content social-modal__content" data-social-post-form novalidate>
            <div class="modal-header social-modal__header">
                <h2 class="modal-title social-modal__title" id="socialPostModalTitle" data-social-post-modal-title>สร้างโพสต์</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>

            <div class="modal-body social-modal__body">
                @php
                    // ชื่อแผนกจริงของผู้โพสต์ — ไม่มีแผนกเลือก "เฉพาะแผนกของฉัน" ไม่ได้ (server ปฏิเสธซ้ำเสมอ)
                    $viewerDepartment = $viewer->department?->department_name;
                @endphp
                <div class="social-modal__author">
                    <span class="social-avatar" aria-hidden="true">
                        @include('components.user-avatar-content', ['user' => $viewer, 'avatarLength' => 2])
                    </span>
                    <div class="social-modal__identity">
                        <strong>{{ $viewer->name }}</strong>
                        <div class="social-audience" data-social-audience>
                            <button type="button" class="social-audience__toggle" data-social-audience-toggle
                                aria-expanded="false" aria-controls="socialAudiencePanel" aria-describedby="socialAudienceHint">
                                <i class="bi bi-globe2" aria-hidden="true" data-social-audience-icon></i>
                                <span data-social-audience-label>ทุกคนในองค์กร</span>
                                <i class="bi bi-chevron-down social-audience__caret" aria-hidden="true"></i>
                            </button>
                            <span class="visually-hidden" id="socialAudienceHint">เลือกผู้ที่สามารถมองเห็นโพสต์นี้</span>
                            <fieldset class="social-audience__panel social-popover" id="socialAudiencePanel" data-social-audience-panel>
                                <legend class="social-audience__legend">ผู้ที่สามารถมองเห็นโพสต์</legend>
                                <label class="social-audience__choice" data-social-audience-choice>
                                    <input type="radio" class="social-audience__radio" name="visibility" value="everyone" checked data-social-audience-option>
                                    <span class="social-audience__icon" aria-hidden="true"><i class="bi bi-globe2"></i></span>
                                    <span class="social-audience__text">
                                        <strong>ทุกคนในองค์กร</strong>
                                        <small>พนักงานทุกแผนกสามารถเห็นโพสต์นี้</small>
                                    </span>
                                    <i class="bi bi-check2 social-audience__check" aria-hidden="true"></i>
                                </label>
                                <label class="social-audience__choice{{ $viewerDepartment ? '' : ' is-disabled' }}" data-social-audience-choice>
                                    <input type="radio" class="social-audience__radio" name="visibility" value="department" data-social-audience-option
                                        aria-describedby="socialAudienceDepartmentHint" @disabled(! $viewerDepartment)>
                                    <span class="social-audience__icon" aria-hidden="true"><i class="bi bi-people"></i></span>
                                    <span class="social-audience__text">
                                        <strong data-social-audience-department-name>เฉพาะแผนกของฉัน{{ $viewerDepartment ? ' ('.$viewerDepartment.')' : '' }}</strong>
                                        <small id="socialAudienceDepartmentHint">{{ $viewerDepartment
                                            ? 'เฉพาะพนักงานแผนก '.$viewerDepartment.' เท่านั้นที่เห็น'
                                            : 'บัญชีของคุณยังไม่มีแผนก จึงเลือกตัวเลือกนี้ไม่ได้ กรุณาติดต่อผู้ดูแลระบบ' }}</small>
                                    </span>
                                    <i class="bi bi-check2 social-audience__check" aria-hidden="true"></i>
                                </label>
                            </fieldset>
                        </div>
                    </div>
                </div>

                <label class="visually-hidden" for="socialPostBody">ข้อความโพสต์</label>
                <textarea class="social-modal__textarea" id="socialPostBody" name="body" rows="4"
                    maxlength="{{ (int) config('social.post_max_length') }}"
                    placeholder="คุณคิดอะไรอยู่..." data-social-post-body></textarea>
                <p class="social-modal__counter" data-social-post-counter aria-live="polite">0 / {{ number_format((int) config('social.post_max_length')) }}</p>

                <div class="social-modal__media" data-social-post-media hidden>
                    <ul class="social-upload-grid" data-social-post-previews aria-label="รูปภาพที่จะโพสต์"></ul>
                </div>

                <p class="social-modal__error" role="alert" data-social-post-error hidden></p>
            </div>

            <div class="social-modal__tools">
                <span class="social-modal__tools-label">เพิ่มลงในโพสต์</span>
                <input type="file" class="visually-hidden" id="socialPostFiles" accept="{{ \App\Support\SocialImageRules::acceptAttribute() }}"
                    multiple tabindex="-1" aria-hidden="true" data-social-post-file>
                <button type="button" class="social-tool social-tool--image" data-social-post-pick-image
                    aria-label="เพิ่มรูปภาพ" title="เพิ่มรูปภาพ">
                    <i class="bi bi-images" aria-hidden="true"></i>
                </button>
                <div class="social-emoji" data-social-emoji>
                    <button type="button" class="social-tool social-tool--emoji" data-social-emoji-toggle
                        aria-expanded="false" aria-controls="socialEmojiPanel" aria-label="เลือกอีโมจิ" title="เลือกอีโมจิ">
                        <i class="bi bi-emoji-smile" aria-hidden="true"></i>
                    </button>
                    <div class="social-emoji__panel social-popover" id="socialEmojiPanel" role="group" aria-label="อีโมจิ" data-social-emoji-panel></div>
                </div>
                <small class="social-modal__limits">{{ \App\Support\SocialImageRules::limitsLabel() }}</small>
            </div>

            <div class="modal-footer social-modal__footer">
                <button type="button" class="btn btn-light social-modal__cancel" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary social-modal__submit" data-social-post-submit>
                    <span class="spinner-border spinner-border-sm" aria-hidden="true" data-social-spinner hidden></span>
                    <span data-social-post-submit-label>โพสต์</span>
                </button>
            </div>
        </form>
    </div>
</div>
