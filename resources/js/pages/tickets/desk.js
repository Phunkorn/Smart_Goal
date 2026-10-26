/**
 * หน้ารับแจ้งปัญหาของทีม IT (tickets/index) — ข้อมูลล่าสุดโดยไม่รีเฟรชหน้า, modal ดูรายละเอียด, รับงาน/เข้าร่วมจากคิว
 *
 * 1. โหลดข้อมูลใหม่แบบ async: ขอหน้าเดิม (URL ปัจจุบันพร้อมตัวกรอง) แล้วแทนที่เฉพาะ
 *    [data-ticket-overview], [data-ticket-queue-results] และ [data-ticket-stamp] — markup มาจาก Blade ชุดเดียวกับตอนโหลดหน้า
 *    ฟอร์มค้นหา/ตัวกรองไม่ถูกแทนที่ คำที่กำลังพิมพ์จึงไม่หาย
 *    อัตโนมัติทั้งหมด ไม่มีปุ่มรีเฟรช: event หมวด ticket ของ realtime-sync เดิม (Ticket ใหม่ขึ้นแทบทันทีสำหรับผู้ที่ได้รับแจ้งเตือน),
 *    กลับมาที่แท็บ และรอบตรวจทุก 15 วินาทีขณะแท็บแสดงอยู่
 *    (ผู้ที่ไม่ได้รับแจ้งเตือน Ticket ใหม่ เช่น admin ที่ไม่ใช่ agent ก็ยังเห็นคิวล่าสุด) · หลังรับงาน/เข้าร่วมโหลดใหม่ทันที
 * 2. "ดูรายละเอียด" และเลข Ticket เปิด modal (components/preview-modal) ที่โหลดเนื้อหาจาก tickets.preview
 *    กด Ctrl/⌘/ปุ่มกลาง ยังเปิดหน้ารายละเอียดเต็มในแท็บใหม่ได้ตามปกติ
 *    หน้าที่มี data-ticket-open-preview (รายการของหัวหน้าแผนกที่มาจากลิงก์ ?ticket) เปิด modal ของใบนั้นเองตอนโหลด
 * 3. ฟอร์มรับงาน/เข้าร่วม (components/claim-actions) ในคิวและใน modal ส่งแบบ AJAX ผ่าน jsonOrBack() เดิม
 *    แล้วโหลดคิวและ modal ใหม่ — server ตรวจสิทธิ์และสถานะเอง (409 เมื่อมีคนเปลี่ยนสถานะไปก่อน,
 *    403 พร้อมข้อความเมื่อเข้าร่วม Ticket ที่ยังไม่มีผู้รับงาน) ข้อความปฏิเสธแสดงเป็น SweetAlert ให้กดรับทราบ
 *
 * ปุ่มที่แสดงมาจาก TicketPolicy ฝั่ง server ไม่มีการตัดสินสิทธิ์ที่นี่
 */

export const AUTO_REFRESH_INTERVAL = 15000;

const REGIONS = ['[data-ticket-overview]', '[data-ticket-queue-results]', '[data-ticket-stamp]'];

/**
 * แทนที่ส่วนที่เปลี่ยนได้ของหน้าด้วยส่วนเดียวกันจาก HTML ที่โหลดมาใหม่
 * คืน false เมื่อ HTML ไม่ใช่หน้ารับแจ้งปัญหา (เช่น session หมดแล้วถูกพาไปหน้า login) — ไม่แตะหน้าเดิม
 */
export function swapDeskRegions(page, html) {
    const doc = page.ownerDocument;
    const incoming = new doc.defaultView.DOMParser().parseFromString(html, 'text/html');
    if (! incoming.querySelector('[data-ticket-list] [data-ticket-queue-results]')) return false;

    for (const selector of REGIONS) {
        const current = page.querySelector(selector);
        const replacement = incoming.querySelector(`[data-ticket-list] ${selector}`);
        if (current && replacement) current.replaceWith(doc.importNode(replacement, true));
    }

    return true;
}

function setBusy(element, busy) {
    element.setAttribute('aria-busy', busy ? 'true' : 'false');
    element.classList.toggle('is-loading', busy);
}

function toast(swal, icon, title) {
    swal()?.fire?.({toast: true, position: 'top-end', icon, title, showConfirmButton: false, timer: 3200});
}

/** การกระทำที่ server ปฏิเสธ (เช่น เข้าร่วมก่อนมีผู้รับงาน, 409) — ต้องให้ผู้ใช้กดรับทราบ ไม่ใช่ toast ที่หายเอง */
function rejected(swal, text) {
    swal()?.fire?.({icon: 'warning', title: 'ดำเนินการไม่ได้', text, confirmButtonText: 'รับทราบ'});
}

/**
 * @param {ParentNode} root
 * @param {{fetchImpl?: typeof fetch, bootstrapApi?: () => any, swal?: () => any,
 *          setTimer?: typeof setTimeout, clearTimer?: typeof clearTimeout, interval?: number}} options
 */
export function initTicketDesk(root = document, {
    fetchImpl = globalThis.fetch?.bind(globalThis),
    bootstrapApi = () => globalThis.window?.bootstrap,
    swal = () => globalThis.window?.Swal,
    setTimer = globalThis.setTimeout,
    clearTimer = globalThis.clearTimeout,
    interval = AUTO_REFRESH_INTERVAL,
} = {}) {
    const page = root.querySelector('[data-ticket-list]');
    if (! page || ! page.querySelector('[data-ticket-queue-results]') || page.dataset.ticketDeskReady === 'true') return null;
    if (typeof fetchImpl !== 'function') return null;
    page.dataset.ticketDeskReady = 'true';

    const doc = page.ownerDocument;
    const view = doc.defaultView;
    const modal = page.querySelector('[data-ticket-preview-modal]');
    const content = modal?.querySelector('[data-ticket-preview-content]');
    const loading = modal?.querySelector('template[data-ticket-preview-loading]');
    const csrf = doc.querySelector('meta[name="csrf-token"]')?.content ?? '';

    let timer = null;
    let refreshing = null;
    let previewUrl = null;
    let previewRequest = 0;
    let opener = null;

    // ---------- 1. โหลดข้อมูลใหม่ ----------
    const schedule = () => {
        clearTimer(timer);
        if (doc.hidden) return;
        timer = setTimer(() => refresh({auto: true}), interval);
        timer?.unref?.();
    };

    /** auto: ไม่แทนที่คิวขณะผู้ใช้โฟกัสอยู่ในคิว (ไม่ให้โฟกัสหลุด) — รอรอบถัดไป */
    const refresh = ({auto = false} = {}) => {
        // หลังรับงาน/เข้าร่วมต้องได้ข้อมูลหลังการกระทำ ไม่ใช่ผลของคำขอที่ส่งไปก่อนหน้า
        if (refreshing) return auto ? refreshing : refreshing.then(() => refresh());
        const results = page.querySelector('[data-ticket-queue-results]');
        if (auto && results?.contains(doc.activeElement)) {
            schedule();

            return Promise.resolve(false);
        }

        refreshing = fetchImpl(view.location.href, {headers: {Accept: 'text/html'}, credentials: 'same-origin'})
            .then((response) => (response.ok ? response.text() : Promise.reject(new Error(`HTTP ${response.status}`))))
            .then((html) => {
                if (! swapDeskRegions(page, html)) throw new Error('unexpected page');

                return true;
            })
            .catch(() => {
                if (! auto) toast(swal, 'error', 'โหลดข้อมูลล่าสุดไม่สำเร็จ กรุณาลองอีกครั้ง');

                return false;
            })
            .finally(() => {
                refreshing = null;
                schedule();
            });

        return refreshing;
    };

    doc.addEventListener('smartgoal:realtime-notification', (event) => {
        if (event.detail?.category === 'ticket') refresh({auto: true});
    });

    doc.addEventListener('visibilitychange', () => {
        if (doc.hidden) clearTimer(timer);
        else refresh({auto: true});
    });

    // ---------- 2. modal ดูรายละเอียด ----------
    const modalInstance = () => {
        const api = bootstrapApi()?.Modal;

        return api && modal ? api.getOrCreateInstance(modal) : null;
    };

    const loadPreview = (url) => {
        const request = ++previewRequest;
        previewUrl = url;
        content.setAttribute('aria-busy', 'true');

        return fetchImpl(url, {headers: {Accept: 'text/html'}, credentials: 'same-origin'})
            .then((response) => (response.ok ? response.text() : Promise.reject(new Error(`HTTP ${response.status}`))))
            .then((html) => {
                if (request !== previewRequest) return false;
                content.innerHTML = html;

                return true;
            })
            .catch(() => {
                if (request !== previewRequest) return false;
                const state = content.querySelector('.ticket-preview__state');
                if (state) {
                    state.textContent = 'โหลดรายละเอียดไม่สำเร็จ — ปิดหน้าต่างแล้วลองอีกครั้ง หรือเปิดหน้ารายละเอียดเต็ม';
                    state.classList.add('is-error');
                }

                return false;
            })
            .finally(() => {
                if (request === previewRequest) content.setAttribute('aria-busy', 'false');
            });
    };

    page.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-ticket-preview]');
        if (! trigger || ! content || ! loading) return;
        // เปิดแท็บใหม่ด้วยปุ่มลัด — ปล่อยให้ลิงก์ทำงานตามปกติ
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        if (openPreview(trigger.dataset.ticketPreview, trigger)) event.preventDefault();
    });

    /** เปิด modal พร้อมสถานะกำลังโหลดแล้วโหลดเนื้อหา — คืน false เมื่อไม่มี Bootstrap (ลิงก์ทำงานตามปกติแทน) */
    function openPreview(url, trigger = null) {
        const instance = modalInstance();
        if (! instance || ! content || ! loading) return false;

        opener = trigger;
        content.innerHTML = loading.innerHTML;
        instance.show();
        loadPreview(url);

        return true;
    }

    // ลิงก์ ?ticket={id} (แจ้งเตือน / redirect จาก show) — server ตรวจ TicketPolicy::view แล้วจึงใส่ data-ticket-open-preview
    if (page.dataset.ticketOpenPreview) openPreview(page.dataset.ticketOpenPreview);

    modal?.addEventListener('hidden.bs.modal', () => {
        previewRequest++;
        previewUrl = null;
        // แถวอาจถูกแทนที่ระหว่างเปิด modal — คืนโฟกัสให้ลิงก์ของ Ticket เดียวกันในคิวปัจจุบัน
        const url = opener?.dataset.ticketPreview;
        const target = opener?.isConnected
            ? opener
            : [...page.querySelectorAll('.ticket-queue__open[data-ticket-preview]')].find((link) => link.dataset.ticketPreview === url);
        target?.focus();
        opener = null;
    });

    // ---------- 3. รับงาน / เข้าร่วม ----------
    page.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-ticket-action-form]');
        if (! form) return;
        event.preventDefault();
        if (form.dataset.submitting === 'true') return;

        form.dataset.submitting = 'true';
        const buttons = [...form.querySelectorAll('button[type="submit"]')];
        buttons.forEach((button) => {
            button.disabled = true;
            setBusy(button, true);
        });

        // ห้ามใช้ form.action — ฟอร์มรับงานมี <input name="action"> ซึ่งบัง property นี้ (ได้ HTMLInputElement แทน URL)
        fetchImpl(form.getAttribute('action'), {
            method: 'POST',
            body: new view.FormData(form),
            headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf},
            credentials: 'same-origin',
        })
            .then((response) => response.json().catch(() => ({})).then((payload) => ({response, payload})))
            .then(({response, payload}) => {
                const ok = response.ok && payload.ok !== false;
                const message = payload.message
                    || (response.status === 403 ? 'คุณไม่มีสิทธิ์ดำเนินการกับ Ticket นี้' : 'ดำเนินการไม่สำเร็จ กรุณาลองอีกครั้ง');
                if (ok) toast(swal, 'success', message);
                else rejected(swal, message);
            })
            .catch(() => rejected(swal, 'เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ กรุณาลองอีกครั้ง'))
            .finally(() => {
                // ทั้งสำเร็จและไม่สำเร็จ (เช่น 409) ต้องแสดงสถานะล่าสุดจาก server
                const pending = [refresh()];
                if (previewUrl && modal?.contains(form)) pending.push(loadPreview(previewUrl));

                return Promise.all(pending).finally(() => {
                    if (! form.isConnected) return;
                    form.dataset.submitting = 'false';
                    buttons.forEach((button) => {
                        button.disabled = false;
                        setBusy(button, false);
                    });
                });
            });
    });

    schedule();

    return {refresh, loadPreview, stop: () => clearTimer(timer)};
}
