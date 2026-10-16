const DIRECTORY_SELECTOR = '[data-work-board-directory]';

/**
 * สลับแท็บภายในแผง Preview (งานวันนี้ / งานที่ต้องจัดการ / สร้างวันนี้)
 *
 * แผงทั้งหมดถูกวาดมาจาก server แล้ว ที่นี่แค่สลับ hidden กับ aria-selected
 * จึงไม่มีข้อมูลใดถูกดึงหรือคำนวณเพิ่มฝั่ง client
 */
export function selectPreviewTab(root, key, {focus = false} = {}) {
    const tabs = [...root.querySelectorAll('[data-preview-tab]')];
    const target = tabs.find((tab) => tab.dataset.previewTab === key);

    if (!target) {
        return false;
    }

    tabs.forEach((tab) => {
        const selected = tab === target;
        tab.setAttribute('aria-selected', String(selected));
        tab.tabIndex = selected ? 0 : -1;
        tab.classList.toggle('is-active', selected);
    });
    root.querySelectorAll('[data-preview-tabpanel]').forEach((panel) => {
        panel.hidden = panel.dataset.previewTabpanel !== key;
    });

    if (focus) {
        target.focus();
    }

    return true;
}

export function initializeDepartmentWorkBoard(root = document, options = {}) {
    const directory = root.matches?.(DIRECTORY_SELECTOR)
        ? root
        : root.querySelector?.(DIRECTORY_SELECTOR);

    if (!directory || directory.dataset.workBoardReady === 'true') {
        return null;
    }

    const panel = directory.querySelector('[data-member-preview-panel]');
    const loading = directory.querySelector('[data-preview-loading]');
    const error = directory.querySelector('[data-preview-error]');
    const body = directory.querySelector('[data-preview-body]');
    const title = directory.querySelector('[data-preview-panel-title]');
    const retry = directory.querySelector('[data-preview-retry]');
    // ช่องในส่วนหัวเป็นตัวเลือก — หน้าที่ไม่มีช่องเหล่านี้ยังโหลดรายการงานได้ตามเดิม
    const headerAvatar = directory.querySelector('[data-preview-header-avatar]');
    const headerMeta = directory.querySelector('[data-preview-header-meta]');
    const headerAction = directory.querySelector('[data-preview-header-action]');

    if (!panel || !loading || !error || !body || !title || !retry) {
        return null;
    }

    const fetchPreview = options.fetch ?? globalThis.fetch?.bind(globalThis);

    if (!fetchPreview) {
        return null;
    }
    let currentUrl = '';
    let currentName = '';
    let activeRequest = 0;
    let controller = null;
    let lastTrigger = null;

    const clearHeader = () => {
        [headerAvatar, headerMeta, headerAction].filter(Boolean).forEach((slot) => {
            slot.replaceChildren();
            slot.hidden = true;
        });
    };

    /*
     * ย้ายส่วนหัวที่ server วาดมา (<template data-preview-header>) ขึ้นไปไว้ใน header ของแผง
     *
     * header อยู่นอกพื้นที่เลื่อน ชื่อ บทบาท และแผนกจึงค้างอยู่ด้านบนขณะเลื่อนดูรายการงาน
     * ทุกครั้งที่โหลดใหม่ช่องจะถูกล้างก่อน (clearHeader) ข้อมูลของคนก่อนจึงไม่ค้าง
     */
    const fillHeader = () => {
        const template = body.querySelector('template[data-preview-header]');

        if (!template) {
            return;
        }

        const fill = (slot, selector) => {
            const source = template.content.querySelector(selector);

            if (!slot || !source) {
                return;
            }

            slot.replaceChildren(...source.childNodes);
            slot.hidden = false;
        };

        fill(headerAvatar, '[data-preview-header-avatar]');
        fill(headerMeta, '[data-preview-header-meta]');
        fill(headerAction, '[data-preview-header-action]');

        if (template.dataset.memberName) {
            title.textContent = template.dataset.memberName;
        }

        template.remove();
    };

    const showState = (state) => {
        loading.hidden = state !== 'loading';
        error.hidden = state !== 'error';
        body.hidden = state !== 'ready';
        panel.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');

        if (state === 'loading') {
            body.replaceChildren();
            clearHeader();
        }
    };

    const load = async (url = currentUrl) => {
        if (!url) {
            return;
        }

        currentUrl = url;
        const requestId = ++activeRequest;
        controller?.abort();
        controller = new AbortController();
        showState('loading');

        try {
            const response = await fetchPreview(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`Preview request failed with ${response.status}`);
            }

            const html = await response.text();

            if (requestId !== activeRequest || controller.signal.aborted) {
                return;
            }

            body.innerHTML = html;
            fillHeader();
            showState('ready');
        } catch (requestError) {
            if (requestId !== activeRequest || requestError?.name === 'AbortError') {
                return;
            }

            showState('error');
        }
    };

    const handleClick = (event) => {
        const trigger = event.target.closest?.('[data-member-preview-trigger]');

        if (trigger && directory.contains(trigger)) {
            lastTrigger = trigger;
            currentUrl = trigger.dataset.previewUrl || '';
            currentName = trigger.dataset.memberName || '';
            // ชื่อขึ้นทันทีที่กด ส่วนรูป บทบาท และแผนกตามมาเมื่อ server ตอบกลับ (fillHeader)
            title.textContent = currentName || 'งานของสมาชิก';
            load(currentUrl);
            return;
        }

        const tab = event.target.closest?.('[data-preview-tab]');

        if (tab && body.contains(tab)) {
            selectPreviewTab(body, tab.dataset.previewTab);
            return;
        }

        const retryButton = event.target.closest?.('[data-preview-retry]');

        if (retryButton && directory.contains(retryButton)) {
            load(currentUrl);
        }
    };

    // แป้นลูกศรเลื่อนระหว่างแท็บตามแบบแผนของ role="tablist"
    const handleKeydown = (event) => {
        const current = event.target.closest?.('[data-preview-tab]');

        if (!current || !body.contains(current) || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const tabs = [...body.querySelectorAll('[data-preview-tab]')];
        const index = tabs.indexOf(current);
        const next = event.key === 'Home'
            ? 0
            : event.key === 'End'
                ? tabs.length - 1
                : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        selectPreviewTab(body, tabs[next].dataset.previewTab, {focus: true});
    };

    const handleHidden = () => {
        activeRequest += 1;
        controller?.abort();
        panel.setAttribute('aria-busy', 'false');

        if (lastTrigger?.isConnected) {
            lastTrigger.focus();
        }
    };

    directory.addEventListener('click', handleClick);
    directory.addEventListener('keydown', handleKeydown);
    panel.addEventListener('hidden.bs.offcanvas', handleHidden);
    directory.dataset.workBoardReady = 'true';

    return {
        load,
        destroy() {
            activeRequest += 1;
            controller?.abort();
            directory.removeEventListener('click', handleClick);
            directory.removeEventListener('keydown', handleKeydown);
            panel.removeEventListener('hidden.bs.offcanvas', handleHidden);
            delete directory.dataset.workBoardReady;
        },
    };
}

const boot = () => {
    document.querySelectorAll(DIRECTORY_SELECTOR).forEach((directory) => {
        initializeDepartmentWorkBoard(directory);
    });
};

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once: true});
    } else {
        boot();
    }
}
