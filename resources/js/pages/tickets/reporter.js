/**
 * หน้าของผู้แจ้งบน /tickets (tickets/landing) — การ์ดสถานะเดียว + modal อ่านอย่างเดียว + modal ประวัติ Ticket
 *
 * สถานะทุกอย่างมาจาก server (TicketController::reporterPage) ไฟล์นี้ไม่ตัดสินว่าแจ้งได้หรือไม่ เพียง:
 *   1. เปิด modal เองเมื่อมาจากลิงก์ ?ticket (data-open-on-load) แล้วลบ ?ticket ออกจาก URL ตอนปิด
 *      เพื่อ refresh ครั้งถัดไปไม่เปิดซ้ำ
 *   2. แจ้งเตือน Ticket จาก realtime-sync เดิม (เช่น เจ้าหน้าที่รับงาน/ปิดงาน) → โหลดหน้าเดิมแล้วแทนที่การ์ด modal รายละเอียด
 *      และ modal ประวัติ (สถานะของใบในรายการเปลี่ยนตามไปด้วย)
 *      ถ้าสถานะข้ามไป/มาจาก "แจ้งปัญหา" (เช่น ปิดงานแล้ว) โหลดทั้งหน้าแทน เพื่อให้ฟอร์มแจ้งปัญหาผูกพฤติกรรมครบ
 *      ไม่มี polling ชุดที่สอง · ระหว่างเปิด modal หรือฟอร์มแจ้งปัญหาอยู่ เลื่อนไปทำหลังปิด
 *   3. กลับมาด้วยปุ่ม Back (bfcache) → โหลดใหม่ ไม่แสดงการ์ดเก่า (server ส่ง no-store ด้วย)
 *
 * เปิด/ปิด โฟกัส และ Escape ของ modal เป็นของ Bootstrap Modal ตัวเดียว
 */

const REGIONS = ['[data-ticket-reporter-region]', '[data-ticket-reporter-modal]', '[data-ticket-reporter-history]'];

const stateOf = (root) => root.querySelector('[data-ticket-reporter-region] [data-state]')?.dataset.state ?? null;

/**
 * แทนที่การ์ดและ modal รายละเอียดด้วยของจากหน้าใหม่
 * คืน 'swapped' · 'reload' เมื่อสถานะข้ามไป/มาจาก "แจ้งปัญหา" (ฟอร์มแจ้งปัญหาต้องผูกพฤติกรรมตอนโหลดหน้า)
 * · false เมื่อไม่ใช่หน้าผู้แจ้ง (เช่น session หมด)
 */
export function swapReporterRegions(page, html) {
    const doc = page.ownerDocument;
    const incoming = new doc.defaultView.DOMParser().parseFromString(html, 'text/html');
    const next = incoming.querySelector('[data-ticket-reporter]');
    if (! next) return false;

    const before = stateOf(page);
    const after = stateOf(next);
    if (before !== after && (before === 'report' || after === 'report')) return 'reload';

    for (const selector of REGIONS) {
        const current = page.querySelector(selector);
        const replacement = next.querySelector(selector);
        if (current && replacement) current.replaceWith(doc.importNode(replacement, true));
        else if (current) current.remove();
        else if (replacement) page.append(doc.importNode(replacement, true));
    }

    return 'swapped';
}

/**
 * @param {ParentNode} root
 * @param {{bootstrapApi?: () => any, fetchImpl?: typeof fetch, history?: History, location?: Location}} options
 */
export function initReporterPage(root = document, {
    bootstrapApi = () => globalThis.window?.bootstrap,
    fetchImpl = globalThis.fetch?.bind(globalThis),
    history = globalThis.window?.history,
    location = globalThis.window?.location,
} = {}) {
    const page = root.querySelector('[data-ticket-reporter]');
    if (! page || page.dataset.ticketReporterReady === 'true') return null;
    page.dataset.ticketReporterReady = 'true';

    const doc = page.ownerDocument;
    const view = doc.defaultView;
    let pendingRefresh = false;
    let refreshing = null;

    const overlayOpen = () => Boolean(page.querySelector('.modal.show'));

    const cleanTicketParam = () => {
        if (! location || ! history?.replaceState) return;
        const url = new URL(location.href);
        if (! url.searchParams.has('ticket')) return;
        url.searchParams.delete('ticket');
        history.replaceState(history.state, '', url.pathname + url.search + url.hash);
    };

    const refresh = () => {
        if (refreshing || typeof fetchImpl !== 'function' || ! location) return refreshing;
        if (overlayOpen()) {
            pendingRefresh = true;

            return null;
        }

        const url = new URL(location.href);
        url.searchParams.delete('ticket');
        refreshing = fetchImpl(url.toString(), {headers: {Accept: 'text/html'}, credentials: 'same-origin'})
            .then((response) => (response.ok ? response.text() : null))
            .then((html) => (html ? swapReporterRegions(page, html) : false))
            .then((result) => {
                if (result === 'reload') location.reload();

                return result;
            })
            .catch(() => false)
            .finally(() => {
                refreshing = null;
            });

        return refreshing;
    };

    // modal ปิด (ทุกทาง) → ลบ ?ticket และทำการโหลดที่เลื่อนไว้ — ฟังที่ page เพราะ modal ถูกแทนที่ได้
    page.addEventListener('hidden.bs.modal', () => {
        cleanTicketParam();
        if (pendingRefresh) {
            pendingRefresh = false;
            refresh();
        }
    });

    doc.addEventListener('smartgoal:realtime-notification', (event) => {
        if (event.detail?.category === 'ticket') refresh();
    });

    view?.addEventListener('pageshow', (event) => {
        if (event.persisted) location?.reload();
    });

    const autoOpen = page.querySelector('[data-ticket-reporter-modal][data-open-on-load]');
    const modalApi = bootstrapApi()?.Modal;
    if (autoOpen && modalApi) modalApi.getOrCreateInstance(autoOpen).show();

    return {refresh};
}
