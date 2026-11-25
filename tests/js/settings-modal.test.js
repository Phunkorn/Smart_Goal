import assert from 'node:assert/strict';
import test from 'node:test';
import {JSDOM} from 'jsdom';
import {readFileSync} from 'node:fs';
import {initializeSettingsPage, openPasswordModal} from '../../resources/js/pages/settings/index.js';

test('opens the password modal after server validation errors', () => {
    const dom = new JSDOM('<main data-settings-page><div data-password-modal data-open-on-load="true"></div></main>');
    const shown = [];
    const bootstrapApi = {Modal: {getOrCreateInstance: (element) => ({show: () => shown.push(element)})}};
    assert.equal(initializeSettingsPage(dom.window.document, bootstrapApi), true);
    assert.equal(shown.length, 1);
});

test('does not open an unmarked password modal', () => {
    const dom = new JSDOM('<div data-password-modal></div>');
    assert.equal(openPasswordModal(dom.window.document, {}), false);
});

test('appearance uses the shared multipart flow, keeps the modal busy, and retains saved choices on reopen', async (t) => {
    let resolve;
    let body;
    const ui = accountUI(t, async (url, options) => {
        assert.equal(url, 'https://smartgoal.test/settings/appearance');
        body = options.body;
        return new Promise((done) => { resolve = done; });
    }, '', true);
    assert.equal(ui.doc.getElementById('settingsAppearanceModal'), null);
    await ui.open('settingsAppearanceModal');
    assert.equal(ui.appearanceLoads, 1);
    const modal = ui.doc.getElementById('settingsAppearanceModal');
    const select = modal.querySelector('[data-appearance-frame]');
    select.value = 'gold';
    select.dispatchEvent(new ui.win.Event('change'));
    const saveButton = modal.querySelector('[type="submit"]');
    saveButton.focus();
    ui.submit('[data-account-form="appearance"]');
    assert.equal(body.get('avatar_frame'), 'gold');
    assert.equal(body.get('_method'), 'PATCH');
    assert.equal(modal.getAttribute('aria-busy'), 'true');
    ui.win.bootstrap.Modal.getInstance(modal).hide();
    assert.equal(modal.classList.contains('show'), true);
    saveButton.blur();
    resolve({ok: true, json: async () => ({message: 'saved', appearance: {cover_url: '/new-cover', avatar_frame: 'gold'}})});
    await flush(ui.doc);
    assert.equal(modal.querySelector('[data-profile-cover] img').getAttribute('src'), '/new-cover');
    assert.equal(ui.doc.activeElement, saveButton);
    ui.win.bootstrap.Modal.getInstance(modal).hide();
    await flush(ui.doc);
    await ui.open('settingsAppearanceModal');
    assert.equal(ui.appearanceLoads, 1);
    assert.equal(select.value, 'gold');
    assert.equal(modal.querySelector('[data-profile-frame]').dataset.profileFrame, 'gold');
});

// jsdom has no CSS transitions. Complete Bootstrap's pending transitions explicitly,
// including backdrop -> dialog chains, instead of racing a fixed wall-clock delay.
const flush = async (doc) => {
    for (let turn = 0; turn < 6; turn++) {
        await new Promise((resolve) => setImmediate(resolve));
        doc?.querySelectorAll('.modal-backdrop, .modal-dialog, .modal').forEach((element) => {
            element.dispatchEvent(new doc.defaultView.Event('transitionend', {bubbles: true}));
        });
    }
};

function accountUI(t, fetcher = async () => ({ok: true, json: async () => ({message: 'saved'})}), telegram = '', deferAppearance = false) {
    const modal = (id, form) => `<div class="modal fade account-modal" id="${id}" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
        <button data-bs-dismiss="modal" aria-label="close">close</button>${form}</div></div></div>`;
    const feedback = '<div class="alert d-none" data-account-feedback role="status"></div>';
    const appearanceModal = modal('settingsAppearanceModal', `<form action="/settings/appearance" data-account-form="appearance" data-profile-user="7">${feedback}
        <input name="_token" type="hidden" value="csrf"><input name="_method" type="hidden" value="PATCH">
        <input id="settingsProfileCover" name="profile_cover" type="file" data-appearance-cover data-max-bytes="4096" accept="image/png">
        <input name="remove_profile_cover" type="checkbox" data-appearance-remove>
        <input type="hidden" id="settingsAvatarFrame" name="avatar_frame" data-appearance-frame data-saved-frame="none" value="none">
        <div data-profile-cover data-cover-url="/old-cover"><img src="/old-cover"></div><span data-profile-frame="none"></span>
        <button type="submit">save</button></form>`);
    let appearanceLoads = 0;
    const dom = new JSDOM(`<!doctype html><html><head><meta name="csrf-token" content="csrf"></head><body>
        <header class="topbar"><div class="dropdown"><button id="topbarAccountTrigger" data-bs-toggle="dropdown">account</button>
        <ul class="dropdown-menu"><li><button class="dropdown-item" data-account-open="settingsProfileModal">profile</button></li>
        <li><button class="dropdown-item" data-account-open="settingsPasswordModal">password</button></li>
        <li><button class="dropdown-item" data-account-open="settingsAppearanceModal" data-account-modal-url="/settings/appearance">appearance</button></li>
        <li><button class="dropdown-item" data-account-open="settingsModal">settings</button></li>
        <li><button class="dropdown-item" data-account-open="accountLogoutModal">logout</button></li></ul></div>
        <span data-account-name>Old</span><span data-account-avatar>Ol</span></header>
        <main id="outside" tabindex="0">content</main><div class="settings-page" data-settings-page data-settings-url="/settings">
        ${modal('settingsProfileModal', `<form action="/settings" data-account-form="profile">${feedback}
            <input name="_token" type="hidden" value="csrf"><input name="_method" type="hidden" value="PATCH">
            <label for="settingsName">Name</label><input id="settingsName" name="name" value="Old">
            <input id="settingsProfileImage" name="profile_image" type="file"><button type="submit">save</button></form>`)}
        ${modal('settingsPasswordModal', `<form action="/settings/password" data-account-form="password">${feedback}
            <input name="current_password" type="password"><input name="password" type="password"><input name="password_confirmation" type="password">
            <button type="submit">save</button></form>`)}
        ${deferAppearance ? '' : appearanceModal}
        ${modal('settingsModal', `${feedback}<button data-account-open="settingsPasswordModal">password</button>${telegram ? `<div data-account-telegram>${telegram}</div>` : ''}`)}
        ${modal('accountLogoutModal', '<form action="/logout" method="POST" data-account-logout><input type="hidden" name="_token" value="csrf"><button type="submit">confirm</button></form>')}
        </div></body></html>`, {url: 'https://smartgoal.test/tasks', runScripts: 'outside-only', pretendToBeVisual: true});
    t.after(() => dom.window.close());
    // jsdom has no layout engine; Bootstrap's visibility check needs a box.
    dom.window.HTMLElement.prototype.getClientRects = () => [{width: 100, height: 30}];
    dom.window.eval(readFileSync(new URL('../../node_modules/bootstrap/dist/js/bootstrap.bundle.js', import.meta.url), 'utf8'));
    const doc = dom.window.document;
    initializeSettingsPage(doc, dom.window.bootstrap, {fetcher: (url, options) => {
        if (deferAppearance && url === '/settings/appearance' && !options.method) {
            appearanceLoads++;
            return Promise.resolve({ok: true, json: async () => ({html: appearanceModal})});
        }
        return fetcher(url, options);
    }});
    const click = (selector) => doc.querySelector(selector).click();
    const open = async (id) => {
        click('#topbarAccountTrigger');
        click(`[data-account-open="${id}"]`);
        await flush(doc);
    };
    const submit = (selector) => doc.querySelector(selector).dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));
    return {doc, win: dom.window, click, open, submit, get appearanceLoads() { return appearanceLoads; }};
}

test('real Bootstrap dropdown closes outside and on Escape, then opens one modal with focus return', async (t) => {
    const ui = accountUI(t);
    ui.click('#topbarAccountTrigger');
    assert.equal(ui.doc.querySelector('.dropdown-menu').classList.contains('show'), true);
    ui.click('#outside');
    assert.equal(ui.doc.querySelector('.dropdown-menu').classList.contains('show'), false);
    ui.click('#topbarAccountTrigger');
    ui.doc.querySelector('#topbarAccountTrigger').dispatchEvent(new ui.win.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
    assert.equal(ui.doc.querySelector('.dropdown-menu').classList.contains('show'), false);
    await ui.open('settingsProfileModal');
    assert.equal(ui.doc.querySelector('.dropdown-menu').classList.contains('show'), false);
    assert.equal(ui.doc.querySelectorAll('.modal.show').length, 1);
    assert.equal(ui.doc.activeElement.id, 'settingsName');
    assert.equal(ui.doc.body.classList.contains('modal-open'), true);
    ui.doc.querySelector('#outside').focus();
    assert.equal(ui.doc.getElementById('settingsProfileModal').contains(ui.doc.activeElement), true);
    ui.doc.querySelector('#settingsProfileModal').dispatchEvent(new ui.win.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
    await flush(ui.doc);
    assert.equal(ui.doc.querySelectorAll('.modal.show').length, 0);
    assert.equal(ui.doc.activeElement.id, 'topbarAccountTrigger');
    assert.equal(ui.doc.body.classList.contains('modal-open'), false);
});

test('profile saves once with multipart CSRF, blocks dismissal while saving, and updates identity safely', async (t) => {
    let resolve;
    const requests = [];
    const ui = accountUI(t, (url, options) => { requests.push({url, options}); return new Promise((done) => { resolve = done; }); });
    await ui.open('settingsProfileModal');
    ui.doc.querySelector('#settingsName').value = '<New Name>';
    ui.submit('[data-account-form="profile"]');
    ui.submit('[data-account-form="profile"]');
    assert.equal(requests.length, 1);
    assert.equal(requests[0].options.body.get('name'), '<New Name>');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf');
    ui.win.bootstrap.Modal.getInstance(ui.doc.getElementById('settingsProfileModal')).hide();
    assert.equal(ui.doc.getElementById('settingsProfileModal').classList.contains('show'), true);
    resolve({ok: true, json: async () => ({message: 'saved', user: {name: '<New Name>', initials: '<N', avatar_url: '/avatar?v=2'}})});
    await flush(ui.doc);
    assert.equal(ui.doc.querySelector('[data-account-name]').textContent, '<New Name>');
    assert.equal(ui.doc.querySelector('[data-account-name]').children.length, 0);
    assert.match(ui.doc.querySelector('[data-account-avatar] img').src, /avatar\?v=2/);
    assert.equal(ui.doc.querySelector('#settingsProfileModal [data-account-feedback]').textContent, 'saved');
    ui.win.bootstrap.Modal.getInstance(ui.doc.getElementById('settingsProfileModal')).hide(); await flush(ui.doc);
    assert.equal(ui.doc.getElementById('settingsName').value, '<New Name>');
});

test('server errors appear below the field and focus it; retry can recover', async (t) => {
    let status = 422;
    const ui = accountUI(t, async () => ({
        ok: status === 200, status,
        json: async () => status === 422 ? {errors: {name: ['Name is required']}} : {message: 'saved'},
    }));
    await ui.open('settingsProfileModal');
    ui.submit('[data-account-form="profile"]'); await flush(ui.doc);
    assert.equal(ui.doc.getElementById('settingsNameError').textContent, 'Name is required');
    assert.equal(ui.doc.getElementById('settingsName').getAttribute('aria-invalid'), 'true');
    assert.equal(ui.doc.activeElement.id, 'settingsName');
    status = 200;
    ui.submit('[data-account-form="profile"]'); await flush(ui.doc);
    assert.equal(ui.doc.getElementById('settingsNameError'), null);
    assert.equal(ui.doc.getElementById('settingsName').disabled, false);
});

test('switching account dialogs does not stack backdrops and passwords clear on success and close', async (t) => {
    const ui = accountUI(t);
    await ui.open('settingsModal');
    ui.click('#settingsModal [data-account-open]'); await flush(ui.doc); await flush(ui.doc);
    assert.equal(ui.doc.querySelectorAll('.modal.show').length, 1);
    assert.equal(ui.doc.querySelectorAll('.modal-backdrop').length, 1);
    const fields = ui.doc.querySelectorAll('[type="password"]');
    fields.forEach((field) => { field.value = 'test-only'; });
    ui.submit('[data-account-form="password"]'); await flush(ui.doc);
    assert.equal([...fields].every((field) => field.value === ''), true);
    fields[0].value = 'unsaved';
    ui.click('#settingsPasswordModal [data-bs-dismiss]'); await flush(ui.doc);
    assert.equal([...fields].every((field) => field.value === ''), true);
    assert.equal(ui.doc.querySelectorAll('.modal-backdrop').length, 0);
});

test('logout asks for confirmation and only posts when the confirmation form is submitted', async (t) => {
    let requests = 0;
    const ui = accountUI(t, async () => { requests++; });
    await ui.open('accountLogoutModal');
    assert.equal(requests, 0);
    const form = ui.doc.querySelector('[data-account-logout]');
    assert.equal(form.method, 'post');
    assert.match(form.action, /\/logout$/);
    ui.click('#accountLogoutModal [data-bs-dismiss]'); await flush(ui.doc);
    assert.equal(requests, 0);
    await ui.open('accountLogoutModal');
    assert.equal(ui.submit('[data-account-logout]'), true);
    assert.equal(new ui.win.FormData(form).get('_token'), 'csrf');
    assert.equal(ui.submit('[data-account-logout]'), false);
});

test('session expiry and network errors keep the dialog open and allow retries', async (t) => {
    let status = 419;
    const ui = accountUI(t, async () => {
        if (status === 0) throw new Error('Failed to fetch');
        return {ok: false, status, json: async () => ({})};
    });
    await ui.open('settingsProfileModal');
    for (const code of [419, 429, 0]) {
        status = code;
        ui.submit('[data-account-form="profile"]'); await flush(ui.doc);
        assert.equal(ui.doc.querySelectorAll('.modal.show').length, 1);
        assert.equal(ui.doc.querySelector('#settingsProfileModal [type="submit"]').disabled, false);
        assert.equal(ui.doc.querySelector('#settingsProfileModal [data-account-feedback]').classList.contains('alert-danger'), true);
    }
});

test('Telegram unlink confirms inline and updates its card through existing endpoints', async (t) => {
    const telegram = `<section data-telegram-settings><form action="/settings/telegram" data-telegram-unlink-form>
        <input name="_method" type="hidden" value="DELETE"><button type="submit">unlink</button></form>
        <div data-telegram-unlink-confirm hidden><button data-telegram-unlink-no>cancel</button><button data-telegram-unlink-yes>confirm</button></div></section>`;
    const requests = [];
    const ui = accountUI(t, async (url, options) => {
        requests.push({url, options});
        return {ok: true, json: async () => options?.method === 'POST' ? {message: 'unlinked'}
            : {telegram_html: '<section data-telegram-settings>disconnected</section>'}};
    }, telegram);
    await ui.open('settingsModal');
    ui.submit('[data-telegram-unlink-form]');
    assert.equal(ui.doc.querySelector('[data-telegram-unlink-confirm]').hidden, false);
    assert.equal(requests.length, 0);
    ui.click('[data-telegram-unlink-no]');
    assert.equal(ui.doc.querySelector('[data-telegram-unlink-confirm]').hidden, true);
    ui.submit('[data-telegram-unlink-form]');
    ui.click('[data-telegram-unlink-yes]'); await flush(ui.doc);
    assert.equal(requests[0].options.body.get('_method'), 'DELETE');
    assert.equal(requests[1].url, '/settings');
    assert.equal(ui.doc.querySelector('[data-account-telegram]').textContent, 'disconnected');
    assert.equal(ui.doc.querySelectorAll('.modal.show').length, 1);
    assert.equal(ui.win.location.pathname, '/tasks');
});
