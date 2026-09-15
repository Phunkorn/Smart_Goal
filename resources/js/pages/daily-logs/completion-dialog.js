/*
 * กล่องปิดงาน — ถามทุกอย่างในกล่องเดียว
 *
 * 1. ผลการทำงาน: เสร็จสิ้น หรือ พบปัญหา (พบปัญหาต้องเขียนรายละเอียด)
 * 2. เหตุผลที่เสร็จเกินเวลา — มีเฉพาะเมื่อเลยเวลาสิ้นสุด + ช่วงผ่อนผันแล้ว
 *
 * ใช้กับทั้งงานประจำและงานนอกสถานที่ ป้ายเหตุผลสำเร็จรูปมาจาก WorkLogDesign ผ่าน JSON island
 * กติกาจริงยังตรวจซ้ำที่ WorkLogService::markDone() กล่องนี้เป็นแค่ทางกรอกที่สะดวก
 */
import {initSelectDropdowns} from '../../components/select-dropdown.js';

const OTHER_REASON = 'อื่น ๆ';

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');

export const completionMarkup = ({late = false, reasons = []} = {}) => {
    const options = [...reasons, OTHER_REASON]
        .map((reason) => `<option value="${escapeHtml(reason)}">${escapeHtml(reason)}</option>`)
        .join('');

    return `<div class="log-completion" data-completion>
        <fieldset class="log-completion__outcome" aria-label="สถานะการปิดงาน">
            <label><input type="radio" name="log-completion-outcome" value="done" checked> เสร็จสิ้น ไม่พบปัญหา</label>
            <label><input type="radio" name="log-completion-outcome" value="issue"> พบปัญหา</label>
        </fieldset>
        <label class="log-completion__field" data-completion-issue hidden>
            <span>รายละเอียดปัญหาที่พบ</span>
            <textarea rows="3" maxlength="2000" placeholder="เช่น เครื่อง 3 เปิดไม่ติด แจ้งซ่อมแล้ว" data-completion-issue-details></textarea>
        </label>
        ${late ? `<div class="log-completion__field">
            <label for="log-completion-late-reason">เหตุผลที่เสร็จเกินเวลา</label>
            <select id="log-completion-late-reason" data-completion-late-reason><option value="">เลือกเหตุผล</option>${options}</select>
        </div>
        <label class="log-completion__field" data-completion-late-other-field hidden>
            <span>ระบุเหตุผล</span>
            <input type="text" maxlength="500" placeholder="พิมพ์เหตุผลสั้น ๆ" data-completion-late-other>
        </label>` : ''}
    </div>`;
};

/** แสดงเฉพาะช่องที่เกี่ยวข้องกับสิ่งที่เลือกอยู่ */
export const syncCompletion = (box) => {
    if (! box) return;

    const outcome = box.querySelector('input[name="log-completion-outcome"]:checked')?.value;
    const issue = box.querySelector('[data-completion-issue]');
    if (issue) issue.hidden = outcome !== 'issue';

    const other = box.querySelector('[data-completion-late-other-field]');
    if (other) other.hidden = box.querySelector('[data-completion-late-reason]')?.value !== OTHER_REASON;
};

/**
 * อ่านค่าที่กรอก คืน {fields} ที่ส่งให้ route complete ได้ทันที หรือ {error} เมื่อยังกรอกไม่ครบ
 */
export const readCompletion = (box, {late = false} = {}) => {
    const outcome = box?.querySelector('input[name="log-completion-outcome"]:checked')?.value || 'done';
    const fields = {outcome};

    if (outcome === 'issue') {
        const details = box.querySelector('[data-completion-issue-details]')?.value.trim() || '';
        if (! details) return {error: 'กรุณาระบุรายละเอียดปัญหาที่พบ'};
        fields.issue_details = details;
    }

    if (late) {
        const selected = box.querySelector('[data-completion-late-reason]')?.value || '';
        const reason = selected === OTHER_REASON
            ? (box.querySelector('[data-completion-late-other]')?.value.trim() || '')
            : selected;
        if (! reason) return {error: 'กรุณาระบุเหตุผลที่เสร็จเกินเวลา'};
        fields.late_completion_reason = reason;
    }

    return {fields};
};

/**
 * เปิดกล่องปิดงาน คืนค่าที่ต้องส่งไปกับ route complete หรือ null เมื่อผู้ใช้กดยกเลิก
 *
 * didOpen ผูก listener กับ popup ที่ Swal สร้างใหม่ทุกครั้ง จึงไม่มี listener ซ้อน
 */
export async function askCompletion({swal, title = '', late = false, reasons = []} = {}) {
    if (! swal) return null;

    const result = await swal.fire({
        titleText: title ? `ปิดงาน: ${title}` : 'ปิดงาน',
        html: completionMarkup({late, reasons}),
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        focusConfirm: false,
        didOpen: (popup) => {
            const box = popup.querySelector('[data-completion]');
            initSelectDropdowns(box, '[data-completion-late-reason]');
            box?.addEventListener('change', () => syncCompletion(box));
        },
        preConfirm: () => {
            const read = readCompletion(swal.getPopup()?.querySelector('[data-completion]'), {late});
            if (read.error) {
                swal.showValidationMessage(read.error);
                return false;
            }

            return read.fields;
        },
    });

    return result?.isConfirmed ? result.value : null;
}
