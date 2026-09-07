/*
 * จุดเริ่มต้นของหน้าบันทึกงานประจำวัน
 *
 * ประกอบสามส่วนเข้าด้วยกัน: ช่องบันทึกเร็ว ไทม์ไลน์ และฟอร์มเต็มแบบ modal
 * โดยแต่ละส่วนไม่รู้จักกันเอง ไฟล์นี้เป็นที่เดียวที่ผูกพฤติกรรมข้ามส่วน
 *
 * ห้ามใช้ alert/confirm ตามกติกาของโปรเจกต์ การยืนยันทุกอย่างผ่าน window.Swal
 */
import {initAttachments} from './attachments.js';
import {firstErrorMessage, sendAction, submitLogForm} from './client.js';
import {initEntryForm} from './entry-form.js';
import {initParticipantPickers} from './participants.js';
import {applySummary} from './summary.js';
import {initTimeline} from './timeline.js';
import {initTimerBanner} from './timer.js';

const readJson = (doc, id, fallback) => {
    try {
        const node = doc.getElementById(id);

        return node ? JSON.parse(node.textContent) : fallback;
    } catch {
        return fallback;
    }
};

export function initDailyLogs({doc = document, swal = globalThis.Swal} = {}) {
    const root = doc.querySelector('[data-daily-log]');

    if (! root || root.dataset.dailyLogReady === 'on') return null;

    root.dataset.dailyLogReady = 'on';

    const routes = readJson(doc, 'work-log-routes', {});
    const dayLogs = readJson(doc, 'work-log-day', []);
    const byId = new Map((Array.isArray(dayLogs) ? dayLogs : []).map((log) => [String(log.id), log]));

    const participantPickers = initParticipantPickers({root});

    const entryForm = initEntryForm({
        root,
        storeAction: routes.store || '',
        updateActionTemplate: routes.update || '',
    });

    const composer = root.querySelector('[data-log-composer]');
    const composerError = root.querySelector('[data-composer-error]');

    const showComposerError = (message) => {
        if (! composerError) return;

        composerError.textContent = message || '';
        composerError.hidden = ! message;
    };

    /** นำผลลัพธ์จากเซิร์ฟเวอร์มาปรับหน้าจอ โดยไม่คำนวณตัวเลขเอง */
    const applyResult = (payload) => {
        if (payload?.log) byId.set(String(payload.log.id), payload.log);
        if (payload?.html) timeline?.upsertCard(payload.html, payload.log?.id);
        applySummary(payload?.summary, {root});
        timerBanner?.syncWith(payload?.log);
    };

    /**
     * ข้อผิดพลาดที่ผู้ใช้ต้องรู้ทันที เช่น "มีงานที่กำลังจับเวลาอยู่แล้ว"
     *
     * กติกาหนึ่งคนหนึ่งตัวจับเวลาถูกบังคับที่ฐานข้อมูล การเปิดสองแท็บแล้วกดเริ่ม
     * ทั้งคู่จึงล้มเหลวได้อย่างถูกต้อง แต่ต้องบอกผู้ใช้ให้โหลดหน้าใหม่เพื่อเห็น
     * สถานะจริง ไม่ใช่ปล่อยให้ปุ่มเงียบไปเฉย ๆ
     */
    const reportTimerError = (error) => swal?.fire({
        icon: 'warning',
        title: 'เริ่มจับเวลาไม่ได้',
        text: error.message,
        confirmButtonText: 'โหลดใหม่',
        showCancelButton: true,
        cancelButtonText: 'ปิด',
    }).then((result) => {
        if (result?.isConfirmed) doc.defaultView?.location.reload();
    });

    const timerBanner = initTimerBanner({
        root,
        onStop: async (logId) => {
            if (! logId) return;

            try {
                applyResult(await sendAction(
                    (routes.timerStop || '').replace('__ID__', String(logId)),
                    {doc}
                ));
            } catch (error) {
                swal?.fire({icon: 'error', title: 'หยุดเวลาไม่สำเร็จ', text: error.message});
            }
        },
    });

    // ไฟล์แนบต้องมีบันทึกอยู่ก่อนแล้ว จึงเปิดใช้เฉพาะตอนแก้ไข
    const attachments = initAttachments({
        root,
        storeTemplate: routes.attachmentStore || '',
        destroyTemplate: routes.attachmentDestroy || '',
        swal,
        // จำนวนไฟล์แนบแสดงอยู่ในแถวไทม์ไลน์ด้วย จึงรีเฟรชแถวจาก HTML ที่
        // เซิร์ฟเวอร์ส่งมา เส้นทางเดียวกับการบันทึก/แก้ไข
        onChange: (payload) => applyResult(payload),
    });

    const timeline = initTimeline({
        root,
        onEdit: (logId, card) => {
            const log = byId.get(String(logId));

            entryForm?.openForEdit(log, card?.querySelector('[data-log-menu-trigger]'));
            attachments?.attachTo(log);
        },
        onDelete: async (logId, card) => {
            const target = byId.get(String(logId));
            const confirmed = await swal?.fire({
                icon: 'warning',
                title: 'ลบบันทึกงานนี้?',
                text: target?.title || '',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc3545',
                reverseButtons: true,
                focusCancel: true,
            });

            if (! confirmed?.isConfirmed) return;

            try {
                const payload = await sendAction(
                    (routes.destroy || '').replace('__ID__', String(logId)),
                    {method: 'DELETE', doc}
                );

                timeline?.removeCard(logId);
                byId.delete(String(logId));
                applySummary(payload?.summary, {root});
            } catch (error) {
                swal?.fire({icon: 'error', title: 'ลบไม่สำเร็จ', text: error.message});
            }
        },
    });

    // ช่องบันทึกเร็ว — เส้นทางที่ผู้ใช้เดินบ่อยที่สุด
    composer?.addEventListener('submit', async (event) => {
        event.preventDefault();
        showComposerError('');

        const submit = composer.querySelector('[data-composer-submit]');
        submit?.setAttribute('disabled', 'disabled');

        try {
            const payload = await submitLogForm(composer, {doc});

            applyResult(payload);
            composer.reset();
            composer.querySelector('[data-composer-title]')?.focus();
        } catch (error) {
            showComposerError(error.message);
        } finally {
            submit?.removeAttribute('disabled');
        }
    });

    composer?.addEventListener('click', async (event) => {
        // "ระบุเวลา / รายละเอียด" — ยกสิ่งที่พิมพ์ค้างไว้ไปต่อในฟอร์มเต็ม
        // ไม่ให้ผู้ใช้ต้องพิมพ์ชื่องานใหม่อีกรอบ
        const openModal = event.target.closest('[data-open-entry-modal]');

        if (openModal) {
            event.preventDefault();
            entryForm?.openForCreate({
                title: composer.querySelector('[data-composer-title]')?.value || '',
                kind: composer.querySelector('[name="kind"]:checked')?.value || 'routine',
                categoryId: composer.querySelector('[name="work_log_category_id"]')?.value || '',
                workDate: root.dataset.date || '',
            }, openModal);

            // ยังไม่มีบันทึกให้แนบไฟล์เข้าไป จึงซ่อนบล็อกไฟล์แนบไว้ก่อน
            attachments?.detach();

            return;
        }

        // "เริ่มงาน" — สร้างรายการแล้วเริ่มจับเวลาในคำสั่งเดียว
        const start = event.target.closest('[data-composer-start]');

        if (! start) return;

        event.preventDefault();

        const title = composer.querySelector('[data-composer-title]')?.value.trim() || '';

        if (title === '') {
            showComposerError('กรุณาระบุงานที่กำลังทำก่อนเริ่มจับเวลา');
            composer.querySelector('[data-composer-title]')?.focus();

            return;
        }

        showComposerError('');
        start.setAttribute('disabled', 'disabled');

        try {
            applyResult(await sendAction(routes.timerStart || '', {
                doc,
                fields: {
                    title,
                    kind: composer.querySelector('[name="kind"]:checked')?.value || 'routine',
                    work_log_category_id: composer.querySelector('[name="work_log_category_id"]')?.value || '',
                },
                // ผู้ร่วมงานเป็นค่าหลายตัว จึงส่งแยกจาก fields ที่เป็นคู่คีย์เดียว
                repeated: {
                    'participants[]': participantPickers?.selectedIn(
                        composer.querySelector('[data-participant-picker]')
                    ) || [],
                },
            }));

            composer.reset();
            participantPickers?.refreshAll();
        } catch (error) {
            await reportTimerError(error);
        } finally {
            start.removeAttribute('disabled');
        }
    });

    // ปุ่มจับเวลาในแต่ละแถว — ใช้ delegation เพราะแถวถูกแทนที่ได้ตลอดเวลา
    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-row-timer-start], [data-row-timer-stop]');

        if (! button) return;

        event.preventDefault();

        const logId = button.closest('[data-log-card]')?.dataset.logId;

        if (! logId) return;

        const isStart = button.hasAttribute('data-row-timer-start');
        const url = (isStart ? routes.timerResume : routes.timerStop || '').replace('__ID__', String(logId));

        button.setAttribute('disabled', 'disabled');

        try {
            applyResult(await sendAction(url, {doc}));
        } catch (error) {
            if (isStart) await reportTimerError(error);
            else swal?.fire({icon: 'error', title: 'หยุดเวลาไม่สำเร็จ', text: error.message});
        } finally {
            button.removeAttribute('disabled');
        }
    });

    // ฟอร์มเต็ม
    const entryFormNode = root.querySelector('[data-log-entry-form]');

    entryFormNode?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const submit = entryFormNode.querySelector('[data-entry-submit]');
        submit?.setAttribute('disabled', 'disabled');

        try {
            const payload = await submitLogForm(entryFormNode, {doc});

            applyResult(payload);
            composer?.reset();
            entryForm?.close();
        } catch (error) {
            entryForm?.showError(error.message);
        } finally {
            submit?.removeAttribute('disabled');
        }
    });

    // เปลี่ยนคนที่ดูอยู่ — ส่งฟอร์มทันทีโดยไม่ต้องกดปุ่มเพิ่ม
    root.querySelector('[data-member-select]')?.addEventListener('change', (event) => {
        event.target.form?.submit();
    });

    return {timeline, entryForm, timerBanner, applyResult, firstErrorMessage};
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initDailyLogs());
    } else {
        initDailyLogs();
    }
}
