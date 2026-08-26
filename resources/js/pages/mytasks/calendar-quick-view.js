/**
 * Calendar Event Popover — คลิกรายการแล้วดูข้อมูลย่อโดยไม่ออกจากหน้าปฏิทิน
 *
 * นี่ไม่ใช่ modal: ไม่มี backdrop ไม่ล็อก body ไม่ trap focus ปฏิทินด้านหลังยังมองเห็นและ
 * ใช้งานต่อได้ปกติเสมอ ตำแหน่งคำนวณจาก getBoundingClientRect() ของ Event ที่ถูกคลิก (anchor)
 * แล้ววางแบบ position: fixed ติดกับ Event นั้น จอแคบเกินกว่าจะวางข้าง Event จะเปลี่ยนเป็น
 * bottom sheet แทน ใช้ instance เดียวทั้งหน้า เปิดรายการใหม่จะเปลี่ยนเนื้อหาในกล่องเดิม
 *
 * เนื้อหาถูก fetch ตอนคลิกเท่านั้น (lazy) และ server เป็นผู้ตรวจสิทธิ์ทุกครั้ง
 * โมดูลนี้ไม่ตัดสินใจเรื่องสิทธิ์เอง และไม่เดาชนิดของรายการจากสีหรือ id
 */

/* ---------- pure state ---------- */

/** ทุกครั้งที่เปิดใหม่ต้องได้ token ใหม่ เพื่อรู้ว่าคำตอบที่กลับมาเป็นของคำขอล่าสุดหรือไม่ */
export function nextRequestToken(token) {
    return (Number(token) || 0) + 1;
}

/** คำตอบที่มาช้ากว่าการคลิกครั้งใหม่ต้องถูกทิ้ง ห้ามเขียนทับเนื้อหาที่ผู้ใช้กำลังดู */
export function isStaleResponse(currentToken, responseToken) {
    return currentToken !== responseToken;
}

/* ---------- pure positioning ---------- */

const GUTTER = 12;
const ANCHOR_OFFSET = 8;
const CARET_SIZE = 16;
const CARET_MARGIN = 20;
export const SHEET_BREAKPOINT = 640;

export function clamp(value, min, max) {
    if (max < min) return min;
    return Math.min(Math.max(value, min), max);
}

/** จอแคบกว่านี้ให้เปลี่ยนเป็น bottom sheet แทนการวาง popover ข้าง Event */
export function shouldUseSheet(viewportWidth, breakpoint = SHEET_BREAKPOINT) {
    return viewportWidth <= breakpoint;
}

/**
 * คำนวณตำแหน่ง popover จาก anchor (Event ที่คลิก): เริ่มจากวางใต้ Event ชิดขอบซ้าย (bottom-start)
 * เว้นระยะจาก Event ตาม offset และเว้นจากขอบ viewport อย่างน้อย gutter เสมอ
 * ถ้าด้านล่างไม่พอให้ flip ขึ้นบน ถ้าด้านขวาไม่พอให้ shift ไปทางซ้าย ห้ามหลุดออกนอก viewport
 */
export function computePopoverPlacement({anchorRect, width, height, viewportWidth, viewportHeight, gutter = GUTTER, offset = ANCHOR_OFFSET}) {
    let placement = 'bottom';
    let top = anchorRect.bottom + offset;
    if (top + height > viewportHeight - gutter) {
        placement = 'top';
        top = anchorRect.top - height - offset;
    }
    const maxTop = Math.max(gutter, viewportHeight - height - gutter);
    top = clamp(top, gutter, maxTop);

    const maxLeft = Math.max(gutter, viewportWidth - width - gutter);
    const left = clamp(anchorRect.left, gutter, maxLeft);

    const anchorCenter = anchorRect.left + (anchorRect.width / 2);
    const caretMax = Math.max(CARET_MARGIN, width - CARET_MARGIN);
    const caretLeft = clamp(anchorCenter - left, CARET_MARGIN, caretMax);
    const caretHidden = anchorCenter < left - CARET_SIZE || anchorCenter > left + width + CARET_SIZE;

    return {top, left, placement, caretLeft, caretHidden};
}

/* ---------- DOM ---------- */

const ERROR_MESSAGE = 'โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';

export function createCalendarQuickView(doc = globalThis.document, fetchImpl = null) {
    const popover = doc.querySelector('[data-quick-view-popover]');
    if (!popover) return null;

    const view = popover.ownerDocument.defaultView || globalThis;
    const caret = popover.querySelector('[data-quick-view-caret]');
    const body = popover.querySelector('[data-quick-view-body]');
    const titleNode = popover.querySelector('[data-quick-view-title]');
    const kickerNode = popover.querySelector('[data-quick-view-kicker]');
    const detailLink = popover.querySelector('[data-quick-view-detail]');
    const closeButton = popover.querySelector('[data-close-quick-view]');

    let token = 0;
    let lastRequest = null;
    let lastDetailUrl = '';
    let controller = null;
    let anchor = null;
    let isOpen = false;

    const request = (...args) => (fetchImpl || globalThis.fetch)(...args);

    const renderState = (html) => {
        body.innerHTML = html;
    };

    const showLoading = () => {
        titleNode.textContent = 'กำลังโหลด...';
        detailLink.hidden = true;
        renderState('<p class="calendar-quick-view__state" data-quick-view-loading><i class="bi bi-arrow-repeat" aria-hidden="true"></i> กำลังโหลดข้อมูล...</p>');
    };

    const showError = () => {
        titleNode.textContent = 'เปิดข้อมูลไม่สำเร็จ';
        detailLink.hidden = true;
        renderState(
            '<div class="calendar-quick-view__state is-error" data-quick-view-error>'
            + `<p>${ERROR_MESSAGE}</p>`
            + '<button type="button" class="task-secondary" data-quick-view-retry>ลองใหม่</button>'
            + '</div>'
        );
    };

    /**
     * หัวเรื่อง/kicker อ่านจาก partial ได้ แต่ปลายทางของลิงก์ต้องมาจาก event ที่ระบบสร้างเองเสมอ
     * ห้ามรับ URL จาก HTML ที่ endpoint ส่งกลับ มิฉะนั้น response จะเปลี่ยนปลายทางของลิงก์ได้
     * ข้อความ/ไอคอนของลิงก์เป็นของ shell คงที่ (ดูรายละเอียดทั้งหมด →) จึงแค่สลับ hidden/href
     */
    const adoptContent = (detailUrl) => {
        const content = body.querySelector('[data-quick-view-type]');
        if (!content) return;

        titleNode.textContent = content.dataset.quickViewTitleText || '';
        kickerNode.textContent = content.dataset.quickViewKickerText || 'ดูอย่างย่อ';
        detailLink.hidden = !detailUrl;
        if (detailUrl) detailLink.href = detailUrl;
    };

    /**
     * วางตำแหน่งใหม่ทุกครั้งที่เนื้อหาเปลี่ยน (loading -> จริง) เพราะความสูงเปลี่ยนไป
     * จอแคบเปลี่ยนเป็น bottom sheet ทั้งกล่อง ไม่ต้องคำนวณตำแหน่งอิง anchor อีก
     */
    const reposition = () => {
        if (!isOpen || !anchor) return;

        const viewportWidth = view.innerWidth;
        const viewportHeight = view.innerHeight;

        if (shouldUseSheet(viewportWidth)) {
            popover.dataset.mode = 'sheet';
            popover.removeAttribute('data-placement');
            popover.style.top = '';
            popover.style.left = '';
            return;
        }

        delete popover.dataset.mode;
        const anchorRect = anchor.getBoundingClientRect();
        const popoverRect = popover.getBoundingClientRect();
        const placement = computePopoverPlacement({
            anchorRect,
            width: popoverRect.width,
            height: popoverRect.height,
            viewportWidth,
            viewportHeight,
        });

        popover.style.top = `${placement.top}px`;
        popover.style.left = `${placement.left}px`;
        popover.dataset.placement = placement.placement;
        popover.toggleAttribute('data-caret-hidden', placement.caretHidden);
        if (caret) caret.style.left = `${placement.caretLeft}px`;
    };

    const load = async (url, detailUrl = '') => {
        token = nextRequestToken(token);
        const currentToken = token;
        lastRequest = url;
        lastDetailUrl = detailUrl;

        // ยกเลิกคำขอก่อนหน้าถ้าเบราว์เซอร์รองรับ แต่ token guard ยังเป็นตัวตัดสินสุดท้ายเสมอ
        controller?.abort();
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        showLoading();
        reposition();

        try {
            const response = await request(url, {
                headers: {Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
                signal: controller?.signal,
            });
            if (!response.ok) throw new Error('quick view failed');

            const html = await response.text();
            // ผู้ใช้อาจคลิกรายการใหม่หรือปิดหน้าต่างระหว่างรอ คำตอบเก่าต้องไม่แทนที่ของใหม่
            if (isStaleResponse(token, currentToken)) return;

            renderState(html);
            adoptContent(detailUrl);
        } catch (_) {
            if (isStaleResponse(token, currentToken)) return;
            showError();
        }

        if (isOpen) reposition();
    };

    const open = (url, triggerAnchor = null, detailUrl = '') => {
        if (anchor && anchor !== triggerAnchor) anchor.setAttribute('aria-expanded', 'false');
        anchor = triggerAnchor || null;
        isOpen = true;
        popover.hidden = false;
        anchor?.setAttribute('aria-expanded', 'true');

        reposition();
        requestAnimationFrame(() => closeButton?.focus());

        load(url, detailUrl);
    };

    const close = () => {
        if (!isOpen) return;

        // เลื่อน token เพื่อให้คำตอบที่ยังค้างอยู่กลายเป็น stale จะได้ไม่เขียนทับกล่องที่ปิดไปแล้ว
        token = nextRequestToken(token);
        controller?.abort();
        controller = null;
        isOpen = false;
        popover.hidden = true;
        popover.removeAttribute('data-mode');
        popover.removeAttribute('data-placement');
        popover.removeAttribute('data-caret-hidden');
        popover.style.top = '';
        popover.style.left = '';

        // ล้างเนื้อหาทิ้งหลังปิด เพื่อไม่ให้ข้อมูลของรายการเดิมค้างอยู่ใน DOM
        // และไม่แวบให้เห็นของเก่าเสี้ยววินาทีตอนเปิดรายการถัดไป
        renderState('');
        titleNode.textContent = 'กำลังโหลด...';
        detailLink.hidden = true;

        const previousAnchor = anchor;
        anchor = null;
        if (previousAnchor) {
            previousAnchor.setAttribute('aria-expanded', 'false');
            if (previousAnchor.isConnected) previousAnchor.focus();
        }
    };

    closeButton?.addEventListener('click', close);

    body.addEventListener('click', (event) => {
        if (event.target.closest('[data-quick-view-retry]') && lastRequest) load(lastRequest, lastDetailUrl);
    });

    // คลิกนอกกล่องแล้วปิด ยกเว้นคลิก Event บนปฏิทิน (calendar.js เป็นผู้ตัดสินใจเปิด/สลับเนื้อหาเอง)
    //
    // ต้องเป็น capture phase: ปุ่มลองใหม่แทนที่ innerHTML ของ body ทันทีตอน bubble-phase handler
    // ของมันทำงาน (showLoading()) ทำให้ event.target (ปุ่มลองใหม่) หลุดออกจาก DOM ก่อนคลิกจะ
    // bubble มาถึง document — popover.contains(target) จะเป็น false เพราะ target หลุดไปแล้ว
    // แล้วเข้าใจผิดว่าเป็นการคลิกนอกกล่อง ปิดตัวเองทั้งที่เพิ่งลองใหม่ไปเอง capture phase ทำงาน
    // ก่อน DOM ถูกแก้เสมอ จึงเห็น target ที่ยังอยู่ในตำแหน่งเดิมจริง
    doc.addEventListener('click', (event) => {
        if (!isOpen || popover.contains(event.target) || event.target.closest('[data-calendar-task]')) return;
        close();
    }, true);

    doc.addEventListener('keydown', (event) => {
        if (!isOpen || event.key !== 'Escape') return;
        event.stopPropagation();
        close();
    });

    view.addEventListener('resize', () => { if (isOpen) reposition(); });

    // เลื่อนหน้าแล้วตำแหน่ง anchor เปลี่ยน ปิดอย่างปลอดภัยแทนการคำนวณตำแหน่งใหม่ทุกเฟรม
    // ยกเว้นการเลื่อนเนื้อหาภายในกล่องเอง (อ่านข้อมูลยาว) และโหมด bottom sheet ที่ไม่ได้อิง anchor
    view.addEventListener('scroll', (event) => {
        if (!isOpen || popover.dataset.mode === 'sheet' || popover.contains(event.target)) return;
        close();
    }, true);

    return {
        open,
        close,
        popover,
        get token() { return token; },
        get isOpen() { return isOpen; },
    };
}
