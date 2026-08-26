/**
 * ตัวจัดการชั้นของ modal ที่ใช้ร่วมกันทุกกล่องในหน้า Task Workspace
 *
 * เดิมแต่ละ modal สลับ `hidden` และ add/remove `body.modal-open` เอง โดยไม่มีการนับชั้น
 * ผลคือเปิดหน้าจัดการผู้ร่วมงานทับ Task Workspace แล้วได้ backdrop สองชั้นทับกัน
 * และปิดชั้นบนทีเดียวก็ปลดล็อก body ทั้งที่ยังมี modal เปิดค้างอยู่
 *
 * ส่วนคำนวณสถานะทั้งหมดแยกเป็นฟังก์ชันบริสุทธิ์ เพื่อให้ทดสอบได้โดยไม่ต้องมี DOM
 */

/* ---------- pure state ---------- */

export function pushModal(stack, id) {
    return stack.includes(id) ? [...stack] : [...stack, id];
}

export function popModal(stack, id) {
    return stack.filter((entry) => entry !== id);
}

export function topOf(stack) {
    return stack.length ? stack[stack.length - 1] : null;
}

/** body ถูกล็อกตราบใดที่ยังมี modal เปิดอยู่อย่างน้อยหนึ่งใบ */
export function shouldLockBody(stack) {
    return stack.length > 0;
}

/** วาด backdrop ทึบเฉพาะชั้นบนสุด ชั้นล่างต้องโปร่งเพื่อไม่ให้สีทับกันจนมืดผิดปกติ */
export function shouldPaintBackdrop(stack, id) {
    return topOf(stack) === id;
}

/** z-index ไล่ตามความลึกของชั้น แก้ปัญหา modal ที่ z-index เท่ากันแล้วบังกันตามลำดับ DOM */
export function layerZIndex(stack, id, base = 1200, step = 10) {
    const index = stack.indexOf(id);

    return index === -1 ? base : base + (index * step);
}

/* ---------- DOM controller ---------- */

const FOCUSABLE = [
    'a[href]', 'button:not([disabled])', 'input:not([disabled])', 'select:not([disabled])',
    'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])',
].join(',');

export function createModalStack(doc = globalThis.document) {
    let stack = [];
    const elements = new Map();
    const openers = new Map();
    let counter = 0;

    const idOf = (element) => {
        if (!element.dataset.modalLayerId) {
            counter += 1;
            element.dataset.modalLayerId = `modal-${counter}`;
            elements.set(element.dataset.modalLayerId, element);
        }
        return element.dataset.modalLayerId;
    };

    const syncLayers = () => {
        elements.forEach((element, id) => {
            const isOpen = stack.includes(id);
            if (!isOpen) {
                element.removeAttribute('data-modal-backdrop');
                element.removeAttribute('inert');
                element.style.removeProperty('z-index');
                return;
            }

            element.dataset.modalBackdrop = shouldPaintBackdrop(stack, id) ? 'on' : 'off';
            element.style.zIndex = String(layerZIndex(stack, id));
            // ชั้นที่ไม่ใช่บนสุดต้องไม่รับ focus และไม่ถูกอ่านโดย screen reader
            if (topOf(stack) === id) element.removeAttribute('inert');
            else element.setAttribute('inert', '');
        });

        doc.body.classList.toggle('modal-open', shouldLockBody(stack));
    };

    const focusInside = (element) => {
        const target = element.querySelector('[autofocus]')
            || element.querySelector(FOCUSABLE);
        if (target) requestAnimationFrame(() => target.focus());
    };

    const open = (element, opener = null) => {
        const id = idOf(element);
        if (stack.includes(id)) return;

        openers.set(id, opener || (doc.activeElement instanceof Element ? doc.activeElement : null));
        stack = pushModal(stack, id);
        element.hidden = false;
        syncLayers();
        focusInside(element);
    };

    const close = (element) => {
        const id = element.dataset.modalLayerId;
        if (!id || !stack.includes(id)) return;

        stack = popModal(stack, id);
        element.hidden = true;
        syncLayers();

        const opener = openers.get(id);
        openers.delete(id);
        if (opener && opener.isConnected) opener.focus();
    };

    const isTop = (element) => topOf(stack) === element.dataset.modalLayerId;

    const topElement = () => elements.get(topOf(stack)) || null;

    doc.addEventListener('keydown', (event) => {
        const element = topElement();
        if (!element) return;

        if (event.key === 'Escape') {
            // Escape ต้องปิดเฉพาะชั้นบนสุด ไม่ใช่ทุกใบพร้อมกัน
            event.stopPropagation();
            element.dispatchEvent(new CustomEvent('modalstack:dismiss', {bubbles: false}));
            return;
        }

        if (event.key !== 'Tab') return;

        const focusable = [...element.querySelectorAll(FOCUSABLE)].filter((node) => node.offsetParent !== null || node === doc.activeElement);
        if (!focusable.length) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && doc.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && doc.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }, true);

    return {open, close, isTop, topElement, get stack() { return [...stack]; }};
}

/** ใช้อินสแตนซ์เดียวทั้งหน้า เพื่อให้ทุก modal นับชั้นร่วมกันจริง */
let shared = null;
let sharedDocument = null;

export function modalStack(doc = globalThis.document) {
    if (!doc) return shared;

    // ถ้า document เปลี่ยน อินสแตนซ์เดิมจะชี้ไปยัง body และ element ที่ไม่มีอยู่แล้ว
    if (!shared || sharedDocument !== doc) {
        shared = createModalStack(doc);
        sharedDocument = doc;
    }

    return shared;
}
