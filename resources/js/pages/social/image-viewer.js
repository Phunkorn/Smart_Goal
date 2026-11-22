/*
 * ตัวดูรูปเต็มจอของโพสต์
 *
 * Bootstrap Modal (fullscreen) เป็นเจ้าของ backdrop การล็อกการเลื่อน focus trap และ Escape
 * ไฟล์นี้ดูแลรายการรูป ตำแหน่งปัจจุบัน สถานะโหลด/โหลดไม่สำเร็จ และการเลื่อนด้วยลูกศร
 * ปิดแล้วคืนโฟกัสให้รูปที่ผู้ใช้กดเปิด
 */

/** ตำแหน่งถัดไปแบบวนรอบ — ฟังก์ชันบริสุทธิ์ */
export function stepIndex(index, delta, length) {
    if (length <= 0) return 0;

    return ((index + delta) % length + length) % length;
}

export function readImages(card) {
    try {
        const images = JSON.parse(card?.dataset.images || '[]');

        return Array.isArray(images) ? images.filter((image) => image && typeof image.url === 'string') : [];
    } catch {
        return [];
    }
}

export function createImageViewer(element, {bootstrapApi} = {}) {
    if (! element) return null;

    const image = element.querySelector('[data-social-viewer-image]');
    const counter = element.querySelector('[data-social-viewer-counter]');
    const loading = element.querySelector('[data-social-viewer-loading]');
    const errorBox = element.querySelector('[data-social-viewer-error]');
    const prev = element.querySelector('[data-social-viewer-prev]');
    const next = element.querySelector('[data-social-viewer-next]');
    const state = {images: [], index: 0, trigger: null};

    const modal = () => bootstrapApi?.Modal?.getOrCreateInstance?.(element) ?? null;

    const render = () => {
        const current = state.images[state.index];
        if (! current) return;

        loading.hidden = false;
        errorBox.hidden = true;
        image.hidden = false;
        image.alt = current.alt || '';
        image.src = current.url;
        counter.textContent = state.images.length > 1 ? `${state.index + 1} / ${state.images.length}` : '';
        prev.hidden = state.images.length < 2;
        next.hidden = state.images.length < 2;
    };

    const go = (delta) => {
        state.index = stepIndex(state.index, delta, state.images.length);
        render();
    };

    image.addEventListener('load', () => {
        loading.hidden = true;
    });

    image.addEventListener('error', () => {
        if (! image.getAttribute('src')) return;
        loading.hidden = true;
        image.hidden = true;
        errorBox.hidden = false;
    });

    prev.addEventListener('click', () => go(-1));
    next.addEventListener('click', () => go(1));

    element.addEventListener('keydown', (event) => {
        if (state.images.length < 2) return;
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            go(-1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            go(1);
        }
    });

    element.addEventListener('hidden.bs.modal', () => {
        image.removeAttribute('src');
        const trigger = state.trigger;
        state.images = [];
        state.trigger = null;
        if (trigger?.isConnected) trigger.focus();
    });

    const open = (images, index = 0, trigger = null) => {
        if (! images.length) return false;

        state.images = images;
        state.index = Math.min(Math.max(0, index), images.length - 1);
        state.trigger = trigger;
        render();
        modal()?.show();

        return true;
    };

    return {open, go, state};
}
