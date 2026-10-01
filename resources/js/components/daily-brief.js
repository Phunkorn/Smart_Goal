import {modalStack} from './modal-stack.js';

/*
 * สรุปประจำวัน (Daily Brief) — modal ที่เปิดเองบนหน้าแรกของวันที่ยังไม่ได้รับทราบ
 *
 * ชั้น backdrop การล็อก body การจับโฟกัส และ Escape เป็นหน้าที่ของ modal-stack ซึ่ง
 * เป็นเจ้าของสถานะ overlay เพียงเจ้าเดียว ไฟล์นี้จึงไม่แตะ z-index ไม่เพิ่ม class ที่ body
 * และไม่ผูก Escape เอง หน้า "งานของฉัน" ที่เปิด Task Workspace จาก ?open_task= ก็ใช้
 * modal-stack ตัวเดียวกัน สองกล่องจึงซ้อนกันได้โดยไม่แย่งโฟกัสกัน
 *
 * กติกาที่ตกลงกันไว้
 *   - "รับทราบและเข้าสู่ระบบ" = บันทึกลงฐานข้อมูล วันนั้นไม่แสดงอีกแม้ login ใหม่
 *   - ปุ่ม X / Escape = ปิดไว้ก่อน ไม่บันทึก จะแสดงอีกเมื่อเปิดหน้าถัดไป
 *   - กดแถวงานหรือลิงก์ในสรุป = นับเป็นการรับทราบ แล้วพาไปที่งานนั้น
 *     (ถ้าบันทึกไม่สำเร็จก็ยังพาไป เพราะผู้ใช้ตั้งใจจะไปทำงานนั้นแล้ว)
 *   - ปุ่ม "ดูทั้งหมด" = เปิดรายการเต็มซ้อนบนสรุป ไม่ออกจากหน้าและไม่นับเป็นการรับทราบ
 *
 * ข้อผิดพลาดแสดงเป็นข้อความในกล่อง ไม่ใช้ SweetAlert เพราะ SweetAlert อยู่ z-index
 * ต่ำกว่าชั้นของ modal-stack จึงจะถูกกล่องนี้บังจนมองไม่เห็น
 */

export const ACKNOWLEDGE_RESULT = Object.freeze({
    OK: 'ok',
    STALE: 'stale',
    ERROR: 'error',
    BUSY: 'busy',
});

/** แปลงรหัสตอบกลับเป็นผลลัพธ์ — แยกออกมาให้ทดสอบได้โดยไม่ต้องมี DOM */
export function resultForStatus(status) {
    if (status >= 200 && status < 300) return ACKNOWLEDGE_RESULT.OK;
    // สรุปที่เปิดค้างข้ามเที่ยงคืน server ปฏิเสธเพื่อไม่ให้รับทราบวันใหม่แทนผู้ใช้
    if (status === 409) return ACKNOWLEDGE_RESULT.STALE;

    return ACKNOWLEDGE_RESULT.ERROR;
}

/** ลิงก์ที่ควรให้เบราว์เซอร์จัดการเอง เช่นกด Ctrl/Cmd เพื่อเปิดแท็บใหม่ */
export function isModifiedClick(event) {
    return event.button !== 0 || Boolean(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey);
}

export async function postAcknowledge(modal, fetchImpl, doc) {
    const token = doc.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const response = await fetchImpl(modal.dataset.acknowledgeUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({brief_date: modal.dataset.briefDate}),
    });

    return response.status;
}

export function initDailyBrief(root = document, options = {}) {
    const modal = root.querySelector('[data-daily-brief]');

    // เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ
    if (! modal || modal.dataset.dailyBriefReady === 'true') {
        return null;
    }

    const view = root.defaultView || globalThis.window;
    const {
        stack = modalStack(root),
        fetchImpl = (...args) => view.fetch(...args),
        navigate = (url) => view.location.assign(url),
        reload = () => view.location.reload(),
    } = options;

    modal.dataset.dailyBriefReady = 'true';

    const acknowledgeButton = modal.querySelector('[data-daily-brief-acknowledge]');
    let pending = null;

    const showError = (message) => {
        let error = modal.querySelector('[data-daily-brief-error]');

        if (! error) {
            error = root.createElement('p');
            error.className = 'daily-brief__error';
            error.setAttribute('role', 'alert');
            error.dataset.dailyBriefError = '';
            acknowledgeButton?.before(error);
        }

        error.textContent = message;
    };

    const dismiss = () => stack.close(modal);

    const acknowledge = () => {
        // กดรัว ๆ หรือกดปุ่มพร้อมลิงก์ ต้องได้คำขอเดียว
        if (pending) return pending;

        if (acknowledgeButton) acknowledgeButton.disabled = true;

        pending = postAcknowledge(modal, fetchImpl, root)
            .then(resultForStatus)
            .catch(() => ACKNOWLEDGE_RESULT.ERROR)
            .finally(() => {
                pending = null;
                if (acknowledgeButton) acknowledgeButton.disabled = false;
            });

        return pending;
    };

    acknowledgeButton?.addEventListener('click', async () => {
        const result = await acknowledge();

        if (result === ACKNOWLEDGE_RESULT.OK) {
            stack.close(modal);
        } else if (result === ACKNOWLEDGE_RESULT.STALE) {
            reload();
        } else {
            showError('บันทึกการรับทราบไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        }
    });

    // กดแถวงาน = รับทราบก่อนแล้วพาไปที่งาน ใช้ทั้งในสรุปและในกล่อง "ดูทั้งหมด"
    const followLink = async (event) => {
        const link = event.target.closest('[data-daily-brief-link]');
        if (! link || isModifiedClick(event)) return;

        event.preventDefault();
        await acknowledge();
        navigate(link.href);
    };

    /*
     * กล่อง "ดูทั้งหมด" เปิดซ้อนบนสรุปผ่าน modal-stack ตัวเดียวกัน ปิดแล้วกลับมาที่สรุป
     * โดยไม่ออกจากหน้า (เดิมเป็นลิงก์ไปหน้างาน ผู้ใช้ต้องออกจากสรุปเพื่อดูรายการครบ)
     */
    const lists = new Map();
    root.querySelectorAll('[data-daily-brief-list]').forEach((list) => {
        lists.set(list.dataset.dailyBriefList, list);

        const closeList = () => stack.close(list);
        list.addEventListener('click', (event) => {
            if (event.target.closest('[data-daily-brief-list-close]') || event.target === list) {
                closeList();

                return;
            }

            followLink(event);
        });
        // Escape ปิดเฉพาะกล่องรายการที่อยู่บนสุด สรุปข้างล่างยังเปิดอยู่
        list.addEventListener('modalstack:dismiss', closeList);
    });

    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-daily-brief-dismiss]')) {
            dismiss();

            return;
        }

        const expand = event.target.closest('[data-daily-brief-expand]');
        if (expand) {
            const list = lists.get(expand.dataset.dailyBriefExpand);
            if (list) stack.open(list, expand);

            return;
        }

        followLink(event);
    });

    // modal-stack เป็นผู้ตัดสินว่า Escape ตกที่ชั้นไหน แล้วส่งสัญญาณมาให้ปิด
    modal.addEventListener('modalstack:dismiss', dismiss);

    stack.open(modal);

    return {modal, acknowledge, dismiss};
}

if (typeof document !== 'undefined') {
    initDailyBrief(document);
}
