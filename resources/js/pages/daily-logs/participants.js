/*
 * ตัวเลือกผู้ร่วมงาน
 *
 * ตัว <details> จัดการการเปิด/ปิดและคีย์บอร์ดให้เองอยู่แล้ว โมดูลนี้จึงเหลือ
 * หน้าที่เดียวคือทำให้จำนวนที่เลือกไว้เห็นได้จากภายนอกโดยไม่ต้องกางออกดู
 * เพราะเมื่อพับอยู่ ผู้ใช้จะไม่รู้เลยว่าติ๊กใครไว้บ้าง
 *
 * ใช้ event delegation ที่ระดับหน้า ตัวเลือกที่อยู่ในฟอร์มเต็ม (modal) ซึ่งถูก
 * เติมค่าใหม่ทุกครั้งที่เปิด จึงทำงานได้โดยไม่ต้องผูก listener ซ้ำ
 */
const countOf = (picker) => picker.querySelectorAll('[data-participant-checkbox]:checked').length;

/** ปรับป้ายจำนวนของตัวเลือกหนึ่งชุดให้ตรงกับที่ติ๊กไว้จริง */
export function refreshParticipantCount(picker) {
    const badge = picker?.querySelector('[data-participant-count]');

    if (! badge) return 0;

    const count = countOf(picker);

    badge.textContent = String(count);
    badge.hidden = count === 0;

    return count;
}

export function initParticipantPickers({root = document.querySelector('[data-daily-log]')} = {}) {
    if (! root || root.dataset.participantsReady === 'on') return null;

    root.dataset.participantsReady = 'on';

    root.addEventListener('change', (event) => {
        if (! event.target.matches('[data-participant-checkbox]')) return;

        refreshParticipantCount(event.target.closest('[data-participant-picker]'));
    });

    root.querySelectorAll('[data-participant-picker]').forEach(refreshParticipantCount);

    return {
        /** ค่าที่ติ๊กไว้ในตัวเลือกชุดหนึ่ง ใช้ตอนส่งคำขอที่ไม่ได้ผ่าน FormData ของฟอร์ม */
        selectedIn(picker) {
            return [...(picker?.querySelectorAll('[data-participant-checkbox]:checked') || [])]
                .map((input) => input.value);
        },
        /** ติ๊กให้ตรงกับรายการที่ส่งมา ใช้ตอนเปิดฟอร์มเต็มเพื่อแก้ไขบันทึกเดิม */
        applySelection(picker, ids = []) {
            if (! picker) return;

            const wanted = new Set(ids.map(String));

            picker.querySelectorAll('[data-participant-checkbox]').forEach((input) => {
                input.checked = wanted.has(String(input.value));
            });

            refreshParticipantCount(picker);
        },
        refreshAll() {
            root.querySelectorAll('[data-participant-picker]').forEach(refreshParticipantCount);
        },
    };
}
