export function initRoutineTopbar({doc = document, fetchImpl = globalThis.fetch} = {}) {
    const root = doc.querySelector('[data-routine-topbar]');
    if (! root || root.dataset.routineReady === 'on') return null;
    root.dataset.routineReady = 'on';
    let pending = false;
    let destroyed = false;
    let away = false;
    let expired = false;
    let refreshQueued = false;

    const sync = async () => {
        if (destroyed || away || expired || doc.hidden || pending) return;
        pending = true;
        try {
            const response = await fetchImpl(root.dataset.routineStatusUrl, {
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            if ([401, 403, 419].includes(response.status)) expired = true;
            if (! response.ok || destroyed || away) return;
            const {attention = {}} = await response.json();
            if (destroyed || away) return;
            const total = Number(attention.total || 0);
            const count = root.querySelector('[data-routine-count]');
            if (count) { count.textContent = String(total); count.hidden = total === 0; }
            const totalText = root.querySelector('[data-routine-total]');
            if (totalText) totalText.textContent = `${total} รายการ`;
            [['waiting', 'waiting'], ['running', 'running'], ['overdue', 'overdue']].forEach(([key, selector]) => {
                const node = root.querySelector(`[data-routine-${selector}]`);
                if (node) node.textContent = String(attention[key] || 0);
            });
        } catch {
            // การเช็กสถานะเป็น progressive enhancement หน้าอื่นต้องทำงานต่อได้เมื่อเครือข่ายหลุด
        } finally {
            pending = false;
            if (refreshQueued) {
                refreshQueued = false;
                sync();
            }
        }
    };

    let timer = globalThis.setInterval(sync, 60_000);
    const resume = () => { if (! doc.hidden) sync(); };
    const pause = () => { away = true; globalThis.clearInterval(timer); };
    const restore = () => {
        if (destroyed || ! away) return;
        away = false;
        globalThis.clearInterval(timer);
        timer = globalThis.setInterval(sync, 60_000);
        resume();
    };
    doc.addEventListener('visibilitychange', resume);
    doc.defaultView?.addEventListener('pagehide', pause);
    doc.defaultView?.addEventListener('pageshow', restore);
    /*
     * กดเริ่ม เสร็จ หรือไม่ได้ทำในหน้าบันทึกงานประจำวัน ต้องเห็นตัวเลขบนแถบบนเปลี่ยนทันที
     * ไม่ใช่รอรอบ poll ถัดไป หน้านั้นจึงยิงเหตุการณ์นี้ออกมาหลังบันทึกสำเร็จ
     */
    const routineChanged = () => {
        if (pending) refreshQueued = true;
        else sync();
    };
    doc.addEventListener('smartgoal:routine-changed', routineChanged);
    return {sync, destroy: () => {
        destroyed = true;
        globalThis.clearInterval(timer);
        doc.removeEventListener('visibilitychange', resume);
        doc.removeEventListener('smartgoal:routine-changed', routineChanged);
        doc.defaultView?.removeEventListener('pagehide', pause);
        doc.defaultView?.removeEventListener('pageshow', restore);
        delete root.dataset.routineReady;
    }};
}

if (typeof document !== 'undefined') initRoutineTopbar();
