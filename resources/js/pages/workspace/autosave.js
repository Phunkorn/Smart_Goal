/*
 * ต่อสายระหว่างตัวจัดตารางบันทึก เซิร์ฟเวอร์ ตัวบอกสถานะ และกล่องเตือน
 *
 * แยกจาก index.js เพราะเป็นเรื่องของ "วงจรชีวิตของการบันทึก" ล้วน ๆ ไม่เกี่ยวกับ
 * การวาด และเพราะเป็นส่วนที่ต้องทดสอบสถานการณ์การชนเวอร์ชันแยกจากการวาด
 *
 * กติกาที่ห้ามละเมิด
 * ---------------------------------------------------------------
 * 1. ห้ามรวมงานของสองคนเข้าด้วยกันเอง (auto-merge) เด็ดขาด
 * 2. ห้ามยิงบันทึกด้วยเวอร์ชันเก่าหลังจากที่รู้แล้วว่าชนกัน
 * 3. ผู้ใช้ต้องเป็นคนตัดสินใจว่าจะทิ้งงานที่เพิ่งวาดหรือไม่ ไม่ใช่โปรแกรม
 */

import {createSaveScheduler} from './save-state.js';

export const initAutosave = ({
    root,
    doc = root?.ownerDocument || globalThis.document,
    client,
    design,
    capabilities = {},
    initialVersion = 1,
    getDocument,
    onReplaceDocument,
    swal = globalThis.Swal,
    now,
    setTimeoutImpl,
    clearTimeoutImpl,
}) => {
    const indicator = root?.querySelector('[data-workspace-save-state]');
    const refreshButton = root?.querySelector('[data-workspace-refresh]');
    const labels = design?.saveStates || {};
    const dialog = design?.conflictDialog || {};

    let version = initialVersion;
    let savedAtLabel = null;

    const paint = (state, detail = {}) => {
        if (detail.saved_at_label) {
            savedAtLabel = detail.saved_at_label;
        }

        if (! indicator) {
            return;
        }

        const meta = labels[state] || {label: state, icon: 'bi-cloud', tone: 'gray'};
        const suffix = state === 'saved' && savedAtLabel ? ` ${savedAtLabel}` : '';

        indicator.dataset.state = state;
        indicator.className = `wsb-save wsb-save--${meta.tone}`;

        // เขียนด้วย textContent เท่านั้น ไอคอนเป็นโหนดแยกที่สลับคลาสเอา
        const icon = indicator.querySelector('i');
        const text = indicator.querySelector('[data-workspace-save-label]');

        if (icon) {
            icon.className = `bi ${meta.icon}`;
        }

        if (text) {
            text.textContent = `${meta.label}${suffix}`;
        }
    };

    // handleConflict ถูกประกาศหลังจากนี้ จึงถือไว้ในตัวแปรที่เติมค่าทีหลัง
    // แทนการอ้างชื่อฟังก์ชันตรง ๆ ซึ่งจะติด temporal dead zone
    let onConflict = null;

    const scheduler = createSaveScheduler({
        now,
        setTimeoutImpl,
        clearTimeoutImpl,
        onState: (state, detail) => {
            paint(state, detail);

            // การชนเวอร์ชันต้องถามผู้ใช้ทันทีที่เกิด ไม่ใช่รอให้เขากดบันทึกเอง
            // เพราะการบันทึกอัตโนมัติหยุดไปแล้วและเขาอาจวาดต่อไปอีกนานโดยไม่รู้ตัว
            if (state === 'conflict') {
                onConflict?.(detail?.error?.payload || {});
            }
        },
        save: async () => {
            const payload = await client.save({document: getDocument(), baseVersion: version});

            version = payload.version ?? version + 1;

            return payload;
        },
    });

    /**
     * แทนเนื้อหาบนหน้าจอด้วยฉบับล่าสุดจากเซิร์ฟเวอร์
     *
     * ต้องรับเวอร์ชันใหม่ก่อนแล้วค่อยเปิดการบันทึกอีกครั้ง มิฉะนั้นการบันทึก
     * ครั้งถัดไปจะยังถือเวอร์ชันเก่าและชนซ้ำทันที
     */
    const adopt = (payload) => {
        version = payload.version ?? version;
        savedAtLabel = payload.saved_at_label ?? savedAtLabel;
        onReplaceDocument?.(payload.document);
        scheduler.resume();
    };

    const handleConflict = async (payload) => {
        const savedBy = payload?.saved_by?.name;
        const savedAt = payload?.saved_at_label;
        const detail = [savedBy, savedAt].filter(Boolean).join(' เมื่อ ');

        const result = await swal?.fire({
            icon: 'warning',
            title: dialog.title,
            text: detail ? `${detail}\n${dialog.warning}` : dialog.warning,
            showCancelButton: true,
            confirmButtonText: dialog.confirm,
            cancelButtonText: dialog.cancel,
        });

        if (result?.isConfirmed) {
            // ใช้เนื้อหาที่มากับ 409 เลย ไม่ต้องยิง GET ตามอีกรอบขณะที่ผู้ใช้รออยู่
            adopt(payload);

            return;
        }

        // ไม่โหลดตอนนี้ = หยุดบันทึกอัตโนมัติค้างไว้ เครื่องมือยังใช้ได้เพื่อให้
        // ผู้ใช้คัดลอกงานของตัวเองไว้ก่อน แต่จะไม่มีทางเขียนทับงานของคนอื่น
        paint('conflict');
    };

    onConflict = handleConflict;

    const refresh = async ({confirmDirty = true} = {}) => {
        if (confirmDirty && scheduler.isDirty()) {
            const result = await swal?.fire({
                icon: 'warning',
                title: dialog.title,
                text: dialog.refreshDirty,
                showCancelButton: true,
                confirmButtonText: dialog.confirm,
                cancelButtonText: dialog.cancel,
            });

            if (! result?.isConfirmed) {
                return;
            }
        }

        try {
            adopt(await client.load());
        } catch (error) {
            await swal?.fire({icon: 'error', title: 'โหลดกระดานไม่สำเร็จ', text: error.message});
        }
    };

    refreshButton?.addEventListener('click', () => refresh());

    // ผู้ที่ดูอย่างเดียวไม่มีอะไรให้บันทึก ผูกเฉพาะปุ่มรีเฟรชก็พอ
    if (capabilities.canEdit !== true) {
        paint('saved');

        return {refresh, scheduler: null, currentVersion: () => version};
    }

    paint('saved');

    // สลับแท็บออกไปคือจังหวะที่คนมักปิดหน้าต่อ ต้องรีบบันทึกให้ทัน
    doc.addEventListener('visibilitychange', () => {
        if (doc.visibilityState === 'hidden') {
            scheduler.flushNow();
        }
    });

    /*
     * เตือนก่อนออกจากหน้าเมื่อยังมีงานค้าง
     *
     * preventDefault() ทำให้เบราว์เซอร์แสดงกล่องของตัวเอง ไม่ใช่การเรียก
     * window.confirm() จึงไม่ขัดกับกติกาใน CLAUDE.md ที่ห้ามใช้กล่องโต้ตอบ
     * ของเบราว์เซอร์ในโค้ดของเรา
     *
     * ตั้งใจไม่ใช้ sendBeacon หรือ fetch แบบ keepalive เพราะทั้งคู่มีเพดาน
     * ขนาด 64 KB ซึ่งเนื้อหากระดานจริงเกินได้ง่าย และการถูกตัดจะเงียบสนิท
     */
    doc.defaultView?.addEventListener('beforeunload', (event) => {
        if (scheduler.isDirty()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    return {
        refresh,
        scheduler,
        currentVersion: () => version,

        /** เรียกทุกครั้งที่เนื้อหากระดานเปลี่ยน */
        markDirty: () => scheduler.markDirty(),

        beginGesture: () => scheduler.beginGesture(),
        endGesture: () => scheduler.endGesture(),

        /** บันทึกทันที (ปุ่มบันทึก) การชนเวอร์ชันถูกจัดการผ่าน onState */
        saveNow: () => scheduler.flushNow(),

        handleConflict,
    };
};
