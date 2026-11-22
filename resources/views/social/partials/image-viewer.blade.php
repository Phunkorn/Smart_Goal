{{--
    ตัวดูรูปเต็มจอของโพสต์ — Bootstrap Modal (fullscreen) เป็นเจ้าของโฟกัส Escape และการล็อกการเลื่อน
    resources/js/pages/social/image-viewer.js เติมรายการรูปจาก data-images ของโพสต์ที่ถูกคลิก
    ลูกศรซ้าย/ขวาบนคีย์บอร์ดเลื่อนรูปได้ และโฟกัสกลับไปที่รูปที่กดเปิดเมื่อปิด
--}}
<div class="modal fade social-viewer" id="socialImageViewer" tabindex="-1" aria-label="ดูรูปภาพ" aria-hidden="true" data-social-viewer>
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content social-viewer__content">
            <div class="social-viewer__bar">
                <span class="social-viewer__counter" data-social-viewer-counter aria-live="polite"></span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิดตัวดูรูป"></button>
            </div>
            <div class="social-viewer__stage">
                <button type="button" class="social-viewer__nav social-viewer__nav--prev" data-social-viewer-prev aria-label="รูปก่อนหน้า">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </button>
                <figure class="social-viewer__figure">
                    <span class="spinner-border text-light social-viewer__loading" role="status" data-social-viewer-loading hidden>
                        <span class="visually-hidden">กำลังโหลดรูป</span>
                    </span>
                    <img class="social-viewer__image" alt="" data-social-viewer-image>
                    <figcaption class="social-viewer__error" data-social-viewer-error hidden>
                        <i class="bi bi-image" aria-hidden="true"></i> โหลดรูปไม่สำเร็จ
                    </figcaption>
                </figure>
                <button type="button" class="social-viewer__nav social-viewer__nav--next" data-social-viewer-next aria-label="รูปถัดไป">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
</div>
