const DIRECTORY_SELECTOR = '[data-work-board-directory]';

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
    let activeRequest = 0;
    let controller = null;
    let shown = false;
    const defaultTitle = title.textContent;
    const tooltipApi = options.bootstrapApi ?? globalThis.window?.bootstrap;
    const tooltips = [...directory.querySelectorAll('[data-member-preview-trigger][title]')]
        .map((trigger) => tooltipApi?.Tooltip?.getOrCreateInstance(trigger, {placement: 'top', trigger: 'hover focus'}))
        .filter(Boolean);

    const clearHeader = () => {
        [headerAvatar, headerMeta, headerAction].filter(Boolean).forEach((slot) => {
            slot.replaceChildren();
            slot.hidden = true;
        });
    };

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

    const selectedTab = () => body.querySelector('[data-preview-tab][aria-selected="true"]');

    const focusSelectedTab = () => {
        if (shown && panel.ownerDocument.activeElement === panel) {
            selectedTab()?.focus({preventScroll: true});
        }
    };

    const load = async (url = currentUrl) => {
        if (!url) {
            return;
        }

        currentUrl = url;
        const requestId = ++activeRequest;
        controller?.abort();
        const request = new AbortController();
        controller = request;
        showState('loading');

        try {
            const response = await fetchPreview(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: request.signal,
            });

            if (!response.ok) {
                throw new Error(`Preview request failed with ${response.status}`);
            }

            const html = await response.text();

            if (requestId !== activeRequest || request.signal.aborted) {
                return;
            }

            body.innerHTML = html;
            fillHeader();
            showState('ready');
            focusSelectedTab();
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
            tooltips.forEach((tooltip) => tooltip.hide());
            title.textContent = trigger.dataset.memberName || defaultTitle;
            load(trigger.dataset.previewUrl ?? '');
            return;
        }

        const tab = event.target.closest?.('[data-preview-tab]');

        if (tab && body.contains(tab)) {
            selectPreviewTab(body, tab.dataset.previewTab);
            return;
        }

        const retryButton = event.target.closest?.('[data-preview-retry]');

        if (retryButton && directory.contains(retryButton)) {
            panel.focus({preventScroll: true});
            load(currentUrl);
        }
    };

    const handleTabKeydown = (event) => {
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

    const handleKeydown = (event) => {
        if (!['ArrowUp', 'ArrowDown'].includes(event.key)) {
            handleTabKeydown(event);
            return;
        }

        const tab = selectedTab();
        const results = body.querySelector('[data-preview-tabpanel]:not([hidden])');
        if (!tab || !results || event.isComposing) return;

        const links = [...results.querySelectorAll('a.wb-spotlight-row[href]')];
        const index = links.indexOf(event.target);
        const down = event.key === 'ArrowDown';
        const next = event.target === tab && down
            ? links[0] ?? results
            : index >= 0
                ? links[index + (down ? 1 : -1)] ?? (down ? null : tab)
                : null;

        if (next) {
            event.preventDefault();
            next.focus();
        }
    };

    const cancelLoad = () => {
        activeRequest += 1;
        controller?.abort();
        controller = null;
    };

    const handleShown = () => {
        shown = true;
        focusSelectedTab();
    };

    const handleHide = () => {
        shown = false;
        cancelLoad();
    };

    const handleHidden = () => {
        panel.setAttribute('aria-busy', 'false');
        body.replaceChildren();
        body.hidden = true;
        clearHeader();
        title.textContent = defaultTitle;
    };

    directory.addEventListener('click', handleClick);
    panel.addEventListener('keydown', handleKeydown);
    panel.addEventListener('shown.bs.modal', handleShown);
    panel.addEventListener('hide.bs.modal', handleHide);
    panel.addEventListener('hidden.bs.modal', handleHidden);
    directory.dataset.workBoardReady = 'true';

    return {
        load,
        destroy() {
            tooltips.forEach((tooltip) => tooltip.dispose());
            cancelLoad();
            directory.removeEventListener('click', handleClick);
            panel.removeEventListener('keydown', handleKeydown);
            panel.removeEventListener('shown.bs.modal', handleShown);
            panel.removeEventListener('hide.bs.modal', handleHide);
            panel.removeEventListener('hidden.bs.modal', handleHidden);
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
