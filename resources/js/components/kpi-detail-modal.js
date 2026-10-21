import {modalStack} from './modal-stack.js';

/*
 * กล่องรายละเอียดของการ์ดตัวเลขในหน้ารายงาน — ใช้ร่วมกันทั้งรายงานโปรเจกต์และรายงานปฏิบัติงาน
 *
 * การ์ดแต่ละใบเป็นปุ่ม [data-kpi-open] ที่บอก URL ของรายการจริงที่การ์ดนับ (data-kpi-url)
 * ไฟล์นี้โหลด HTML ชิ้นนั้นจาก server ซึ่ง render และ escape ด้วย Blade แล้ว มาใส่ในกล่องใบเดียวของหน้า
 * จึงไม่มีการประกอบ HTML จากข้อความใน JavaScript และสิทธิ์ทั้งหมดตัดสินที่ server
 *
 * สถานะของกล่อง (data-kpi-state): loading → ready | error
 * - กำลังโหลด: โครงจาก <template data-kpi-modal-loading> และ aria-busy="true"
 * - โหลดไม่สำเร็จ หรือได้หน้าอื่นกลับมา (เช่นหลุดออกจากระบบแล้วถูกพาไปหน้า login):
 *   ข้อความจาก <template data-kpi-modal-error> พร้อมปุ่มลองอีกครั้ง
 * - คำตอบที่มาช้ากว่าคำขอใหม่ (กดเปลี่ยนหน้าเร็ว ๆ หรือปิดแล้วเปิดการ์ดอื่น) ถูกทิ้งเสมอ
 *
 * backdrop การล็อกการเลื่อน การจับโฟกัส Escape และการคืนโฟกัสให้การ์ดเป็นหน้าที่ของ modal-stack
 * ซึ่งเป็นเจ้าของสถานะ overlay เพียงเจ้าเดียว ไฟล์นี้จึงไม่แตะ z-index และไม่ผูก Escape เอง
 */

/** แปลง HTML จาก server เป็น fragment — คืน null เมื่อไม่ใช่เนื้อหาของกล่องนี้ */
export function parseKpiDetail(html, doc = globalThis.document) {
    const template = doc.createElement('template');
    template.innerHTML = String(html ?? '');

    return template.content.querySelector('[data-kpi-detail]') ? template.content : null;
}

export function initKpiDetailModal(root = document, {
    stack = modalStack(root.ownerDocument ?? root),
    fetchImpl = globalThis.fetch?.bind(globalThis),
} = {}) {
    const doc = root.ownerDocument ?? root;
    const modal = root.querySelector('[data-kpi-modal]');

    if (!modal || modal.dataset.kpiModalReady === 'true') {
        return null;
    }

    modal.dataset.kpiModalReady = 'true';

    const body = modal.querySelector('[data-kpi-modal-body]');
    const title = modal.querySelector('[data-kpi-modal-title]');
    const loadingTemplate = modal.querySelector('template[data-kpi-modal-loading]');
    const errorTemplate = modal.querySelector('template[data-kpi-modal-error]');
    let requestId = 0;
    let controller = null;
    let currentUrl = '';

    const setState = (state) => {
        modal.dataset.kpiState = state;
        body.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
    };

    const showTemplate = (template) => body.replaceChildren(template ? template.content.cloneNode(true) : '');

    const cancel = () => {
        requestId += 1;
        controller?.abort();
        controller = null;
    };

    const load = async (url, {focusResult = false} = {}) => {
        cancel();
        const id = requestId;
        currentUrl = url;
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        setState('loading');
        showTemplate(loadingTemplate);

        try {
            const response = await fetchImpl(url, {
                headers: {Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
                signal: controller?.signal,
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const fragment = parseKpiDetail(await response.text(), doc);
            if (id !== requestId) return;
            if (!fragment) throw new Error('unexpected response');

            body.replaceChildren(fragment);
            setState('ready');
            // หลังเปลี่ยนหน้า ปุ่มที่กดถูกแทนที่ไปแล้ว โฟกัสจึงย้ายมาที่บรรทัดบอกช่วงที่แสดงแทนการหลุดไป body
            if (focusResult) body.querySelector('[data-kpi-detail-focus]')?.focus();
        } catch (error) {
            if (id !== requestId || error?.name === 'AbortError') return;
            setState('error');
            showTemplate(errorTemplate);
            if (focusResult) body.querySelector('[data-kpi-retry]')?.focus();
        }
    };

    const close = () => {
        cancel();
        stack.close(modal);
    };

    // delegation ที่ root ทีเดียว — เปิดซ้ำกี่ครั้งก็ไม่เพิ่ม listener
    root.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-kpi-open]');

        if (opener && !modal.contains(opener)) {
            const url = opener.dataset.kpiUrl;
            if (!url) return;

            title.textContent = opener.dataset.kpiLabel || '';
            stack.open(modal, opener);
            load(url);

            return;
        }

        if (!modal.contains(event.target)) return;

        const pageButton = event.target.closest('[data-kpi-page-url]');
        if (pageButton) {
            if (!pageButton.disabled) load(pageButton.dataset.kpiPageUrl, {focusResult: true});

            return;
        }

        if (event.target.closest('[data-kpi-retry]')) {
            load(currentUrl, {focusResult: true});

            return;
        }

        // คลิกบนฉากหลัง (ตัวกล่องเอง ไม่ใช่แผงข้างใน) ถือเป็นการปิด
        if (event.target.closest('[data-kpi-modal-close]') || event.target === modal) {
            close();
        }
    });

    modal.addEventListener('modalstack:dismiss', close);

    return {modal, close, load};
}
