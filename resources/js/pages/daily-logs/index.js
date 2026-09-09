/*
 * จุดเริ่มต้นของหน้าบันทึกงานประจำวัน
 *
 * ประกอบสามส่วนเข้าด้วยกัน: ปุ่มเพิ่มงาน ไทม์ไลน์ และกล่องฟอร์ม
 * โดยแต่ละส่วนไม่รู้จักกันเอง ไฟล์นี้เป็นที่เดียวที่ผูกพฤติกรรมข้ามส่วน
 *
 * ห้ามใช้ alert/confirm ตามกติกาของโปรเจกต์ การยืนยันทุกอย่างผ่าน window.Swal
 */
import {useDatePickers} from '../../components/date-picker.js';
import {initAttachments} from './attachments.js';
import {firstErrorMessage, sendAction, submitLogForm} from './client.js';
import {initEntryForm} from './entry-form.js';
import {initParticipantPickers} from './participants.js';
import {initRoutinePanel} from './routines.js';
import {applySummary} from './summary.js';
import {initTimeline} from './timeline.js';

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
    // ป้ายชื่อและเหตุผลสำเร็จรูปทั้งหมดมาจาก WorkLogDesign ผ่าน island นี้
    // ไฟล์ .js ไม่เก็บข้อความไทยชุดที่สองของตัวเอง
    const design = readJson(doc, 'work-log-design', {});
    const dayLogs = readJson(doc, 'work-log-day', []);
    const byId = new Map((Array.isArray(dayLogs) ? dayLogs : []).map((log) => [String(log.id), log]));

    // ตัวเลือกผู้ร่วมงานดูแลป้ายจำนวนของตัวเอง ค่าที่ติ๊กถูกส่งไปกับ FormData
    // ของฟอร์มโดยตรง ที่นี่จึงเหลือแค่การเปิดใช้งาน
    initParticipantPickers({root});

    // งานประจำเป็นโหมดหนึ่งในกล่องเดียวกับฟอร์มบันทึกงาน ไม่ใช่กล่องของตัวเอง
    const routinePanel = initRoutinePanel({root, swal});

    const entryForm = initEntryForm({
        root,
        storeAction: routes.store || '',
        updateActionTemplate: routes.update || '',
    });

    /** นำผลลัพธ์จากเซิร์ฟเวอร์มาปรับหน้าจอ โดยไม่คำนวณตัวเลขเอง */
    const applyResult = (payload) => {
        if (payload?.log) byId.set(String(payload.log.id), payload.log);
        if (payload?.html) timeline?.upsertCard(payload.html, payload.log?.id);
        applySummary(payload?.summary, {root});
    };

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

    // ปุ่มเพิ่มงาน — ทางเข้าเดียวของหน้านี้ อยู่นอกกล่อง จึงฟังที่ระดับหน้า
    root.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-open-entry-modal]');

        if (! opener) return;

        event.preventDefault();
        entryForm?.openForCreate({workDate: root.dataset.date || ''}, opener);

        // ยังไม่มีบันทึกให้แนบไฟล์เข้าไป จึงซ่อนบล็อกไฟล์แนบไว้ก่อน
        attachments?.detach();
    });

    /* ปุ่มเริ่ม/จบงานประจำและปุ่มยืนยันของงานครั้งเดียว ใช้ delegation เพราะแถวถูกแทนที่ได้ */
    /**
     * ตัวเลือกเหตุผลของแต่ละปุ่ม อ่านจาก WorkLogDesign
     *
     * 'missed' คือของวันที่ผ่านไปแล้ว จึงไม่มีตัวเลือกที่แปลว่า "ยังทำอยู่"
     * ท้ายรายการเติม "อื่น ๆ" ไว้เสมอ เพื่อให้พิมพ์เหตุผลเองได้
     */
    const reasonOptions = (type) => {
        const list = design?.reasons?.[type] || [];
        const options = {};

        list.forEach((reason) => { options[reason] = reason; });
        options['อื่น ๆ'] = 'อื่น ๆ';

        return options;
    };

    const askReason = async (type, title, text = undefined) => {
        const answer = await swal?.fire({
            title,
            text,
            input: 'select',
            inputOptions: reasonOptions(type),
            inputPlaceholder: 'เลือกเหตุผล',
            showCancelButton: true,
            confirmButtonText: 'ยืนยัน',
            cancelButtonText: 'ยกเลิก',
            inputValidator: (value) => value ? undefined : 'กรุณาเลือกเหตุผล',
        });

        if (! answer?.isConfirmed) return null;
        if (answer.value !== 'อื่น ๆ') return answer.value;

        const custom = await swal?.fire({
            title: 'ระบุเหตุผล',
            input: 'text',
            inputPlaceholder: 'พิมพ์เหตุผลสั้น ๆ',
            showCancelButton: true,
            confirmButtonText: 'ยืนยัน',
            cancelButtonText: 'ยกเลิก',
            inputValidator: (value) => value?.trim() ? undefined : 'กรุณาระบุเหตุผล',
        });

        return custom?.isConfirmed ? custom.value.trim() : null;
    };

    const isAfter = (iso) => iso && Date.now() > new Date(iso).getTime();

    const refreshRoutineClocks = () => {
        root.querySelectorAll('[data-routine-elapsed]').forEach((node) => {
            const started = new Date(node.dataset.startedAt || '').getTime();
            if (! Number.isFinite(started)) return;

            const minutes = Math.max(0, Math.floor((Date.now() - started) / 60_000));
            const hours = Math.floor(minutes / 60);
            const remainder = minutes % 60;
            node.textContent = hours > 0 ? `กำลังทำ ${hours} ชม. ${remainder} นาที` : `กำลังทำ ${minutes} นาที`;
        });

        root.querySelectorAll('[data-row-start][data-planned-start-at][disabled]').forEach((button) => {
            if (! isAfter(button.dataset.plannedStartAt)) return;
            button.removeAttribute('disabled');
            button.removeAttribute('title');
            const label = button.querySelector('span');
            if (label) label.textContent = 'เริ่มงาน';
        });

        root.querySelectorAll('[data-log-card][data-planned-end-at]').forEach((card) => {
            const current = byId.get(String(card.dataset.logId));
            if (! ['open', 'in_progress'].includes(current?.status) || ! isAfter(card.dataset.plannedEndAt)) return;

            // รายการของวันที่ผ่านไปแล้วไม่ใช่ "เกินเวลา" แต่เป็น "ต้องระบุเหตุผล"
            // สถานะนั้นตัดสินจากฝั่งเซิร์ฟเวอร์แล้ว ที่นี่ต้องไม่เขียนทับ
            if (current?.is_missed) return;

            card.classList.add('log-card--status-overdue');
            const chip = card.querySelector('[data-log-status-chip]');
            if (! chip) return;
            chip.className = 'log-chip log-chip--red';
            chip.innerHTML = '<i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> เกินเวลา';
        });
    };

    refreshRoutineClocks();
    globalThis.setInterval(refreshRoutineClocks, 15_000);

    const rowActions = {
        'data-row-start': {route: () => routes.start, errorTitle: 'เริ่มงานไม่สำเร็จ'},
        'data-row-complete': {route: () => routes.complete, errorTitle: 'ยืนยันไม่สำเร็จ'},
        'data-row-skip': {route: () => routes.skip, errorTitle: 'บันทึกไม่ได้ทำวันนี้ไม่สำเร็จ'},
        'data-row-reopen': {route: () => routes.reopen, errorTitle: 'ย้ายกลับไม่สำเร็จ'},
    };

    const rowActionSelector = Object.keys(rowActions).map((name) => `[${name}]`).join(', ');

    root.addEventListener('click', async (event) => {
        const button = event.target.closest(rowActionSelector);

        if (! button) return;

        event.preventDefault();

        const logId = button.closest('[data-log-card]')?.dataset.logId;
        const name = Object.keys(rowActions).find((key) => button.hasAttribute(key));

        if (! logId || ! name) return;

        const action = rowActions[name];
        const current = byId.get(String(logId));
        const body = {};

        if (name === 'data-row-start' && (current?.requires_late_start_reason || isAfter(current?.planned_start_at))) {
            const reason = await askReason('start', 'เหตุผลที่เริ่มงานช้า');
            if (! reason) return;
            body.late_start_reason = reason;
        }

        if (name === 'data-row-complete' && (current?.requires_late_completion_reason || isAfter(current?.planned_end_at))) {
            const reason = await askReason('complete', 'เหตุผลที่เสร็จงานเกินเวลา');
            if (! reason) return;
            body.late_completion_reason = reason;
        }

        if (name === 'data-row-skip') {
            // วันที่ผ่านไปแล้วถามคนละชุดกับวันนี้ เพราะสิ่งที่ต้องอธิบายคือ
            // "ทำไมวันนั้นไม่ได้เริ่มงาน" ไม่ใช่ "วันนี้จะไม่ทำเพราะอะไร"
            const missed = current?.requires_missed_reason || button.hasAttribute('data-missed-reason');
            const reason = await askReason(
                missed ? 'missed' : 'skip',
                missed ? 'ทำไมวันนั้นถึงไม่ได้ทำรายการนี้' : 'เหตุผลที่ไม่ได้ทำงานวันนี้'
            );
            if (! reason) return;
            body.skip_reason = reason;
        }

        button.setAttribute('disabled', 'disabled');

        try {
            applyResult(await sendAction(
                (action.route() || '').replace('__ID__', String(logId)),
                {doc, fields: body}
            ));
            // ตัวเลขงานประจำบนแถบบนอ่านจากสถานะล่าสุดของวันนี้ จึงต้องรู้ทันทีที่สถานะเปลี่ยน
            doc.dispatchEvent(new CustomEvent('smartgoal:routine-changed', {detail: {logId: String(logId)}}));
        } catch (error) {
            swal?.fire({icon: 'error', title: action.errorTitle, text: error.message});
        } finally {
            button.removeAttribute('disabled');
        }
    });

    // ฟอร์มบันทึกงานในกล่อง (โหมด "ทำครั้งเดียว")
    const entryFormNode = root.querySelector('[data-log-entry-form]');

    entryFormNode?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const submit = entryFormNode.querySelector('[data-entry-submit]');
        submit?.setAttribute('disabled', 'disabled');

        try {
            const payload = await submitLogForm(entryFormNode, {doc});

            applyResult(payload);
            entryForm?.close();
        } catch (error) {
            entryForm?.showError(error.message);
        } finally {
            submit?.removeAttribute('disabled');
        }
    });

    /*
     * งานประจำของวันที่ผ่านมาที่ไม่มีรายการเลย — กดได้อย่างเดียวคือระบุเหตุผล
     *
     * ปุ่มนี้ไม่ได้สร้างงานย้อนหลังให้ทำต่อ แต่บันทึกว่าวันนั้นไม่ได้ทำเพราะอะไร
     * เซิร์ฟเวอร์ส่ง HTML ของแถวกลับมาให้เหมือนทุกคำสั่งอื่น จึงวางลงไทม์ไลน์
     * ได้ทันทีโดยไม่ต้องโหลดหน้าใหม่
     */
    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-routine-missed]');

        if (! button) return;

        event.preventDefault();

        const reason = await askReason(
            'missed',
            'ทำไมวันนั้นถึงไม่ได้ทำรายการนี้',
            button.dataset.routineTitle || ''
        );

        if (! reason) return;

        button.setAttribute('disabled', 'disabled');

        try {
            const payload = await sendAction(
                (routes.routineMissed || '').replace('__TEMPLATE__', String(button.dataset.templateId)),
                {doc, fields: {date: root.dataset.date || '', reason}}
            );

            applyResult(payload);

            const item = button.closest('.pending-routines__item');
            const section = button.closest('[data-pending-routines]');
            item?.remove();

            // รายการหมดแล้วก็ไม่ต้องเหลือหัวข้อว่างค้างไว้
            if (section && section.querySelectorAll('.pending-routines__item').length === 0) {
                section.remove();
            }
        } catch (error) {
            swal?.fire({icon: 'error', title: 'บันทึกเหตุผลไม่สำเร็จ', text: error.message});
        } finally {
            button.removeAttribute('disabled');
        }
    });

    /*
     * ปฏิทินเลือกวัน — ใช้ปฏิทินตัวเดียวกับทั้งระบบ
     *
     * useDatePickers() ผูกไว้แบบ delegated ที่ document ให้ทุกช่องที่ประกาศ
     * data-date-picker เปิดปฏิทินแบบ พ.ศ. ของระบบแทนของเบราว์เซอร์ ที่นี่จึง
     * เหลือหน้าที่เดียวคือส่งฟอร์มเมื่อผู้ใช้เลือกวันแล้ว
     */
    useDatePickers();

    const dateForm = root.querySelector('[data-date-form]');
    const dateInput = dateForm?.querySelector('[data-date-input]');

    dateInput?.addEventListener('change', () => {
        if (dateInput.value) dateForm?.submit();
    });

    // เปลี่ยนคนที่ดูอยู่ — ส่งฟอร์มทันทีโดยไม่ต้องกดปุ่มเพิ่ม
    root.querySelector('[data-member-select]')?.addEventListener('change', (event) => {
        event.target.form?.submit();
    });

    // หน้าที่หัวหน้าเปิดดูเป็น read-only จึงเช็กสถานะจากเครื่องของพนักงานทุก 30 วินาที
    // แล้วรีเฟรชเฉพาะเมื่อสถานะจริงเปลี่ยน ป้องกันการกระพริบหน้าโดยไม่จำเป็น
    if (root.dataset.readOnly === '1' && routes.routineStatus) {
        globalThis.setInterval(async () => {
            if (doc.hidden) return;
            try {
                const response = await fetch(routes.routineStatus, {headers: {Accept: 'application/json'}});
                if (! response.ok) return;
                const payload = await response.json();
                if (payload.fingerprint && payload.fingerprint !== root.dataset.routineFingerprint) {
                    globalThis.location.reload();
                }
            } catch {
                // การดูแบบสดเป็นส่วนเสริม เมื่อเครือข่ายหลุดให้ข้อมูลเดิมยังอ่านได้
            }
        }, 30_000);
    }

    return {timeline, entryForm, routinePanel, applyResult, firstErrorMessage};
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initDailyLogs());
    } else {
        initDailyLogs();
    }
}
