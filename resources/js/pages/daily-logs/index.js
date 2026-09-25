/*
 * จุดเริ่มต้นของหน้าบันทึกงานประจำวัน
 *
 * ประกอบส่วนต่าง ๆ เข้าด้วยกัน: เพิ่มงานใหม่ (ในหน้า) ไทม์ไลน์ กล่องฟอร์ม และปฏิทินเวร
 * โดยแต่ละส่วนไม่รู้จักกันเอง ไฟล์นี้เป็นที่เดียวที่ผูกพฤติกรรมข้ามส่วน
 *
 * ห้ามใช้ alert/confirm ตามกติกาของโปรเจกต์ การยืนยันทุกอย่างผ่าน window.Swal
 */
import {initAutoSubmitFilters} from '../../components/auto-submit-filter.js';
import {useDatePickers} from '../../components/date-picker.js';
import {initSelectDropdowns} from '../../components/select-dropdown.js';
import {initAttachments} from './attachments.js';
import {firstErrorMessage, sendAction, submitLogForm} from './client.js';
import {askCompletion} from './completion-dialog.js';
import {askReason} from './reason-dialog.js';
import {askStartRequirements} from './routine-start-dialog.js';
import {initEntryForm} from './entry-form.js';
import {initParticipantPickers} from './participants.js';
import {initPlanCalendar} from './plan-calendar.js';
import {initRoutinePanel} from './routines.js';
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
    initPlanCalendar({root});
    // ดร็อปดาวน์แบบสไลด์ชุดเดียวของทั้งหน้า: ตัวกรองปฏิทิน ตัวกรองสถานะ ตัวเลือกสมาชิก
    // ตัวเลือกเดือนของสรุปรายเดือน และช่องเลือกในกล่องเพิ่มงาน
    initSelectDropdowns(root, '.daily-plan__filters select, [data-status-filter], [data-member-select], [data-month-select], .log-modal select.form-select');
    // เลือกเดือนในสรุปรายเดือนแล้วส่งฟอร์ม GET ทันที
    initAutoSubmitFilters(root, '[data-daily-month-filter]');

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
            } catch (error) {
                swal?.fire({icon: 'error', title: 'ลบไม่สำเร็จ', text: error.message});
            }
        },
    });

    // ปุ่มเปิดกล่อง — งานประจำของฉัน / ตั้งงานประจำใหม่
    root.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-open-entry-modal]');

        if (! opener) return;

        event.preventDefault();
        entryForm?.openForCreate({
            mode: opener.dataset.openEntryModal || 'choice',
            kind: opener.dataset.entryKindValue || '',
            workDate: root.dataset.date || '',
        }, opener);
        if (opener.dataset.openEntryModal === 'routine' && ! opener.hasAttribute('data-routine-edit')) {
            routinePanel?.resetEditor();
        }

        // ยังไม่มีบันทึกให้แนบไฟล์เข้าไป จึงซ่อนบล็อกไฟล์แนบไว้ก่อน
        attachments?.detach();
    });

    /* ปุ่มเริ่ม/จบงานประจำและปุ่มยืนยันของงานครั้งเดียว ใช้ delegation เพราะแถวถูกแทนที่ได้ */
    const isAfter = (iso) => iso && Date.now() > new Date(iso).getTime();

    /**
     * เริ่มงานประจำ — ถามตามที่ server ขอทีละขั้น ไม่ตัดสินเองว่าต้องถามอะไร
     *
     * 1. เหตุผลวันค้าง (ของตัวเองและของผู้ร่วมงานที่มาทำด้วย) + ผู้ร่วมงานมาไหม — กล่องเดียว
     * 2. แล้วจึงเหตุผลที่เริ่มช้า ถ้าเลยเวลาเริ่ม + ช่วงผ่อนผันแล้ว
     *
     * ทุกคำตอบถูกส่งซ้ำไปพร้อมกัน server ตรวจทั้งชุดใน transaction เดียว
     * กดยกเลิกขั้นไหนก็ตาม = ไม่เริ่มงาน (คืน null)
     */
    const sendStart = async (url, fields, current) => {
        try {
            return await sendAction(url, {doc, fields});
        } catch (error) {
            const requirements = error.payload?.requirements;

            if (requirements) {
                const answers = await askStartRequirements({
                    swal,
                    requirements,
                    reasons: design?.reasons || {},
                    title: current?.title || '',
                });

                return answers ? sendStart(url, {...fields, ...answers}, current) : null;
            }

            if (error.payload?.errors?.late_start_reason && ! fields.late_start_reason) {
                const reason = await askReason({swal, title: 'เหตุผลที่เริ่มงานช้า', reasons: design?.reasons?.start || []});

                return reason ? sendStart(url, {...fields, late_start_reason: reason}, current) : null;
            }

            throw error;
        }
    };

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
        });

        root.querySelectorAll('[data-log-card][data-planned-end-at]').forEach((card) => {
            const current = byId.get(String(card.dataset.logId));
            if (! ['open', 'in_progress'].includes(current?.status) || ! isAfter(card.dataset.plannedEndAt)) return;

            // รายการที่ปิดรอบ 17:00 แล้วไม่ใช่ "เกินเวลา" สถานะนั้นตัดสินจากฝั่งเซิร์ฟเวอร์ ที่นี่ต้องไม่เขียนทับ
            if (current?.is_closed_by_cutoff || current?.requires_explanation) return;

            card.classList.add('log-card--status-overdue');
            const chip = card.querySelector('[data-log-status-chip]');
            if (! chip) return;
            chip.textContent = 'เกินเวลา';
        });
    };

    refreshRoutineClocks();
    globalThis.setInterval(refreshRoutineClocks, 15_000);

    const rowActions = {
        'data-row-start': {route: () => routes.start, errorTitle: 'เริ่มงานไม่สำเร็จ'},
        'data-row-complete': {route: () => routes.complete, errorTitle: 'ยืนยันไม่สำเร็จ'},
        'data-row-skip': {route: () => routes.skip, errorTitle: 'บันทึกไม่ได้ทำวันนี้ไม่สำเร็จ'},
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

        // ปิดงานทุกครั้งถามในกล่องเดียว: เสร็จสิ้น/พบปัญหา และเหตุผลที่เสร็จช้าเมื่อเลยช่วงผ่อนผัน
        if (name === 'data-row-complete') {
            const completion = await askCompletion({
                swal,
                title: current?.title || '',
                late: Boolean(current?.requires_late_completion_reason || isAfter(current?.late_completion_after)),
                reasons: design?.reasons?.complete || [],
            });
            if (! completion) return;
            Object.assign(body, completion);
        }

        if (name === 'data-row-skip') {
            // รายการปิดรอบถามคนละชุดตามกรณี ห้ามรวม "เริ่มแล้วไม่กดเสร็จ" กับ "ไม่ได้เริ่ม/ไม่มา"
            const prompts = {
                not_started: ['missed', 'ทำไมวันนั้นถึงไม่ได้เริ่มงานนี้'],
                absent: ['missed', 'ทำไมวันนั้นถึงไม่ได้มาทำงานนี้'],
                unfinished: ['unfinished', 'ทำไมเริ่มแล้วแต่ไม่ได้กดเสร็จ'],
            };
            const [list, prompt] = prompts[button.dataset.explainType || current?.explanation_type] || ['skip', 'เหตุผลที่ไม่ได้ทำงานวันนี้'];
            const reason = await askReason({swal, title: prompt, reasons: design?.reasons?.[list] || []});
            if (! reason) return;
            body.skip_reason = reason;
        }

        button.setAttribute('disabled', 'disabled');

        try {
            const url = (action.route() || '').replace('__ID__', String(logId));
            const payload = name === 'data-row-start'
                ? await sendStart(url, body, current)
                : await sendAction(url, {doc, fields: body});
            if (! payload) return;

            applyResult(payload);
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

            if (Number(payload.created_count || 1) > 1) {
                globalThis.location?.reload();
                return;
            }
            applyResult(payload);
            entryForm?.close();
        } catch (error) {
            entryForm?.showError(error.message);
        } finally {
            submit?.removeAttribute('disabled');
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

    /*
     * รายการทั้งหมดของวันนี้อัปเดตเองโดยไม่ต้องกด F5 — ทั้งหน้าของเจ้าของและหน้าที่หัวหน้าเปิดดู
     *
     * สิ่งที่เปลี่ยนจากเครื่องอื่น: ผู้ร่วมงานกดเริ่ม/เสร็จงานประจำแทน (RoutineAccountabilityService)
     * พนักงานเพิ่ม/แก้/ลบงานนอกสถานที่ขณะหัวหน้าเปิดดู หรือถูกเพิ่มเป็นผู้ร่วมงานนอกสถานที่
     * จึงเช็ก fingerprint ทุก 10 วินาที (และทันทีที่กลับมาที่แท็บ) แล้วขอการ์ดทั้งวันเฉพาะเมื่อมีอะไรเปลี่ยน
     * timeline.syncCards() เพิ่ม/แทน/ลบการ์ดตามลำดับของ server — ไม่รีโหลดหน้า กล่องที่เปิดอยู่จึงไม่หาย
     */
    const syncDay = async () => {
        if (doc.hidden || ! routes.routineStatus) return;
        try {
            // live=1: server ตอบแค่ fingerprint แบบอ่านอย่างเดียว ไม่ปิดรอบ/สร้างงาน/แจ้งเตือนซ้ำทุก 10 วินาที
            const url = new URL(routes.routineStatus, globalThis.location?.origin);
            url.searchParams.set('live', '1');
            const status = await (await fetch(url, {headers: {Accept: 'application/json'}})).json();
            if (! status.fingerprint || status.fingerprint === root.dataset.dayFingerprint) return;

            url.searchParams.set('cards', '1');
            const response = await fetch(url, {headers: {Accept: 'application/json'}});
            if (! response.ok) return;
            const payload = await response.json();
            const cards = Array.isArray(payload.cards) ? payload.cards : [];

            // ข้อมูลของการ์ดที่ปุ่มเริ่ม/เสร็จ/แก้ไขอ่าน ต้องตรงกับการ์ดบนหน้าเสมอ
            byId.clear();
            cards.forEach((card) => {
                if (card.log) byId.set(String(card.log.id), card.log);
            });
            timeline?.syncCards(cards);

            root.dataset.dayFingerprint = payload.fingerprint || status.fingerprint;
            doc.dispatchEvent(new CustomEvent('smartgoal:routine-changed', {detail: {source: 'sync'}}));
        } catch {
            // การอัปเดตสดเป็นส่วนเสริม เมื่อเครือข่ายหลุดให้ข้อมูลเดิมยังอ่านได้
        }
    };

    if (root.dataset.liveDay === '1' && routes.routineStatus) {
        globalThis.setInterval(syncDay, 10_000);
        doc.addEventListener('visibilitychange', () => {
            if (! doc.hidden) syncDay();
        });
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
