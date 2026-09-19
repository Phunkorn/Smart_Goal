/*
 * กล่องก่อนเริ่มงานประจำ — ถามทุกอย่างที่ server ขอในครั้งเดียว
 *
 * server (RoutineAccountabilityService::start) ตอบ 422 พร้อม requirements เมื่อยังขาด:
 *   backlog    — วันที่ค้างของงานเดียวกันที่ยังไม่มีเหตุผล (ต้องครบทุกวัน)
 *   attendance — ผู้ร่วมงานที่วันนี้ยังไม่เริ่ม ต้องตอบว่ามาทำด้วยหรือไม่มา
 *                คนที่ "มาทำด้วย" เริ่มพร้อมกันทันที ถ้าเขายังค้างเหตุผลของวันก่อน คนกดเริ่มตอบแทนที่นี่
 *                (วันค้างของคนนั้นแสดงใต้ชื่อ และซ่อนเมื่อเลือกว่า "ไม่มา" เพราะเขาจะตอบเองเมื่อกลับมา)
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

/**
 * วันค้างหนึ่งวัน — ใช้ทั้งวันของคนกดเริ่ม (data-backlog-day) และของผู้ร่วมงาน (data-member-backlog-day)
 * แยกชื่อ attribute เพื่อให้วันของผู้ร่วมงานไม่ปนไปอยู่ใน backlog_reasons ของคนกด
 */
const dayMarkup = (day, reasons, marker = 'data-backlog-day') => `<div class="routine-start__day" ${marker} data-date="${escapeHtml(day.date)}">
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
    </div>`;

export const startRequirementsMarkup = ({backlog = [], attendance = [], reasons = {}} = {}) => {
    const days = backlog.map((day) => dayMarkup(day, reasons)).join('');

    const people = attendance.map((person) => {
        const personBacklog = Array.isArray(person.backlog) ? person.backlog : [];

        return `<div class="routine-start__person" data-attendance-person data-user-id="${escapeHtml(person.id)}">
        <span>${escapeHtml(person.name)}</span>
        <label><input type="radio" name="routine-attendance-${escapeHtml(person.id)}" value="present" checked> มาทำด้วย</label>
        <label><input type="radio" name="routine-attendance-${escapeHtml(person.id)}" value="absent"> ไม่มา</label>
        ${personBacklog.length ? `<div class="routine-start__member-backlog" data-member-backlog>
            <p class="routine-start__member-note">${escapeHtml(person.name)} ยังไม่ได้ระบุเหตุผลของวันที่ค้าง (${personBacklog.length} วัน)</p>
            ${personBacklog.map((day) => dayMarkup(day, reasons, 'data-member-backlog-day')).join('')}
        </div>` : ''}
    </div>`;
    }).join('');

    return `<div class="routine-start" data-routine-start>
        ${backlog.length ? `<section class="routine-start__section"><p class="routine-start__heading">ระบุเหตุผลของวันที่ค้าง (${backlog.length} วัน)</p>${days}</section>` : ''}
        ${attendance.length ? `<section class="routine-start__section"><p class="routine-start__heading">วันนี้ผู้ร่วมงานมาทำด้วยไหม</p>${people}</section>` : ''}
    </div>`;
};

/** แสดงช่องพิมพ์เหตุผลเฉพาะวันที่เลือก "อื่น ๆ" และซ่อนวันค้างของคนที่ตอบว่าไม่มา */
export const syncStartRequirements = (box) => {
    box?.querySelectorAll('[data-backlog-day], [data-member-backlog-day]').forEach((day) => {
        const other = day.querySelector('[data-backlog-other]');
        if (other) other.hidden = day.querySelector('[data-backlog-reason]')?.value !== OTHER_REASON;
    });

    box?.querySelectorAll('[data-attendance-person]').forEach((person) => {
        const memberBacklog = person.querySelector('[data-member-backlog]');
        if (memberBacklog) memberBacklog.hidden = person.querySelector('input[type="radio"]:checked')?.value !== 'present';
    });
};

const dayReason = (day) => {
    const selected = day.querySelector('[data-backlog-reason]')?.value || '';

    return selected === OTHER_REASON
        ? (day.querySelector('[data-backlog-other]')?.value.trim() || '')
        : selected;
};

/** คืน {fields} ในรูปแบบที่ส่งให้ endpoint start ได้ทันที หรือ {error} เมื่อยังตอบไม่ครบ */
export const readStartRequirements = (box) => {
    const fields = {};

    for (const day of box?.querySelectorAll('[data-backlog-day]') || []) {
        const reason = dayReason(day);
        if (! reason) return {error: 'กรุณาระบุเหตุผลให้ครบทุกวันที่ค้าง'};
        fields[`backlog_reasons[${day.dataset.date}]`] = reason;
    }

    for (const person of box?.querySelectorAll('[data-attendance-person]') || []) {
        const answer = person.querySelector('input[type="radio"]:checked')?.value;
        if (! answer) return {error: 'กรุณาตอบว่าผู้ร่วมงานแต่ละคนมาทำด้วยหรือไม่'};
        fields[`attendance[${person.dataset.userId}]`] = answer;

        // คนที่ไม่มาวันนี้จะตอบเหตุผลของตัวเองเมื่อกลับมา จึงไม่ส่งและไม่บังคับ
        if (answer !== 'present') continue;

        for (const day of person.querySelectorAll('[data-member-backlog-day]')) {
            const reason = dayReason(day);
            if (! reason) return {error: 'กรุณาระบุเหตุผลวันค้างของผู้ร่วมงานที่มาทำด้วยให้ครบ'};
            fields[`member_backlog_reasons[${person.dataset.userId}][${day.dataset.date}]`] = reason;
        }
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
