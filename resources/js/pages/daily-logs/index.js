/*
 * จุดเริ่มต้นของหน้าบันทึกงานประจำวัน
 *
 * ประกอบส่วนต่าง ๆ เข้าด้วยกัน: เพิ่มงานใหม่ (ในหน้า) ไทม์ไลน์ กล่องฟอร์ม และปฏิทินเวร
 * โดยแต่ละส่วนไม่รู้จักกันเอง ไฟล์นี้เป็นที่เดียวที่ผูกพฤติกรรมข้ามส่วน
 *
 * ห้ามใช้ alert/confirm ตามกติกาของโปรเจกต์ การยืนยันทุกอย่างผ่าน window.Swal
 */
import {useDatePickers} from '../../components/date-picker.js';
import {initSelectDropdowns} from '../../components/select-dropdown.js';
import {initAttachments} from './attachments.js';
import {firstErrorMessage, sendAction, submitLogForm} from './client.js';
import {askCompletion} from './completion-dialog.js';
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
    // ดร็อปดาวน์แบบสไลด์ชุดเดียวของทั้งหน้า: ตัวกรองปฏิทิน ตัวกรองสถานะ ตัวเลือกสมาชิก และช่องเลือกในกล่องเพิ่มงาน
    initSelectDropdowns(root, '.daily-plan__filters select, [data-status-filter], [data-member-select], .log-modal select.form-select');

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
    /**
     * ตัวเลือกเหตุผลของแต่ละปุ่ม อ่านจาก WorkLogDesign
     *
     * 'missed' ใช้กับรายการปิดรอบที่ไม่ได้เริ่ม/ไม่มา ส่วน 'unfinished' ใช้กับเริ่มแล้วไม่กดเสร็จ
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
            didOpen: (popup) => {
                const dropdown = initSelectDropdowns(popup, '.swal2-select')[0];
                dropdown?.root.classList.add('log-reason-select');
            },
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

        // late_*_after = เวลาที่ตั้งไว้ + ช่วงผ่อนผัน คำนวณจากฝั่ง server ที่เดียว
        if (name === 'data-row-start' && (current?.requires_late_start_reason || isAfter(current?.late_start_after))) {
            const reason = await askReason('start', 'เหตุผลที่เริ่มงานช้า');
            if (! reason) return;
            body.late_start_reason = reason;
        }

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
            const reason = await askReason(list, prompt);
            if (! reason) return;
            body.skip_reason = reason;
        }

        button.setAttribute('disabled', 'disabled');

        try {
            const url = (action.route() || '').replace('__ID__', String(logId));
            let payload;

            try {
                payload = await sendAction(url, {doc, fields: body});
            } catch (error) {
                // เริ่มงานประจำ: server ขอเหตุผลของวันที่ค้าง และ/หรือคำตอบว่าผู้ร่วมงานมาไหม — ถามครั้งเดียวแล้วส่งซ้ำ
                const requirements = name === 'data-row-start' ? error.payload?.requirements : null;
                if (! requirements) throw error;

                const answers = await askStartRequirements({
                    swal,
                    requirements,
                    reasons: design?.reasons || {},
                    title: current?.title || '',
                });
                if (! answers) return;

                payload = await sendAction(url, {doc, fields: {...body, ...answers}});
            }

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
