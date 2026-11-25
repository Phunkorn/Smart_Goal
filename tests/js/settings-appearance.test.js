import test from 'node:test';
import assert from 'node:assert/strict';
import {initializeProfileAppearance} from '../../resources/js/pages/settings/appearance.js';
import {initializeProfileFrames} from '../../resources/js/components/profile-frames.js';
import {mountDom} from './helpers/dom.js';

test('shared frame observation is idempotent and releases listeners and animation on teardown', (t) => {
    const ui = mountDom('<span class="sg-profile-portrait" data-profile-frame="aurora"><img data-profile-frame-overlay hidden></span>');
    t.after(ui.cleanup);
    let callback, observes = 0, disconnected = false;
    ui.window.IntersectionObserver = class {
        constructor(fn) { callback = fn; }
        observe() { observes++; }
        disconnect() { disconnected = true; }
    };
    const media = new ui.window.EventTarget();
    media.matches = false;
    ui.window.matchMedia = () => media;
    const controller = initializeProfileFrames(ui.document);
    controller.observe(ui.document);
    assert.equal(observes, 1);
    const portrait = ui.document.querySelector('span');
    callback([{target: portrait, isIntersecting: true}]);
    assert.ok(portrait.hasAttribute('data-frame-visible'));
    controller.destroy();
    assert.ok(disconnected);
    assert.equal(portrait.hasAttribute('data-frame-visible'), false);
    const overlay = portrait.querySelector('img');
    overlay.src = '/late-load.webp';
    overlay.dispatchEvent(new ui.window.Event('load'));
    assert.ok(overlay.hidden, 'disposed load listener must not reveal a stale frame');
    media.dispatchEvent(new ui.window.Event('change'));
    controller.observe(ui.document);
    assert.equal(observes, 1);
});

function setup(t) {
    const ui = mountDom(`<form data-account-form="appearance" data-profile-user="7">
        <input type="file" data-appearance-cover data-max-bytes="4096" accept="image/png,image/jpeg,image/webp">
        <input type="checkbox" data-appearance-remove>
        <input type="hidden" data-appearance-frame data-saved-frame="gold" value="gold">
        <div data-profile-cover data-cover-url="/old"><img src="/old"></div><span data-profile-frame="gold"></span>
        </form><article data-profile-user="7"><div data-profile-cover><img src="/old"></div><span data-profile-frame="gold"></span></article>
        <article data-profile-user="8"><div data-profile-cover><img src="/other"></div><span data-profile-frame="none"></span></article>`);
    t.after(ui.cleanup);
    const revoked = [];
    ui.window.URL.createObjectURL = () => 'blob:preview';
    ui.window.URL.revokeObjectURL = (url) => revoked.push(url);
    const controller = initializeProfileAppearance(ui.document);
    const form = ui.document.querySelector('form');
    const input = form.querySelector('input[type="file"]');
    const change = (element) => element.dispatchEvent(new ui.window.Event('change'));
    const choose = (type = 'image/png', size = 10) => {
        Object.defineProperty(input, 'files', {configurable: true, value: [new ui.window.File(['x'.repeat(size)], 'cover.png', {type})]});
        change(input);
    };
    return {...ui, controller, revoked, form, input, change, choose};
}

test('local preview changes immediately without changing saved cards; cancel releases it and restores saved choices', (t) => {
    const ui = setup(t);
    const select = ui.form.querySelector('[data-appearance-frame]');
    select.value = 'aurora';
    ui.change(select);
    ui.choose();
    assert.equal(ui.form.querySelector('[data-profile-frame]').dataset.profileFrame, 'aurora');
    assert.equal(ui.form.querySelector('img').getAttribute('src'), 'blob:preview');
    assert.equal(ui.document.querySelector('article img').getAttribute('src'), '/old');
    Object.defineProperty(ui.input, 'files', {configurable: true, value: []});
    ui.form.reset();
    ui.controller.reset(ui.form);
    assert.equal(ui.form.querySelector('img').getAttribute('src'), '/old');
    assert.equal(ui.form.querySelector('[data-profile-frame]').dataset.profileFrame, 'gold');
    assert.deepEqual(ui.revoked, ['blob:preview']);
});

test('save updates only matching members, clears upload state, and establishes the next cancel baseline', (t) => {
    const ui = setup(t);
    ui.choose();
    Object.defineProperty(ui.input, 'files', {configurable: true, value: []});
    ui.controller.commit(ui.form, {cover_url: '/saved?v=2', avatar_frame: 'aurora'});
    assert.equal(ui.document.querySelector('article img').getAttribute('src'), '/saved?v=2');
    assert.equal(ui.document.querySelectorAll('article img')[1].getAttribute('src'), '/other');
    assert.deepEqual(ui.revoked, ['blob:preview']);
    ui.form.querySelector('[data-appearance-frame]').value = 'none';
    ui.form.reset();
    ui.controller.reset(ui.form);
    assert.equal(ui.form.querySelector('[data-appearance-frame]').value, 'aurora');
    assert.equal(ui.form.querySelector('img').getAttribute('src'), '/saved?v=2');
    ui.controller.commit(ui.form, {cover_url: null, avatar_frame: 'none'});
    assert.equal(ui.form.querySelector('img'), null);
    assert.equal(ui.document.querySelector('article').querySelector('img'), null);
});

test('invalid local files keep the saved preview and block form validity', (t) => {
    const ui = setup(t);
    ui.choose('image/svg+xml');
    assert.equal(ui.input.validity.customError, true);
    assert.equal(ui.form.querySelector('img').getAttribute('src'), '/old');
    ui.choose('image/png', 4097);
    assert.equal(ui.input.validity.customError, true);
    ui.choose();
    assert.equal(ui.input.validity.customError, false);
});

test('removing the saved cover previews the fallback and cancel restores it', (t) => {
    const ui = setup(t);
    const remove = ui.form.querySelector('[data-appearance-remove]');
    remove.checked = true;
    ui.change(remove);
    assert.equal(ui.form.querySelector('img'), null);
    ui.form.reset();
    ui.controller.reset(ui.form);
    assert.equal(ui.form.querySelector('img').getAttribute('src'), '/old');
});
