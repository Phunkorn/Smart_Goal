/*
 * จุดเริ่มต้นของหน้าตรวจเช็กคอมพิวเตอร์ประจำวัน
 *
 * ประกอบสามส่วนเข้าด้วยกัน: ผังห้อง แผงตรวจรายเครื่อง และกล่องพรีวิวแบบตรวจ
 * โดยแต่ละส่วนไม่รู้จักกันเอง ไฟล์นี้เป็นที่เดียวที่ผูกพฤติกรรมข้ามส่วน
 * (รูปแบบเดียวกับ pages/daily-logs/index.js)
 *
 * ตัวเลขสรุปและสถานะของทุกเครื่องมาจากเซิร์ฟเวอร์เสมอ ไฟล์นี้ไม่นับเอง
 * เพื่อไม่ให้หน้าจอค่อย ๆ เพี้ยนออกจากฐานข้อมูล
 *
 * ห้ามใช้ alert/confirm ตามกติกาของโปรเจกต์ การยืนยันทุกอย่างผ่าน window.Swal
 */
import {initInspectionReportModal} from '../../components/inspection-report-modal.js';
import {initChecklistPanel} from './checklist.js';
import {initFloorPlan} from './floor-plan.js';
import {firstErrorMessage, sendForm} from './session-client.js';

const readJson = (doc, id, fallback) => {
    try {
        const node = doc.getElementById(id);

        return node ? JSON.parse(node.textContent) : fallback;
    } catch {
        return fallback;
    }
};

export function initComputerCheck({doc = document, swal = globalThis.Swal} = {}) {
    const root = doc.querySelector('[data-computer-check]');

    if (! root || root.dataset.computerCheckReady === 'on') return null;

    root.dataset.computerCheckReady = 'on';

    const routes = readJson(doc, 'computer-check-routes', {});
    const design = readJson(doc, 'computer-check-design', {});
    let stations = readJson(doc, 'computer-check-stations', []);

    const sessionId = root.dataset.sessionId || '';
    const stationById = () => new Map(stations.map((station) => [String(station.id), station]));

    initInspectionReportModal({doc});

    /* ---------- แท็บ ---------- */

    const tabs = [...root.querySelectorAll('[data-cc-tab]')];
    const views = [...root.querySelectorAll('[data-cc-view]')];

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            tabs.forEach((other) => other.setAttribute('aria-selected', String(other === tab)));
            views.forEach((view) => { view.hidden = view.dataset.ccView !== tab.dataset.ccTab; });
        });
    });

    /* ---------- ตัวเลขสรุปและรายการ ---------- */

    const paintSummary = (summary) => {
        if (! summary) return;

        root.querySelectorAll('[data-cc-stat]').forEach((node) => {
            const key = node.dataset.ccStat;

            if (key in summary) node.textContent = String(summary[key]);
        });

        const partialRow = root.querySelector('[data-cc-stat-row="partial"]');

        if (partialRow) partialRow.hidden = (summary.partial || 0) === 0;
    };

    /*
     * แถวในแท็บ "รายการเครื่อง" ถูกอัปเดตจากข้อมูลชุดเดียวกับผัง
     * ป้ายสถานะมาจาก design island ไม่ได้เขียนข้อความไทยซ้ำในไฟล์นี้
     */
    const paintList = () => {
        const byId = stationById();

        root.querySelectorAll('[data-cc-station-row]').forEach((row) => {
            const station = byId.get(row.dataset.stationId);

            if (! station) return;

            row.className = `cc-list__row cc-list__row--${station.status}`;

            const status = row.querySelector('.cc-list__status');
            if (status) {
                status.className = `cc-list__status cc-list__status--${station.status}`;
                const icon = status.querySelector('i');
                status.textContent = ` ${station.status_label}`;
                if (icon) status.prepend(icon);
            }

            row.querySelectorAll('.cc-chip-inline').forEach((chip, index) => {
                const key = Object.keys(design.devices || {})[index];
                const value = station.devices?.[key] || 'pending';
                chip.className = `cc-chip-inline cc-chip-inline--${value}`;
            });
        });
    };

    const paintIssues = (issues) => {
        const list = root.querySelector('[data-cc-issue-list]');

        if (! list) return;

        const ownerDoc = list.ownerDocument;

        if (! issues || issues.length === 0) {
            const empty = ownerDoc.createElement('li');
            empty.className = 'cc-issue cc-issue--empty';
            empty.dataset.ccIssueEmpty = '';
            empty.textContent = 'ยังไม่พบปัญหาในรอบนี้';
            list.replaceChildren(empty);

            return;
        }

        list.replaceChildren(...issues.map((issue) => {
            const item = ownerDoc.createElement('li');
            item.className = 'cc-issue';

            const code = ownerDoc.createElement('strong');
            code.className = 'cc-issue__code';
            code.textContent = issue.code;

            const devices = ownerDoc.createElement('span');
            devices.className = 'cc-issue__devices';
            devices.textContent = (issue.devices || []).join(', ');

            const note = ownerDoc.createElement('span');
            note.className = 'cc-issue__note';
            note.textContent = issue.note || '';

            item.append(code, devices, note);

            return item;
        }));
    };

    /** นำผลลัพธ์จากเซิร์ฟเวอร์มาปรับหน้าจอทั้งหมดในทางเดียว */
    const applyState = (payload) => {
        if (Array.isArray(payload?.stations)) {
            stations = payload.stations;
            plan?.applyStations(stations);
            paintList();
        }

        paintSummary(payload?.summary);
        paintIssues(payload?.issues);
    };

    /* ---------- ผังห้องและแผงตรวจ ---------- */

    const panel = initChecklistPanel({
        root,
        design,
        onSave: async ({stationId, statuses, note}) => {
            const url = (routes.saveResult || '')
                .replace('__ID__', sessionId)
                .replace('__STATION__', String(stationId));

            const payload = await sendForm(url, {
                doc,
                method: 'PATCH',
                fields: {...statuses, note},
            });

            applyState(payload);
        },
        onBulkNormal: async () => {
            const ids = plan?.selectedIds() || [];
            const url = (routes.markNormal || '').replace('__ID__', sessionId);

            const payload = await sendForm(url, {
                doc,
                repeated: {'workstations[]': ids},
            });

            applyState(payload);
            plan?.clearSelection();
        },
    });

    const plan = initFloorPlan({
        root,
        design,
        onSelect: (id) => {
            const station = stationById().get(String(id));
            const trigger = root.querySelector(`[data-cc-station][data-station-id="${id}"]`);

            panel?.openForStation(station, trigger);
        },
        onMultiChange: (ids) => {
            if (ids === null) {
                panel?.close();

                return;
            }

            const byId = stationById();
            panel?.openForSelection(ids.map((id) => byId.get(String(id))?.code).filter(Boolean));
        },
    });

    // แถวในแท็บรายการเปิดแผงชุดเดียวกับผัง ไม่ใช่ฟอร์มคนละใบ
    root.querySelectorAll('[data-cc-station-row]').forEach((row) => {
        row.addEventListener('click', () => {
            panel?.openForStation(stationById().get(row.dataset.stationId), row);
        });
    });

    /* ---------- เปิดรอบ ---------- */

    const startForm = root.querySelector('[data-cc-start-form]');
    const startError = root.querySelector('[data-cc-start-error]');

    startForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (startError) startError.hidden = true;

        try {
            const payload = await sendForm(startForm.action, {doc, form: startForm});

            if (payload?.redirect) globalThis.location.assign(payload.redirect);
        } catch (failure) {
            if (startError) {
                startError.textContent = failure.message;
                startError.hidden = false;
            }
        }
    });

    /* ---------- ปิดรอบ ---------- */

    const completeButton = root.querySelector('[data-cc-complete]');
    const completeError = root.querySelector('[data-cc-complete-error]');

    completeButton?.addEventListener('click', async () => {
        const confirmed = await swal?.fire({
            icon: 'question',
            title: 'บันทึกผลการตรวจ?',
            text: 'ระบบจะสร้างบันทึกงานประจำวันหนึ่งรายการ และรอบนี้จะแก้ไขย้อนหลังไม่ได้',
            showCancelButton: true,
            confirmButtonText: 'บันทึกผล',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true,
        });

        if (! confirmed?.isConfirmed) return;

        if (completeError) completeError.hidden = true;

        try {
            const url = (routes.complete || '').replace('__ID__', sessionId);
            const payload = await sendForm(url, {doc});

            await swal?.fire({
                icon: 'success',
                title: 'บันทึกผลการตรวจเรียบร้อย',
                text: 'เปิดแบบตรวจย้อนหลังได้จากประวัติการตรวจและรายงานปฏิบัติงาน',
                confirmButtonText: 'ตกลง',
            });

            globalThis.location.reload();
        } catch (failure) {
            if (completeError) {
                completeError.textContent = failure.message;
                completeError.hidden = false;
            }
        }
    });

    return {applyState, firstErrorMessage};
}

initComputerCheck();
