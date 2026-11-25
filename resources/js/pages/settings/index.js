import {initializeProfileImagePicker} from './profile-image.js';
import {initializeProfileAppearance} from './appearance.js';
import {initializeTelegramSettings, disposeTelegramSettings} from './telegram.js';

export function openPasswordModal(root = document, bootstrapApi = globalThis.window?.bootstrap) {
    const modalElement = root.querySelector('[data-password-modal][data-open-on-load="true"]');
    if (! modalElement || typeof bootstrapApi?.Modal?.getOrCreateInstance !== 'function') return false;
    bootstrapApi.Modal.getOrCreateInstance(modalElement).show();
    return true;
}

export function initializeSettingsPage(root = document, bootstrapApi = globalThis.window?.bootstrap, options = {}) {
    const settings = root.querySelector('[data-settings-page]');
    if (! settings || settings.dataset.accountReady === 'true') return false;
    settings.dataset.accountReady = 'true';
    const fetcher = options.fetcher ?? globalThis.fetch?.bind(globalThis);
    const win = root.defaultView;
    const appearance = initializeProfileAppearance(root);
    const trigger = root.querySelector('#topbarAccountTrigger');
    const busy = new Set();
    let opener = trigger;
    let switching = false;
    let galleryReturn = null;

    const feedback = (modal, message, success = false) => {
        const box = modal?.querySelector('[data-account-feedback]');
        if (! box) return;
        box.className = `alert ${success ? 'alert-success' : 'alert-danger'}`;
        box.textContent = message;
    };
    const lock = (modal, labelWhileBusy = 'กำลังบันทึก…') => {
        const focused = root.activeElement;
        busy.add(modal);
        modal.setAttribute('aria-busy', 'true');
        const controls = [...modal.querySelectorAll('button, input:not([type="hidden"]), select, textarea')];
        const disabled = controls.map((control) => control.disabled);
        controls.forEach((control) => { control.disabled = true; });
        const submit = modal.querySelector('[type="submit"]');
        const label = submit?.innerHTML;
        if (submit && labelWhileBusy) submit.textContent = labelWhileBusy;
        return () => {
            busy.delete(modal);
            modal.removeAttribute('aria-busy');
            controls.forEach((control, index) => { control.disabled = disabled[index]; });
            if (submit) submit.innerHTML = label;
            if (modal.classList.contains('show') && modal.contains(focused) && !modal.contains(root.activeElement)) {
                focused.focus();
            }
        };
    };
    const show = (element) => bootstrapApi?.Modal?.getOrCreateInstance(element).show();
    const open = (id, source) => {
        const target = root.getElementById(id);
        if (! target || switching || busy.size || ! bootstrapApi?.Modal) return;
        if (root.querySelector('[data-modal-layer-id]:not([hidden])')) return;
        bootstrapApi.Dropdown?.getInstance(trigger)?.hide();
        const current = root.querySelector('.modal.show');
        // Other feature dialogs retain control until the user closes them.
        if (current && ! current.matches('.account-modal')) return;
        if (current === target) return;
        if (! current) opener = source?.closest('.dropdown-menu') ? trigger : (source ?? trigger);
        if (current) {
            if (target.hasAttribute('data-frame-gallery') && source?.closest('[data-account-form="appearance"]')) {
                galleryReturn = {modal: current, button: source};
            }
            switching = true;
            current.addEventListener('hidden.bs.modal', () => {
                target.addEventListener('shown.bs.modal', () => { switching = false; }, {once: true});
                show(target);
            }, {once: true});
            bootstrapApi.Modal.getOrCreateInstance(current).hide();
        } else {
            show(target);
        }
    };
    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-account-open]');
        if (! button) return;
        event.preventDefault();
        const modalUrl = button.dataset.accountModalUrl;
        if (modalUrl && !root.getElementById(button.dataset.accountOpen)) {
            if (switching || busy.size || !bootstrapApi?.Modal) return;
            const parent = button.closest('.account-modal');
            if (parent && !parent.classList.contains('show')) return;
            const errorMessage = button.parentElement.querySelector('[data-account-load-error]');
            if (errorMessage) errorMessage.textContent = '';
            const unlock = parent ? lock(parent, null) : () => {
                busy.delete(button);
                button.removeAttribute('aria-busy');
            };
            if (!parent) {
                busy.add(button);
                button.setAttribute('aria-busy', 'true');
            }
            let failed = false;
            try {
                const response = await fetcher(modalUrl, {
                    headers: {Accept: 'application/json'},
                    signal: win.AbortSignal?.timeout?.(15000),
                });
                if (!response.ok || response.redirected) throw new Error();
                const {html} = await response.json();
                const template = root.createElement('template');
                template.innerHTML = html;
                const modal = template.content.firstElementChild;
                if (modal?.id !== button.dataset.accountOpen || !modal.matches('.account-modal')) throw new Error();
                await appearance.mount(modal, settings);
                initializeProfileImagePicker(modal);
                bindModal(modal);
            } catch {
                failed = true;
            } finally {
                unlock();
            }
            if (failed) {
                const message = parent ? 'ไม่สามารถโหลดกรอบโปรไฟล์ กรุณาลองอีกครั้ง' : 'โหลดการตกแต่งโปรไฟล์ไม่สำเร็จ กรุณาลองอีกครั้ง';
                if (parent) feedback(parent, message);
                else if (errorMessage) {
                    errorMessage.textContent = message;
                    bootstrapApi.Dropdown?.getOrCreateInstance(trigger)?.show();
                }
                button.focus();
                return;
            }
        }
        open(button.dataset.accountOpen, button);
    });

    function bindModal(modal) {
        modal.addEventListener('hide.bs.modal', (event) => {
            if (busy.has(modal)) event.preventDefault();
        });
        modal.addEventListener('shown.bs.modal', () => {
            const initial = modal.querySelector('[data-account-initial-focus], input:not([type="hidden"]), .modal-footer button');
            initial?.focus();
        });
        modal.addEventListener('hidden.bs.modal', () => {
            if (modal.hasAttribute('data-frame-gallery') && galleryReturn) {
                const {modal: parent, button} = galleryReturn;
                galleryReturn = null;
                switching = true;
                parent.addEventListener('shown.bs.modal', () => {
                    switching = false;
                    button.focus();
                }, {once: true});
                show(parent);
                return;
            }
            if (switching && galleryReturn?.modal === modal) return;
            const form = modal.querySelector('[data-account-form]');
            if (form) {
                form.reset();
                appearance.reset(form);
                clearErrors(form);
                form.querySelector('[data-profile-image-input]')?.dispatchEvent(new win.Event('change'));
            }
            modal.querySelector('[data-account-feedback]')?.classList.add('d-none');
            if (! switching) (opener?.isConnected ? opener : trigger)?.focus();
        });
    }
    settings.querySelectorAll('.account-modal').forEach(bindModal);

    const refreshTelegram = async () => {
        const region = settings.querySelector('[data-account-telegram]');
        if (! region) return;
        const response = await fetcher(settings.dataset.settingsUrl, {headers: {Accept: 'application/json'}});
        if (! response.ok) throw new Error('อัปเดตสถานะ Telegram ไม่สำเร็จ กรุณาลองอีกครั้ง');
        const data = await response.json();
        disposeTelegramSettings(region);
        region.innerHTML = data.telegram_html;
        bindTelegram();
    };
    const submitForm = async (form) => {
        const modal = form.closest('.account-modal');
        if (busy.has(modal)) return;
        const body = new win.FormData(form);
        const unlock = lock(modal);
        clearErrors(form);
        modal.querySelector('[data-account-feedback]')?.classList.add('d-none');
        try {
            const response = await fetcher(form.action, {
                method: 'POST', body,
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': root.querySelector('meta[name="csrf-token"]')?.content ?? ''},
            });
            if (response.redirected || (response.headers?.get('content-type') && ! response.headers.get('content-type').includes('application/json'))) {
                throw new Error('เซสชันหมดอายุ กรุณาโหลดหน้าใหม่และเข้าสู่ระบบอีกครั้ง');
            }
            const data = await response.json().catch(() => ({}));
            if (! response.ok) {
                if (response.status === 422) fieldErrors(form, data.errors ?? {});
                const messages = {401: 'กรุณาเข้าสู่ระบบใหม่', 403: 'คุณไม่มีสิทธิ์ดำเนินการนี้',
                    419: 'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่', 429: 'ทำรายการบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่'};
                throw new Error(messages[response.status] ?? (response.status === 422
                    ? Object.values(data.errors ?? {}).flat()[0] ?? 'กรุณาตรวจสอบข้อมูล' : 'บันทึกไม่สำเร็จ กรุณาลองอีกครั้ง'));
            }
            if (data.user) updateIdentity(root, data.user);
            if (data.appearance) appearance.commit(form, data.appearance);
            if (form.dataset.accountForm === 'password') form.reset();
            if (form.dataset.accountForm === 'profile') {
                [...form.elements].filter((field) => field.type !== 'file').forEach((field) => { field.defaultValue = field.value; });
                const image = form.querySelector('[type="file"]');
                image.value = '';
                image.dispatchEvent(new win.Event('change'));
            }
            feedback(modal, data.message, true);
            if (form.closest('[data-account-telegram]')) await refreshTelegram();
        } catch (error) {
            feedback(modal, error.message === 'Failed to fetch' ? 'เชื่อมต่อไม่สำเร็จ กรุณาลองอีกครั้ง' : error.message);
        } finally {
            unlock();
            form.querySelector('.is-invalid')?.focus();
        }
    };
    const bindTelegram = () => initializeTelegramSettings(root, {
        bootstrapApi, fetcher, submitForm,
        setBusy: () => lock(root.getElementById('settingsModal')),
        reload: async () => {
            try {
                await refreshTelegram();
                feedback(root.getElementById('settingsModal'), 'เชื่อมต่อ Telegram เรียบร้อยแล้ว', true);
            } catch (error) { feedback(root.getElementById('settingsModal'), error.message); }
        },
        reportError: (message) => feedback(root.getElementById('settingsModal'), message),
    });
    settings.addEventListener('submit', (event) => {
        if (! event.target.matches('[data-account-form], [data-account-telegram] form')) return;
        // Telegram unlink confirms before calling the shared submit helper.
        if (event.target.matches('[data-telegram-unlink-form]')) return;
        event.preventDefault();
        submitForm(event.target);
    });
    settings.querySelector('[data-account-logout]')?.addEventListener('submit', (event) => {
        const modal = event.target.closest('.account-modal');
        if (busy.has(modal)) { event.preventDefault(); return; }
        lock(modal);
    });
    initializeProfileImagePicker(root);
    root.querySelectorAll('[data-account-avatar] img').forEach((image) => {
        image.dataset.avatarImage = '';
        if (image.complete && image.naturalWidth === 0) image.remove();
    });
    bindTelegram();
    const marked = settings.querySelector('[data-open-on-load="true"]');
    if (marked) {
        const other = root.querySelector('.modal.show');
        if (other) other.addEventListener('hidden.bs.modal', () => open(marked.id), {once: true});
        else if (root.querySelector('[data-modal-layer-id]:not([hidden])')) {
            const observer = new win.MutationObserver(() => {
                if (root.querySelector('[data-modal-layer-id]:not([hidden])')) return;
                observer.disconnect();
                open(marked.id);
            });
            observer.observe(root.body, {subtree: true, attributes: true, attributeFilter: ['hidden']});
        }
        else if (marked.id) open(marked.id);
        else openPasswordModal(root, bootstrapApi);
    }
    return true;
}

function clearErrors(form) {
    form.querySelectorAll('.is-invalid').forEach((field) => {
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        const describedBy = (field.getAttribute('aria-describedby') ?? '').split(' ').filter((id) => id && id !== `${field.id}Error`);
        if (describedBy.length) field.setAttribute('aria-describedby', describedBy.join(' '));
        else field.removeAttribute('aria-describedby');
    });
    form.querySelectorAll('.invalid-feedback').forEach((error) => error.remove());
}

function fieldErrors(form, errors) {
    Object.entries(errors).forEach(([name, messages]) => {
        const field = form.elements.namedItem(name);
        if (! field?.classList) return;
        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');
        const error = form.ownerDocument.createElement('div');
        error.className = 'invalid-feedback d-block';
        error.id = `${field.id}Error`;
        error.textContent = messages[0];
        field.setAttribute('aria-describedby', `${field.getAttribute('aria-describedby') ?? ''} ${error.id}`.trim());
        (field.closest('[data-profile-image-picker]') ?? field).after(error);
    });
    form.querySelector('.is-invalid')?.focus();
}

function updateIdentity(root, user) {
    root.querySelectorAll('[data-account-name]').forEach((label) => { label.textContent = user.name; });
    root.querySelectorAll('[data-account-avatar]').forEach((avatar) => {
        avatar.textContent = user.initials;
        if (user.avatar_url) {
            const image = root.createElement('img');
            image.alt = '';
            image.dataset.avatarImage = '';
            image.loading = 'lazy';
            image.src = user.avatar_url;
            avatar.append(image);
        }
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initializeSettingsPage(document), {once: true});
    } else {
        initializeSettingsPage(document);
    }
}
