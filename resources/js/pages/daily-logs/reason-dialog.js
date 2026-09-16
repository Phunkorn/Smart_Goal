/*
 * กล่องถามเหตุผล — ใช้กับ "เริ่มงานช้า" และ "ปิดรอบวันที่ไม่ได้ทำ"
 *
 * ห้ามใช้ input:'select' ของ SweetAlert2 คู่กับดร็อปดาวน์ของโปรเจกต์:
 * Swal อ่านค่าช่องกรอกด้วย selector ลูกโดยตรง `.swal2-popup > .swal2-select`
 * แต่ enhanceSelect() ย้าย <select> เข้าไปไว้ใน .sg-select ที่สร้างครอบ
 * Swal.getInput() จึงคืน null, inputValidator ได้ค่า null ทุกครั้ง แล้วขึ้น
 * "กรุณาเลือกเหตุผล" ค้างไว้แม้ผู้ใช้เลือกเหตุผลไปแล้ว — กดยืนยันไม่ผ่านสักครั้ง
 *
 * กล่องนี้จึงวาด markup เอง แล้วอ่านค่าเองใน preConfirm แบบเดียวกับกล่องปิดงาน
 * (completion-dialog.js) และกล่องก่อนเริ่มงานประจำ (routine-start-dialog.js)
 * ป้ายเหตุผลสำเร็จรูปมาจาก WorkLogDesign กติกาจริงยังตรวจซ้ำที่ฝั่ง server เสมอ
 */
import {initSelectDropdowns} from '../../components/select-dropdown.js';

const OTHER_REASON = 'อื่น ๆ';

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');

export const reasonMarkup = ({reasons = []} = {}) => {
    const options = [...reasons, OTHER_REASON]
        .map((reason) => `<option value="${escapeHtml(reason)}">${escapeHtml(reason)}</option>`)
        .join('');

    return `<div class="log-reason" data-reason>
        <select data-reason-select aria-label="เลือกเหตุผล"><option value="">เลือกเหตุผล</option>${options}</select>
        <label class="log-reason__other" data-reason-other-field hidden>
            <span>ระบุเหตุผล</span>
            <input type="text" maxlength="500" placeholder="พิมพ์เหตุผลสั้น ๆ" data-reason-other>
        </label>
    </div>`;
};

/** ช่องพิมพ์เองโผล่เฉพาะตอนเลือก "อื่น ๆ" */
export const syncReason = (box) => {
    const other = box?.querySelector('[data-reason-other-field]');

    if (other) other.hidden = box.querySelector('[data-reason-select]')?.value !== OTHER_REASON;
};

/** คืน {reason} ที่ส่งต่อได้ทันที หรือ {error} เมื่อยังกรอกไม่ครบ */
export const readReason = (box) => {
    const selected = box?.querySelector('[data-reason-select]')?.value || '';

    if (selected === OTHER_REASON) {
        const typed = box.querySelector('[data-reason-other]')?.value.trim() || '';

        return typed ? {reason: typed} : {error: 'กรุณาระบุเหตุผล'};
    }

    return selected ? {reason: selected} : {error: 'กรุณาเลือกเหตุผล'};
};

/**
 * เปิดกล่องถามเหตุผล คืนข้อความเหตุผล หรือ null เมื่อผู้ใช้กดยกเลิก
 *
 * didOpen ผูก listener กับ popup ที่ Swal สร้างใหม่ทุกครั้ง จึงไม่มี listener ซ้อน
 */
export async function askReason({swal, title = 'ระบุเหตุผล', reasons = []} = {}) {
    if (! swal) return null;

    const result = await swal.fire({
        titleText: title,
        html: reasonMarkup({reasons}),
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        focusConfirm: false,
        didOpen: (popup) => {
            const box = popup.querySelector('[data-reason]');
            const dropdown = initSelectDropdowns(box, '[data-reason-select]')[0];
            dropdown?.root.classList.add('log-reason-select');
            box?.addEventListener('change', () => syncReason(box));
        },
        preConfirm: () => {
            const read = readReason(swal.getPopup()?.querySelector('[data-reason]'));

            if (read.error) {
                swal.showValidationMessage(read.error);

                return false;
            }

            return read.reason;
        },
    });

    return result?.isConfirmed ? result.value : null;
}
