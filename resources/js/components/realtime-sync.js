const ACTIVE_INTERVAL = 3000;
const MAX_BACKOFF = 60000;
const MAX_DROPDOWN_ITEMS = 15;
const REFRESH_EVENT = 'smartgoal:realtime-refresh';
const escapeSelector = (root, value) => root.defaultView.CSS?.escape
    ? root.defaultView.CSS.escape(String(value))
    : String(value).replace(/["\\]/g, '\\$&');

export function updateNotificationCount(root, count) {
    const value = Math.max(0, Number(count) || 0);
    root.querySelectorAll('[data-notification-count]').forEach((badge) => {
        badge.textContent = value > 99 ? '99+' : String(value);
        badge.hidden = value === 0;
    });
    root.querySelectorAll('[data-notification-summary]').forEach((summary) => {
        summary.textContent = `${value} รายการ`;
        summary.classList.toggle('amber', value > 0);
        summary.classList.toggle('gray', value === 0);
    });
}

function notificationItem(root, event) {
    const item = root.createElement('div');
    item.className = 'p-2 mb-2 notification-item d-flex gap-2 align-items-start is-new';
    item.dataset.dropdownNotificationId = String(event.id);

    const link = root.createElement('a');
    link.className = 'notification-body';
    link.href = event.url;

    const title = root.createElement('div');
    title.className = 'notification-title';
    title.textContent = event.title;
    const badge = root.createElement('span');
    badge.className = 'notification-new';
    badge.textContent = 'ใหม่';
    title.append(' ', badge);

    const message = root.createElement('div');
    message.className = 'notification-meta notification-meta-tight';
    message.textContent = event.message || event.relative_time || '';
    link.append(title, message);
    item.append(link);
    return item;
}

export function prependDropdownNotification(root, event) {
    if (root.querySelector(`[data-dropdown-notification-id="${escapeSelector(root, event.id)}"]`)) return false;
    const list = root.querySelector('[data-notification-dropdown-list]');
    if (! list) return false;

    list.prepend(notificationItem(root, event));
    root.querySelector('[data-notification-dropdown-empty]')?.setAttribute('hidden', '');
    while (list.querySelectorAll('[data-dropdown-notification-id]').length > MAX_DROPDOWN_ITEMS) {
        list.querySelector('[data-dropdown-notification-id]:last-child')?.remove();
    }
    return true;
}

function toastRegion(root) {
    let region = root.querySelector('[data-realtime-toast-region]');
    if (region) return region;
    region = root.createElement('div');
    region.className = 'realtime-toast-region';
    region.dataset.realtimeToastRegion = '';
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-atomic', 'false');
    root.body.append(region);
    return region;
}

export function showRealtimeToast(root, event, setTimer = globalThis.setTimeout) {
    const toast = root.createElement('article');
    toast.className = `realtime-toast realtime-toast--${event.category || 'system'}`;
    const icon = root.createElement('i');
    icon.className = event.category === 'comment' ? 'bi bi-chat-left-text' : 'bi bi-bell';
    icon.setAttribute('aria-hidden', 'true');
    const copy = root.createElement('div');
    const title = root.createElement('strong');
    title.textContent = event.title;
    const message = root.createElement('span');
    message.textContent = event.message || '';
    copy.append(title, message);
    const link = root.createElement('a');
    link.href = event.url;
    link.textContent = event.category === 'comment' ? 'เปิดคอมเมนต์' : 'เปิดดู';
    toast.append(icon, copy, link);

    const region = toastRegion(root);
    region.prepend(toast);
    while (region.children.length > 4) region.lastElementChild?.remove();
    const removalTimer = setTimer(() => toast.remove(), 12000);
    removalTimer?.unref?.();
    return toast;
}

export function applyRealtimePayload(root, payload, seen = new Set()) {
    updateNotificationCount(root, payload.unread_count);
    if (payload.comment_receipts) {
        root.dispatchEvent(new root.defaultView.CustomEvent('smartgoal:comment-receipts', {detail: payload.comment_receipts}));
    }
    for (const event of payload.events || []) {
        if (seen.has(event.id)) continue;
        seen.add(event.id);
        prependDropdownNotification(root, event);
        showRealtimeToast(root, event);
        root.dispatchEvent(new root.defaultView.CustomEvent('smartgoal:realtime-notification', {detail: event}));
    }
}

export function initializeRealtimeSync(
    root = document,
    fetchImpl = globalThis.fetch,
    setTimer = globalThis.setTimeout,
    clearTimer = globalThis.clearTimeout,
) {
    const shell = root.body;
    const endpoint = shell?.dataset.realtimeSyncUrl;
    if (! endpoint || typeof fetchImpl !== 'function' || shell.dataset.realtimeInitialized === 'true') return null;
    shell.dataset.realtimeInitialized = 'true';

    let cursor = Math.max(0, Number(shell.dataset.realtimeCursor) || 0);
    let delay = ACTIVE_INTERVAL;
    let timer = null;
    let pending = false;
    let stopped = false;
    const seen = new Set();

    const schedule = (wait = delay) => {
        clearTimer(timer);
        if (stopped || root.hidden) return;
        timer = setTimer(poll, wait);
    };

    const poll = async () => {
        if (stopped || pending || root.hidden) return;
        pending = true;
        try {
            const url = new URL(endpoint, root.defaultView.location.origin);
            url.searchParams.set('after', String(cursor));
            if (shell.dataset.realtimeTaskId) url.searchParams.set('task_id', shell.dataset.realtimeTaskId);
            const response = await fetchImpl(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (response.status === 401 || response.status === 419) {
                stopped = true;
                return;
            }
            if (! response.ok) throw new Error('sync failed');
            const payload = await response.json();
            applyRealtimePayload(root, payload, seen);
            cursor = Math.max(cursor, Number(payload.cursor) || cursor);
            shell.dataset.realtimeCursor = String(cursor);
            delay = ACTIVE_INTERVAL;
            schedule(payload.has_more ? 0 : delay);
        } catch (_) {
            delay = Math.min(MAX_BACKOFF, Math.max(ACTIVE_INTERVAL, delay * 2));
            schedule(delay);
        } finally {
            pending = false;
        }
    };

    const resume = () => {
        if (root.hidden || stopped) return;
        schedule(0);
    };
    root.addEventListener('visibilitychange', resume);
    root.addEventListener(REFRESH_EVENT, resume);
    root.defaultView.addEventListener('focus', resume);
    root.defaultView.addEventListener('online', resume);
    schedule();

    return {
        poll,
        stop() {
            stopped = true;
            clearTimer(timer);
            root.removeEventListener('visibilitychange', resume);
            root.removeEventListener(REFRESH_EVENT, resume);
            root.defaultView.removeEventListener('focus', resume);
            root.defaultView.removeEventListener('online', resume);
        },
    };
}

if (typeof document !== 'undefined') initializeRealtimeSync(document);
