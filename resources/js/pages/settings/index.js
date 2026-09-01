export function openPasswordModal(root = document, bootstrapApi = globalThis.window?.bootstrap) {
    const modalElement = root.querySelector('[data-password-modal][data-open-on-load="true"]');
    if (! modalElement || typeof bootstrapApi?.Modal?.getOrCreateInstance !== 'function') return false;
    bootstrapApi.Modal.getOrCreateInstance(modalElement).show();
    return true;
}

export function initializeSettingsPage(root = document, bootstrapApi = globalThis.window?.bootstrap) {
    if (! root.querySelector('[data-settings-page]')) return false;
    openPasswordModal(root, bootstrapApi);
    return true;
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initializeSettingsPage(document), {once: true});
    } else {
        initializeSettingsPage(document);
    }
}
