{{--
    กล่องสร้าง/แก้ไขกระดาน ใช้ตัวเดียวกันทั้งสองงาน

    เป็น modal จริง (มี backdrop, ล็อกการเลื่อนหน้า, aria-modal) ไม่ใช่ popover
    เจ้าของสถานะเปิด/ปิด โฟกัส และปุ่ม Escape มีตัวเดียวคือ board-list.js
    ตามกติกาเรื่อง overlay ใน CLAUDE.md

    ปุ่มยืนยันการลบไม่ได้อยู่ที่นี่ แต่ใช้ window.Swal.fire() เพราะเป็นการถาม
    คำถามเดียวจบ ไม่ต้องกรอกอะไร
--}}
<div class="ws-modal" data-workspace-modal hidden>
    <div class="ws-modal__backdrop" data-workspace-modal-dismiss></div>

    <div class="ws-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wsModalTitle">
        <form class="ws-modal__form" data-workspace-form novalidate>
            <header class="ws-modal__header">
                <h2 class="ws-modal__title" id="wsModalTitle" data-workspace-modal-title>สร้างกระดานใหม่</h2>
                <button type="button" class="ws-icon-btn" data-workspace-modal-dismiss aria-label="ปิด">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </header>

            <div class="ws-modal__body">
                <label class="ws-field">
                    <span class="ws-field__label">ชื่อกระดาน</span>
                    <input type="text" class="ws-field__input" name="title" data-workspace-title
                        maxlength="{{ \App\Support\WorkspaceDesign::MAX_TITLE_LENGTH }}"
                        placeholder="เช่น ไอเดียปรับปรุงขั้นตอนแจ้งซ่อม" required>
                </label>

                <fieldset class="ws-field">
                    <legend class="ws-field__label">ใครเห็นกระดานนี้ได้</legend>

                    @foreach (\App\Support\WorkspaceDesign::VISIBILITIES as $key => $option)
                        <label class="ws-radio">
                            <input type="radio" name="visibility" value="{{ $key }}"
                                @checked($key === \App\Support\WorkspaceDesign::DEFAULT_VISIBILITY)>
                            <span class="ws-radio__body">
                                <span class="ws-radio__label">
                                    <i class="bi {{ $option['icon'] }}" aria-hidden="true"></i>
                                    {{ $option['label'] }}
                                </span>
                                <span class="ws-radio__hint">{{ $option['hint'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                <p class="ws-modal__error" data-workspace-error hidden role="alert"></p>
            </div>

            <footer class="ws-modal__footer">
                <button type="button" class="ws-btn" data-workspace-modal-dismiss>ยกเลิก</button>
                <button type="submit" class="ws-btn ws-btn--primary" data-workspace-submit>บันทึก</button>
            </footer>
        </form>
    </div>
</div>
