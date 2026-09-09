export function initRoutineTopbar({doc = document, fetchImpl = globalThis.fetch} = {}) {
    const root = doc.querySelector('[data-routine-topbar]');
    if (! root || root.dataset.routineReady === 'on') return null;
    root.dataset.routineReady = 'on';

    const sync = async () => {
        try {
            const response = await fetchImpl(root.dataset.routineStatusUrl, {
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            if (! response.ok) return;
            const {attention = {}} = await response.json();
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
        }
    };

    const timer = globalThis.setInterval(sync, 60_000);
    doc.addEventListener('visibilitychange', () => { if (! doc.hidden) sync(); });
    /*
     * กดเริ่ม เสร็จ หรือไม่ได้ทำในหน้าบันทึกงานประจำวัน ต้องเห็นตัวเลขบนแถบบนเปลี่ยนทันที
     * ไม่ใช่รอรอบ poll ถัดไป หน้านั้นจึงยิงเหตุการณ์นี้ออกมาหลังบันทึกสำเร็จ
     */
    doc.addEventListener('smartgoal:routine-changed', () => sync());
    return {sync, destroy: () => globalThis.clearInterval(timer)};
}

if (typeof document !== 'undefined') initRoutineTopbar();
