/*
 * กล่องก่อนเริ่มงานประจำ — ถามทุกอย่างที่ server ขอในครั้งเดียว
 *
 * server (RoutineAccountabilityService::start) ตอบ 422 พร้อม requirements เมื่อยังขาด:
 *   backlog    — วันที่ค้างของงานเดียวกันที่ยังไม่มีเหตุผล (ต้องครบทุกวัน)
 *   attendance — ผู้ร่วมงานที่วันนี้ยังไม่เริ่ม ต้องตอบว่ามาทำด้วยหรือไม่มา
 * กล่องนี้แค่เก็บคำตอบแล้วส่งกลับไปที่ endpoint เดิม กติกาจริงตรวจซ้ำที่ server เสมอ
 */
const OTHER_REASON = 'อื่น ๆ';

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');

/** unfinished = เริ่มแล้วไม่กดเสร็จ ถามคนละชุดกับ "ไม่ได้เริ่ม / ไม่มา" */
const reasonsFor = (reasons, type) => [...(type === 'unfinished' ? reasons.unfinished : reasons.missed) || [], OTHER_REASON];

export const startRequirementsMarkup = ({backlog = [], attendance = [], reasons = {}} = {}) => {
    const days = backlog.map((day) => `<div class="routine-start__day" data-backlog-day data-date="${escapeHtml(day.date)}">
        <div class="routine-start__day-head">
            <strong>${escapeHtml(day.date_label)}</strong>
            <span class="routine-start__tag routine-start__tag--${escapeHtml(day.type)}">${escapeHtml(day.status_label)}</span>
        </div>
        ${day.absent_marked_by ? `<small>${escapeHtml(day.absent_marked_by)} ระบุว่าไม่มา</small>` : ''}
        <select data-backlog-reason aria-label="เหตุผลของวันที่ ${escapeHtml(day.date_label)}">
            <option value="">เลือกเหตุผล</option>
            ${reasonsFor(reasons, day.type).map((reason) => `<option value="${escapeHtml(reason)}">${escapeHtml(reason)}</option>`).join('')}
        </select>
        <input type="text" maxlength="500" placeholder="พิมพ์เหตุผล" data-backlog-other hidden>
    </div>`).join('');

    const people = attendance.map((person) => `<div class="routine-start__person" data-attendance-person data-user-id="${escapeHtml(person.id)}">
        <span>${escapeHtml(person.name)}</span>
        <label><input type="radio" name="routine-attendance-${escapeHtml(person.id)}" value="present" checked> มาทำด้วย</label>
        <label><input type="radio" name="routine-attendance-${escapeHtml(person.id)}" value="absent"> ไม่มา</label>
    </div>`).join('');

    return `<div class="routine-start" data-routine-start>
        ${backlog.length ? `<section class="routine-start__section"><p class="routine-start__heading">ระบุเหตุผลของวันที่ค้าง (${backlog.length} วัน)</p>${days}</section>` : ''}
        ${attendance.length ? `<section class="routine-start__section"><p class="routine-start__heading">วันนี้ผู้ร่วมงานมาทำด้วยไหม</p>${people}</section>` : ''}
    </div>`;
};

/** แสดงช่องพิมพ์เหตุผลเฉพาะวันที่เลือก "อื่น ๆ" */
export const syncStartRequirements = (box) => {
    box?.querySelectorAll('[data-backlog-day]').forEach((day) => {
        const other = day.querySelector('[data-backlog-other]');
        if (other) other.hidden = day.querySelector('[data-backlog-reason]')?.value !== OTHER_REASON;
    });
};

/** คืน {fields} ในรูปแบบที่ส่งให้ endpoint start ได้ทันที หรือ {error} เมื่อยังตอบไม่ครบ */
export const readStartRequirements = (box) => {
    const fields = {};

    for (const day of box?.querySelectorAll('[data-backlog-day]') || []) {
        const selected = day.querySelector('[data-backlog-reason]')?.value || '';
        const reason = selected === OTHER_REASON
            ? (day.querySelector('[data-backlog-other]')?.value.trim() || '')
            : selected;
        if (! reason) return {error: 'กรุณาระบุเหตุผลให้ครบทุกวันที่ค้าง'};
        fields[`backlog_reasons[${day.dataset.date}]`] = reason;
    }

    for (const person of box?.querySelectorAll('[data-attendance-person]') || []) {
        const answer = person.querySelector('input[type="radio"]:checked')?.value;
        if (! answer) return {error: 'กรุณาตอบว่าผู้ร่วมงานแต่ละคนมาทำด้วยหรือไม่'};
        fields[`attendance[${person.dataset.userId}]`] = answer;
    }

    return {fields};
};

export async function askStartRequirements({swal, requirements = {}, reasons = {}, title = ''} = {}) {
    if (! swal) return null;

    const result = await swal.fire({
        titleText: title ? `ก่อนเริ่มงาน: ${title}` : 'ก่อนเริ่มงาน',
        html: startRequirementsMarkup({backlog: requirements.backlog || [], attendance: requirements.attendance || [], reasons}),
        showCancelButton: true,
        confirmButtonText: 'บันทึกและเริ่มงาน',
        cancelButtonText: 'ยกเลิก',
        focusConfirm: false,
        didOpen: (popup) => {
            const box = popup.querySelector('[data-routine-start]');
            box?.addEventListener('change', () => syncStartRequirements(box));
        },
        preConfirm: () => {
            const read = readStartRequirements(swal.getPopup()?.querySelector('[data-routine-start]'));
            if (read.error) {
                swal.showValidationMessage(read.error);
                return false;
            }

            return read.fields;
        },
    });

    return result?.isConfirmed ? result.value : null;
}
