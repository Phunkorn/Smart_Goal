const DIRECTORY_SELECTOR = '[data-work-board-directory]';

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

    const showState = (state) => {
        loading.hidden = state !== 'loading';
        error.hidden = state !== 'error';
        body.hidden = state !== 'ready';
        panel.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');

        if (state === 'loading') {
            body.replaceChildren();
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
            title.textContent = currentName ? `งานของ ${currentName}` : 'งานของสมาชิก';
            load(currentUrl);
            return;
        }

        const retryButton = event.target.closest?.('[data-preview-retry]');

        if (retryButton && directory.contains(retryButton)) {
            load(currentUrl);
        }
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
    panel.addEventListener('hidden.bs.offcanvas', handleHidden);
    directory.dataset.workBoardReady = 'true';

    return {
        load,
        destroy() {
            activeRequest += 1;
            controller?.abort();
            directory.removeEventListener('click', handleClick);
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
