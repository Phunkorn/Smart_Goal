import assert from 'node:assert/strict';
import {readdirSync, readFileSync} from 'node:fs';
import test from 'node:test';
import {errorMessageFor, firstErrorMessage, request} from '../../resources/js/pages/social/client.js';
import {shouldSubmitOnKey} from '../../resources/js/pages/social/comments.js';
import {screenFiles} from '../../resources/js/pages/social/composer.js';
import {insertAtCursor} from '../../resources/js/pages/social/emoji-picker.js';
import {applyLikeState} from '../../resources/js/pages/social/feed.js';
import {stepIndex} from '../../resources/js/pages/social/image-viewer.js';
import {initSocialPage} from '../../resources/js/pages/social/index.js';
import {click, mountDom} from './helpers/dom.js';

const viewsDir = new URL('../../resources/views/social/', import.meta.url);
const partialsDir = new URL('../../resources/views/social/partials/', import.meta.url);
const jsDir = new URL('../../resources/js/pages/social/', import.meta.url);
const bladeSources = [
    readFileSync(new URL('index.blade.php', viewsDir), 'utf8'),
    ...readdirSync(partialsDir).map((name) => readFileSync(new URL(name, partialsDir), 'utf8')),
];
const jsSources = readdirSync(jsDir).map((name) => readFileSync(new URL(name, jsDir), 'utf8'));

/* ---------- markup ที่ตรงกับ resources/views/social/* (เฉพาะ hook ที่ JS ใช้) ---------- */

function card(id, {liked = false, likes = 0, comments = 0, owner = true, images = []} = {}) {
    const imageData = JSON.stringify(images.map((url, index) => ({url, alt: `รูปภาพที่ ${index + 1}`}))).replaceAll('"', '&quot;');
    const postData = JSON.stringify({id, body: `โพสต์ ${id}`, visibility: id === 2 ? 'department' : 'everyone', department_label: id === 2 ? 'เฉพาะแผนกของฉัน (บัญชีเดิม)' : null, media: images.map((url, index) => ({id: 100 + index, url, alt: `รูป ${index}`}))}).replaceAll('"', '&quot;');

    return `<article class="social-card social-post" id="social-post-${id}" data-social-post data-post-id="${id}"
        data-like-url="/social/posts/${id}/like" data-comments-url="/social/posts/${id}/comments" data-images="${imageData}">
        <header>
            ${owner ? `<div class="dropdown"><button type="button" data-bs-toggle="dropdown">⋯</button><ul>
                <li><button type="button" data-social-post-edit data-update-url="/social/posts/${id}" data-post="${postData}">แก้ไข</button></li>
                <li><button type="button" data-social-post-delete data-destroy-url="/social/posts/${id}" data-moderation="false">ลบ</button></li>
            </ul></div>` : ''}
        </header>
        <div class="social-post__body">โพสต์ ${id}</div>
        ${images.length ? `<div class="social-media-grid">${images.map((url, index) => `<button type="button" class="social-media-grid__item" data-social-image-open="${index}"><img src="${url}" alt=""></button>`).join('')}</div>` : ''}
        <div class="social-post__stats">
            <span data-social-like-summary ${likes ? '' : 'hidden'}><span data-social-like-count>${likes}</span> ถูกใจ</span>
            <button type="button" class="social-post__comment-count" data-social-comments-toggle ${comments ? '' : 'hidden'}><span data-social-comment-count>${comments}</span> ความคิดเห็น</button>
        </div>
        <button type="button" class="social-post__action${liked ? ' is-active' : ''}" data-social-like aria-pressed="${liked}">
            <i class="bi ${liked ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up'}" data-social-like-icon></i>ถูกใจ</button>
        <button type="button" class="social-post__action" data-social-comments-toggle data-focus-input aria-expanded="false">แสดงความคิดเห็น</button>
        <section class="social-comments" data-social-comments hidden>
            <button type="button" data-social-comments-older hidden>ก่อนหน้า</button>
            <p data-social-comments-status hidden></p>
            <ul data-social-comment-list></ul>
            <p data-social-comments-empty hidden>ยังไม่มีความคิดเห็น</p>
            <form data-social-comment-form action="/social/posts/${id}/comments"><textarea name="body" data-social-comment-input></textarea><button type="submit">ส่ง</button></form>
        </section>
    </article>`;
}

function comment(id, {root = null, author = 'พนักงาน HR', body = 'ขอบคุณค่ะ'} = {}) {
    return `<li class="social-comment" id="social-comment-${id}" data-social-comment data-comment-id="${id}" data-root-id="${root ?? id}" data-author="${author}">
        <div class="social-comment__main">
            <div class="social-comment__bubble"><p data-social-comment-text>${body}</p></div>
            <div class="social-comment__meta"><button type="button" data-social-comment-reply>ตอบกลับ</button>
                <button type="button" data-social-comment-edit data-update-url="/social/comments/${id}" data-body="${body}">แก้ไข</button>
                <button type="button" data-social-comment-delete data-destroy-url="/social/comments/${id}" data-has-replies="false">ลบ</button></div>
            ${root ? '' : '<ul data-social-reply-list></ul><div data-social-reply-slot></div>'}
        </div>
    </li>`;
}

function page({cards = [card(1, {likes: 2, comments: 1}), card(2, {images: ['/media/a', '/media/b']})], nextCursor = '2', departmentDisabled = false} = {}) {
    return `<!doctype html><html><head><meta name="csrf-token" content="token-123"></head><body>
    <div class="social-page" data-social-page data-feed-url="/social/posts" data-max-images="3" data-max-image-kb="1024" data-post-max-length="50">
        <section><button type="button" data-social-compose="text">คุณคิดอะไรอยู่...</button>
            <button type="button" data-social-compose="image">รูปภาพ</button><button type="button" data-social-compose="emoji">ความรู้สึก</button></section>
        <div data-social-feed>${cards.join('')}</div>
        <div data-social-empty ${cards.length ? 'hidden' : ''}>ยังไม่มีโพสต์</div>
        <button type="button" data-social-load-more data-next-cursor="${nextCursor}" ${nextCursor ? '' : 'hidden'}><span data-social-spinner hidden></span><span data-social-load-more-label>โหลดโพสต์เพิ่มเติม</span></button>
        <p data-social-feed-end hidden>ครบแล้ว</p>
        <div class="modal" data-social-post-modal data-store-url="/social/posts">
            <form data-social-post-form>
                <h2 data-social-post-modal-title>สร้างโพสต์</h2>
                <div data-social-audience>
                    <button type="button" data-social-audience-toggle aria-expanded="false"><i class="bi bi-globe2" data-social-audience-icon></i><span data-social-audience-label>ทุกคนในองค์กร</span></button>
                    <fieldset data-social-audience-panel>
                        <label data-social-audience-choice><input type="radio" name="visibility" value="everyone" checked data-social-audience-option>ทุกคนในองค์กร</label>
                        <label data-social-audience-choice><input type="radio" name="visibility" value="department" data-social-audience-option ${departmentDisabled ? 'disabled' : ''}><strong data-social-audience-department-name>เฉพาะแผนกของฉัน (IT)</strong></label>
                    </fieldset>
                </div>
                <textarea name="body" maxlength="50" data-social-post-body></textarea>
                <p data-social-post-counter></p>
                <div data-social-post-media hidden><ul data-social-post-previews></ul></div>
                <p data-social-post-error hidden></p>
                <input type="file" multiple data-social-post-file>
                <button type="button" data-social-post-pick-image>รูป</button>
                <div data-social-emoji><button type="button" data-social-emoji-toggle aria-expanded="false">😀</button><div data-social-emoji-panel></div></div>
                <button type="submit" data-social-post-submit><span data-social-spinner hidden></span><span data-social-post-submit-label>โพสต์</span></button>
            </form>
        </div>
        <div class="modal" data-social-viewer>
            <span data-social-viewer-counter></span>
            <button type="button" data-social-viewer-prev>‹</button>
            <span data-social-viewer-loading hidden></span><img data-social-viewer-image alt=""><p data-social-viewer-error hidden></p>
            <button type="button" data-social-viewer-next>›</button>
        </div>
        <template data-social-reply-template><form data-social-reply-form><strong data-social-reply-to></strong><button type="button" data-social-reply-cancel>ยกเลิก</button><label data-social-reply-label></label><textarea name="body" data-social-comment-input></textarea><button type="submit">ส่ง</button></form></template>
        <template data-social-edit-template><form data-social-comment-edit-form><label data-social-edit-label></label><textarea name="body" data-social-comment-input></textarea><button type="button" data-social-edit-cancel>ยกเลิก</button><button type="submit">บันทึก</button></form></template>
    </div></body></html>`;
}

function setup(options = {}, {responses = [], swalResult = {isConfirmed: true}} = {}) {
    const dom = mountDom(page(options));
    const calls = [];
    const queue = [...responses];
    const fetchImpl = async (url, init) => {
        calls.push({url, init, body: init.body ? Object.fromEntries([...init.body.entries()].map(([key, value]) => [key, value])) : null, all: init.body ? [...init.body.entries()] : []});
        const next = typeof queue[0] === 'function' ? queue.shift()(url, init) : queue.shift() ?? {status: 200, payload: {ok: true}};
        const resolved = await next;

        return {ok: resolved.status >= 200 && resolved.status < 300, status: resolved.status, json: async () => resolved.payload};
    };
    const modals = new Map();
    const bootstrapApi = {Modal: {getOrCreateInstance: (element) => {
        if (! modals.has(element)) {
            modals.set(element, {
                shown: 0,
                show() {
                    this.shown += 1;
                    element.dispatchEvent(new dom.window.Event('shown.bs.modal'));
                },
                hide() {
                    const event = new dom.window.Event('hide.bs.modal', {cancelable: true});
                    element.dispatchEvent(event);
                    if (! event.defaultPrevented) element.dispatchEvent(new dom.window.Event('hidden.bs.modal'));
                },
            });
        }
        return modals.get(element);
    }}};
    const swalCalls = [];
    const swal = () => ({fire: async (config) => { swalCalls.push(config); return swalResult; }});
    const app = initSocialPage(dom.document, {bootstrapApi, swal, fetchImpl});
    const $ = (selector) => dom.document.querySelector(selector);
    const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

    return {dom, app, calls, swalCalls, $, settle, modal: (element) => bootstrapApi.Modal.getOrCreateInstance(element)};
}

/* ---------- ฟังก์ชันบริสุทธิ์ ---------- */

test('screenFiles keeps supported images within the limits and explains every rejection', () => {
    const file = (name, type, size = 100) => ({name, type, size});
    const {accepted, errors} = screenFiles([
        file('a.jpg', 'image/jpeg'),
        file('evil.svg', 'image/svg+xml'),
        file('huge.png', 'image/png', 3 * 1024 * 1024),
        file('b.webp', 'image/webp'),
        file('c.png', 'image/png'),
    ], {currentCount: 1, maxImages: 3, maxKilobytes: 2048});

    assert.deepEqual(accepted.map((item) => item.name), ['a.jpg', 'b.webp']);
    assert.deepEqual(errors, ['"evil.svg" ไม่ใช่รูป JPG, PNG หรือ WebP', '"huge.png" มีขนาดเกิน 2 MB', 'แนบรูปได้ไม่เกิน 3 รูปต่อโพสต์']);
});

test('stepIndex wraps around in both directions', () => {
    assert.equal(stepIndex(0, -1, 3), 2);
    assert.equal(stepIndex(2, 1, 3), 0);
    assert.equal(stepIndex(1, 1, 3), 2);
    assert.equal(stepIndex(0, 1, 0), 0);
});

test('Enter sends a comment, Shift+Enter and IME composition do not', () => {
    assert.equal(shouldSubmitOnKey({key: 'Enter'}), true);
    assert.equal(shouldSubmitOnKey({key: 'Enter', shiftKey: true}), false);
    assert.equal(shouldSubmitOnKey({key: 'Enter', isComposing: true}), false);
    assert.equal(shouldSubmitOnKey({key: 'Enter', keyCode: 229}), false);
    assert.equal(shouldSubmitOnKey({key: 'a'}), false);
});

test('server errors become Thai messages, validation errors first', () => {
    assert.equal(firstErrorMessage({errors: {images: ['รูปเกิน']}}), 'รูปเกิน');
    assert.equal(errorMessageFor(422, {message: 'x', errors: {body: ['กรุณาพิมพ์']}}), 'กรุณาพิมพ์');
    assert.equal(errorMessageFor(403), 'คุณไม่มีสิทธิ์ทำรายการนี้');
    assert.equal(errorMessageFor(404), 'ไม่พบรายการนี้ อาจถูกลบไปแล้ว');
    assert.equal(errorMessageFor(429, {message: 'ช้าลงหน่อย'}), 'ช้าลงหน่อย');
    assert.equal(errorMessageFor(500, {}), 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง');
});

test('request sends CSRF and method spoofing, and turns network failures into a message', async () => {
    const dom = mountDom('<!doctype html><html><head><meta name="csrf-token" content="abc"></head><body></body></html>');
    let seen = null;
    await request('/x', {method: 'DELETE', doc: dom.document, fetchImpl: async (url, init) => {
        seen = init;
        return {ok: true, status: 200, json: async () => ({ok: true})};
    }});
    assert.equal(seen.method, 'POST');
    assert.equal(seen.headers['X-CSRF-TOKEN'], 'abc');
    assert.equal(seen.headers.Accept, 'application/json');
    assert.equal(seen.body.get('_method'), 'DELETE');

    await assert.rejects(
        request('/x', {doc: dom.document, fetchImpl: async () => { throw new TypeError('Failed to fetch'); }}),
        {message: 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบอินเทอร์เน็ตแล้วลองใหม่'},
    );
    dom.cleanup();
});

test('insertAtCursor inserts at the caret and respects maxlength', () => {
    const dom = mountDom('<!doctype html><html><body><textarea maxlength="6"></textarea></body></html>');
    const field = dom.document.querySelector('textarea');
    field.value = 'abcd';
    field.setSelectionRange(2, 2);

    assert.equal(insertAtCursor(field, '🎉'), true);
    assert.equal(field.value, 'ab🎉cd');
    assert.equal(insertAtCursor(field, '🎉'), false, 'เกิน maxlength ไม่แทรก');
    dom.cleanup();
});

/* ---------- เส้นทางจริงจากการคลิกถึงผลลัพธ์บนหน้า ---------- */

test('init is idempotent so a second call never doubles the listeners', async () => {
    const env = setup({}, {responses: [{status: 200, payload: {ok: true, liked: true, likes_count: 3}}]});
    assert.equal(initSocialPage(env.dom.document), null);

    click(env.$('#social-post-1 [data-social-like]'));
    await env.settle();
    assert.equal(env.calls.length, 1);
    env.dom.cleanup();
});

test('liking updates the button and count, ignores clicks while busy, and uses PUT', async () => {
    let release;
    const env = setup({}, {responses: [() => new Promise((resolve) => { release = () => resolve({status: 200, payload: {ok: true, liked: true, likes_count: 3}}); })]});
    const button = env.$('#social-post-1 [data-social-like]');

    click(button);
    click(button);
    assert.equal(button.getAttribute('aria-pressed'), 'true', 'แสดงผลทันทีก่อนได้คำตอบ');
    assert.equal(env.$('#social-post-1 [data-social-like-count]').textContent, '3');
    release();
    await env.settle();

    assert.equal(env.calls.length, 1, 'กดซ้ำระหว่างรอไม่ส่งคำขอที่สอง');
    assert.equal(env.calls[0].url, '/social/posts/1/like');
    assert.equal(env.calls[0].body._method, 'PUT');
    assert.equal(env.$('#social-post-1 [data-social-like-icon]').classList.contains('bi-hand-thumbs-up-fill'), true);
    env.dom.cleanup();
});

test('a failed like rolls the button back and tells the user', async () => {
    const env = setup({}, {responses: [{status: 429, payload: {ok: false, message: 'ทำรายการถี่เกินไป'}}]});
    const card = env.$('#social-post-1');

    click(card.querySelector('[data-social-like]'));
    await env.settle();

    assert.equal(card.querySelector('[data-social-like]').getAttribute('aria-pressed'), 'false');
    assert.equal(card.querySelector('[data-social-like-count]').textContent, '2');
    assert.equal(env.swalCalls.at(-1).title, 'ทำรายการถี่เกินไป');
    env.dom.cleanup();
});

test('applyLikeState hides the summary at zero', () => {
    const env = setup();
    const card = env.$('#social-post-1');
    applyLikeState(card, false, 0);
    assert.equal(card.querySelector('[data-social-like-summary]').hidden, true);
    env.dom.cleanup();
});

test('the composer opens from the prompt, refuses an empty post, then posts and prepends the new card', async () => {
    const env = setup({}, {responses: [{status: 201, payload: {ok: true, message: 'โพสต์เรียบร้อยแล้ว', post_id: 9, html: card(9)}}]});
    const modalElement = env.$('[data-social-post-modal]');

    click(env.$('[data-social-compose="text"]'));
    assert.equal(env.modal(modalElement).shown, 1);
    assert.equal(env.dom.document.activeElement, env.$('[data-social-post-body]'));

    env.$('[data-social-post-form]').dispatchEvent(new env.dom.window.Event('submit', {cancelable: true, bubbles: true}));
    await env.settle();
    assert.equal(env.calls.length, 0);
    assert.equal(env.$('[data-social-post-error]').hidden, false);
    assert.match(env.$('[data-social-post-error]').textContent, /กรุณาพิมพ์ข้อความหรือเลือกรูปภาพ/);

    const body = env.$('[data-social-post-body]');
    body.value = 'สวัสดีทุกแผนก';
    body.dispatchEvent(new env.dom.window.Event('input', {bubbles: true}));
    assert.equal(env.$('[data-social-post-counter]').textContent, '13 / 50');

    const form = env.$('[data-social-post-form]');
    form.dispatchEvent(new env.dom.window.Event('submit', {cancelable: true, bubbles: true}));
    form.dispatchEvent(new env.dom.window.Event('submit', {cancelable: true, bubbles: true}));
    await env.settle();

    assert.equal(env.calls.length, 1, 'กดโพสต์ซ้ำระหว่างส่งไม่ส่งซ้ำ');
    assert.equal(env.calls[0].url, '/social/posts');
    assert.equal(env.calls[0].body.body, 'สวัสดีทุกแผนก');
    assert.equal(env.calls[0].body._method, undefined);
    assert.equal(env.$('[data-social-feed]').firstElementChild.id, 'social-post-9');
    assert.equal(env.$('[data-social-post-body]').value, '', 'ปิดแล้วฟอร์มถูกล้าง');
    assert.equal(env.swalCalls.at(-1).title, 'โพสต์เรียบร้อยแล้ว');
    env.dom.cleanup();
});

test('selected images preview, can be removed before posting, and are sent as images[]', async () => {
    const env = setup({}, {responses: [{status: 201, payload: {ok: true, html: card(9)}}]});
    const {File} = env.dom.window;
    click(env.$('[data-social-compose="text"]'));

    env.app.composer.addFiles([
        new File(['a'], 'a.jpg', {type: 'image/jpeg'}),
        new File(['b'], 'b.png', {type: 'image/png'}),
        new File(['c'], 'c.gif', {type: 'image/gif'}),
    ]);
    assert.equal(env.$('[data-social-post-media]').hidden, false);
    assert.equal(env.dom.document.querySelectorAll('[data-social-remove-image]').length, 2);
    assert.match(env.$('[data-social-post-error]').textContent, /c\.gif/);

    click(env.dom.document.querySelectorAll('[data-social-remove-image]')[0]);
    assert.equal(env.dom.document.querySelectorAll('[data-social-remove-image]').length, 1);

    env.$('[data-social-post-form]').dispatchEvent(new env.dom.window.Event('submit', {cancelable: true, bubbles: true}));
    await env.settle();
    const sent = env.calls[0].all.filter(([key]) => key === 'images[]').map(([, value]) => value.name);
    assert.deepEqual(sent, ['b.png']);
    env.dom.cleanup();
});

test('editing a post fills the modal, sends PATCH with removed images, and replaces the card', async () => {
    const env = setup({}, {responses: [{status: 200, payload: {ok: true, html: card(2).replace('โพสต์ 2</div>', 'แก้แล้ว</div>')}}]});

    click(env.$('#social-post-2 [data-social-post-edit]'));
    assert.equal(env.$('[data-social-post-modal-title]').textContent, 'แก้ไขโพสต์');
    assert.equal(env.$('[data-social-post-body]').value, 'โพสต์ 2');
    assert.equal(env.dom.document.querySelectorAll('[data-social-remove-image="existing"]').length, 2);

    click(env.dom.document.querySelector('[data-social-remove-image="existing"]'));
    env.$('[data-social-post-form]').dispatchEvent(new env.dom.window.Event('submit', {cancelable: true, bubbles: true}));
    await env.settle();

    assert.equal(env.calls[0].url, '/social/posts/2');
    assert.equal(env.calls[0].body._method, 'PATCH');
    assert.deepEqual(env.calls[0].all.filter(([key]) => key === 'remove_media[]').map(([, value]) => value), ['100']);
    assert.equal(env.$('#social-post-2 .social-post__body').textContent, 'แก้แล้ว');
    assert.equal(env.dom.document.querySelectorAll('#social-post-2').length, 1);
    env.dom.cleanup();
});

test('deleting a post asks first, removes the card, and shows the empty state when the feed is empty', async () => {
    const env = setup({cards: [card(1)], nextCursor: ''}, {responses: [{status: 200, payload: {ok: true, message: 'ลบโพสต์แล้ว'}}]});

    click(env.$('[data-social-post-delete]'));
    await env.settle();
    await env.settle();

    assert.equal(env.swalCalls[0].icon, 'warning');
    assert.equal(env.calls[0].body._method, 'DELETE');
    assert.equal(env.$('#social-post-1'), null);
    assert.equal(env.$('[data-social-empty]').hidden, false);
    env.dom.cleanup();
});

test('cancelling the delete confirmation sends nothing', async () => {
    const env = setup({}, {swalResult: {isConfirmed: false}});
    click(env.$('#social-post-1 [data-social-post-delete]'));
    await env.settle();
    assert.equal(env.calls.length, 0);
    assert.ok(env.$('#social-post-1'));
    env.dom.cleanup();
});

test('load more appends older posts by cursor and hides itself at the end', async () => {
    const env = setup({}, {responses: [{status: 200, payload: {ok: true, html: card(1) + card(0), next_cursor: null}}]});

    click(env.$('[data-social-load-more]'));
    await env.settle();

    assert.equal(new URL(env.calls[0].url).searchParams.get('before'), '2');
    assert.equal(env.calls[0].init.method, 'GET');
    assert.deepEqual([...env.dom.document.querySelectorAll('[data-social-post]')].map((node) => node.id), ['social-post-1', 'social-post-2', 'social-post-0'], 'โพสต์ที่มีอยู่แล้วไม่ถูกเพิ่มซ้ำ');
    assert.equal(env.$('[data-social-load-more]').hidden, true);
    assert.equal(env.$('[data-social-feed-end]').hidden, false);
    env.dom.cleanup();
});

test('opening comments loads them once, then a new comment is appended and counted', async () => {
    const env = setup({}, {responses: [
        {status: 200, payload: {ok: true, html: comment(5), next_cursor: null, comments_count: 1}},
        {status: 201, payload: {ok: true, html: comment(6, {body: 'ยินดีครับ'}), parent_id: null, comments_count: 2}},
    ]});
    const cardElement = env.$('#social-post-1');

    click(cardElement.querySelector('.social-post__action[data-social-comments-toggle]'));
    await env.settle();
    assert.equal(cardElement.querySelector('[data-social-comments]').hidden, false);
    assert.equal(env.calls[0].url, 'http://localhost/social/posts/1/comments');
    assert.ok(cardElement.querySelector('#social-comment-5'));
    assert.equal(env.dom.document.activeElement, cardElement.querySelector('[data-social-comment-form] textarea'));

    const field = cardElement.querySelector('[data-social-comment-form] textarea');
    field.value = 'ยินดีครับ';
    field.dispatchEvent(new env.dom.window.KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true}));
    await env.settle();

    assert.equal(env.calls[1].body.body, 'ยินดีครับ');
    assert.ok(cardElement.querySelector('[data-social-comment-list] #social-comment-6'));
    assert.equal(cardElement.querySelector('[data-social-comment-count]').textContent, '2');
    assert.equal(field.value, '');
    env.dom.cleanup();
});

test('a post without comments opens straight to the empty state without a request', () => {
    const env = setup();
    const cardElement = env.$('#social-post-2');
    click(cardElement.querySelector('.social-post__action[data-social-comments-toggle]'));
    assert.equal(env.calls.length, 0);
    assert.equal(cardElement.querySelector('[data-social-comments-empty]').hidden, false);
    env.dom.cleanup();
});

test('replying opens one form under the root comment and appends the reply there', async () => {
    const env = setup({}, {responses: [
        {status: 200, payload: {ok: true, html: comment(5), next_cursor: null, comments_count: 1}},
        {status: 201, payload: {ok: true, html: comment(7, {root: 5, body: 'ยินดีครับ'}), parent_id: 5, comments_count: 2}},
    ]});
    const cardElement = env.$('#social-post-1');
    click(cardElement.querySelector('.social-post__action[data-social-comments-toggle]'));
    await env.settle();

    click(cardElement.querySelector('#social-comment-5 [data-social-comment-reply]'));
    click(cardElement.querySelector('#social-comment-5 [data-social-comment-reply]'));
    assert.equal(cardElement.querySelectorAll('[data-social-reply-form]').length, 1);
    assert.equal(cardElement.querySelector('[data-social-reply-to]').textContent, 'พนักงาน HR');

    const form = cardElement.querySelector('[data-social-reply-form]');
    assert.equal(form.querySelector('label').htmlFor, form.querySelector('textarea').id);
    form.querySelector('textarea').value = 'ยินดีครับ';
    form.dispatchEvent(new env.dom.window.Event('submit', {cancelable: true, bubbles: true}));
    await env.settle();

    assert.equal(env.calls[1].body.parent_id, '5');
    assert.ok(cardElement.querySelector('#social-comment-5 [data-social-reply-list] #social-comment-7'));
    assert.equal(cardElement.querySelectorAll('[data-social-reply-form]').length, 0);
    env.dom.cleanup();
});

test('a comment response arriving late never steals focus from a reply the user opened meanwhile', async () => {
    let release;
    const env = setup({}, {responses: [
        {status: 200, payload: {ok: true, html: comment(5), next_cursor: null, comments_count: 1}},
        () => new Promise((resolve) => { release = () => resolve({status: 201, payload: {ok: true, html: comment(6), parent_id: null, comments_count: 2}}); }),
    ]});
    const cardElement = env.$('#social-post-1');
    click(cardElement.querySelector('.social-post__action[data-social-comments-toggle]'));
    await env.settle();

    const field = cardElement.querySelector('[data-social-comment-form] textarea');
    field.value = 'ส่งแล้วรอคำตอบ';
    field.form.dispatchEvent(new env.dom.window.Event('submit', {cancelable: true, bubbles: true}));
    click(cardElement.querySelector('#social-comment-5 [data-social-comment-reply]'));
    const reply = cardElement.querySelector('[data-social-reply-form] textarea');
    assert.equal(env.dom.document.activeElement, reply);

    release();
    await env.settle();
    assert.ok(cardElement.querySelector('#social-comment-6'));
    assert.equal(env.dom.document.activeElement, reply, 'โฟกัสยังอยู่ที่ช่องตอบกลับ');
    env.dom.cleanup();
});

test('editing a comment inline sends PATCH and Escape cancels without saving', async () => {
    const env = setup({}, {responses: [
        {status: 200, payload: {ok: true, html: comment(5), next_cursor: null, comments_count: 1}},
        {status: 200, payload: {ok: true, html: comment(5, {body: 'แก้แล้ว'})}},
    ]});
    const cardElement = env.$('#social-post-1');
    click(cardElement.querySelector('.social-post__action[data-social-comments-toggle]'));
    await env.settle();

    click(cardElement.querySelector('#social-comment-5 [data-social-comment-edit]'));
    const field = cardElement.querySelector('[data-social-comment-edit-form] textarea');
    assert.equal(field.value, 'ขอบคุณค่ะ');
    field.dispatchEvent(new env.dom.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
    assert.equal(cardElement.querySelector('[data-social-comment-edit-form]'), null);
    assert.equal(cardElement.querySelector('#social-comment-5 .social-comment__bubble').hidden, false);

    click(cardElement.querySelector('#social-comment-5 [data-social-comment-edit]'));
    const form = cardElement.querySelector('[data-social-comment-edit-form]');
    form.querySelector('textarea').value = 'แก้แล้ว';
    form.dispatchEvent(new env.dom.window.Event('submit', {cancelable: true, bubbles: true}));
    await env.settle();

    assert.equal(new URL(env.calls[1].url).pathname, '/social/comments/5');
    assert.equal(env.calls[1].body._method, 'PATCH');
    assert.equal(cardElement.querySelector('#social-comment-5 [data-social-comment-text]').textContent, 'แก้แล้ว');
    env.dom.cleanup();
});

test('clicking an image opens the viewer at that image and arrows move between images', () => {
    const env = setup();
    const viewer = env.$('[data-social-viewer]');
    const second = env.$('#social-post-2 [data-social-image-open="1"]');

    click(second);
    assert.equal(env.modal(viewer).shown, 1);
    assert.equal(env.$('[data-social-viewer-image]').getAttribute('src'), '/media/b');
    assert.equal(env.$('[data-social-viewer-counter]').textContent, '2 / 2');

    viewer.dispatchEvent(new env.dom.window.KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true}));
    assert.equal(env.$('[data-social-viewer-image]').getAttribute('src'), '/media/a');

    viewer.dispatchEvent(new env.dom.window.Event('hidden.bs.modal'));
    assert.equal(env.dom.document.activeElement, second, 'ปิดแล้วโฟกัสกลับไปที่รูปที่กดเปิด');
    env.dom.cleanup();
});

test('the emoji popover inserts at the caret and Escape closes only the popover', () => {
    const env = setup();
    const modalElement = env.$('[data-social-post-modal]');
    let modalSawEscape = false;
    modalElement.addEventListener('keydown', (event) => { if (event.key === 'Escape') modalSawEscape = true; });

    click(env.$('[data-social-compose="emoji"]'));
    const panel = env.$('[data-social-emoji-panel]');
    assert.equal(panel.dataset.open, 'true', 'ปุ่มความรู้สึกเปิดตัวเลือกอีโมจิทันที');
    assert.ok(panel.querySelectorAll('[data-social-emoji-option]').length > 20);

    panel.querySelector('[data-social-emoji-option]').dispatchEvent(new env.dom.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
    assert.equal(panel.dataset.open, 'false');
    assert.equal(modalSawEscape, false, 'Escape ไม่หลุดไปปิด modal');
    assert.equal(env.dom.document.activeElement, env.$('[data-social-emoji-toggle]'));

    click(env.$('[data-social-emoji-toggle]'));
    click(panel.querySelector('[data-social-emoji-option]'));
    assert.equal(env.$('[data-social-post-body]').value, '😀');
    assert.equal(panel.dataset.open, 'false');
    assert.equal(panel.querySelectorAll('[data-social-emoji-option]').length, 72, 'เปิดซ้ำไม่สร้างปุ่มซ้ำ');
    env.dom.cleanup();
});

test('choosing "my department" with the pointer updates the summary, closes the popover, and is sent with the post', async () => {
    const env = setup({}, {responses: [{status: 201, payload: {ok: true, html: card(9)}}]});
    click(env.$('[data-social-compose="text"]'));
    const toggle = env.$('[data-social-audience-toggle]');
    const panel = env.$('[data-social-audience-panel]');

    click(toggle);
    assert.equal(panel.dataset.open, 'true');
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(env.dom.document.activeElement.value, 'everyone', 'โฟกัสไปที่ตัวเลือกที่เลือกอยู่');

    const department = env.$('[data-social-audience-option][value="department"]');
    panel.dispatchEvent(new env.dom.window.Event('pointerdown', {bubbles: true}));
    click(department);
    // ปิดใน task ถัดไป — หลังจาก <label> ย้ายโฟกัสเสร็จ (ไม่งั้นโฟกัสค้างอยู่ใน radio ของแผงที่ปิดแล้ว)
    department.focus();
    await env.settle();

    assert.equal(department.checked, true);
    assert.equal(panel.dataset.open, 'false');
    assert.equal(env.dom.document.activeElement, toggle);
    assert.equal(env.$('[data-social-audience-label]').textContent, 'เฉพาะแผนกของฉัน (IT)');
    assert.equal(env.$('[data-social-audience-icon]').classList.contains('bi-people'), true);
    assert.equal(department.closest('[data-social-audience-choice]').classList.contains('is-selected'), true);

    const body = env.$('[data-social-post-body]');
    body.value = 'ถึงทีม';
    env.$('[data-social-post-form]').dispatchEvent(new env.dom.window.Event('submit', {cancelable: true, bubbles: true}));
    await env.settle();
    assert.equal(env.calls[0].body.visibility, 'department');
    assert.equal(env.calls[0].body.target_department_id, undefined, 'ไม่ส่งแผนกเป้าหมายจากหน้าเว็บ');

    click(env.$('[data-social-compose="text"]'));
    assert.equal(env.$('[data-social-audience-label]').textContent, 'ทุกคนในองค์กร', 'โพสต์ใหม่กลับไปเป็นค่าตั้งต้น');
    env.dom.cleanup();
});

test('a mouse pick on a label returns focus to the audience button (event order measured in Chrome)', async () => {
    const env = setup();
    click(env.$('[data-social-compose="text"]'));
    const toggle = env.$('[data-social-audience-toggle]');
    const panel = env.$('[data-social-audience-panel]');
    const modalElement = env.$('[data-social-post-modal]');
    modalElement.tabIndex = -1;
    const {Event, FocusEvent} = env.dom.window;

    click(toggle);
    const everyone = env.$('[data-social-audience-option][value="everyone"]');
    const department = env.$('[data-social-audience-option][value="department"]');
    const label = department.closest('label');

    // Chrome: pointerdown บน label → โฟกัสย้ายไปกล่อง .modal ก่อน → click → label ให้โฟกัส radio → change
    label.dispatchEvent(new Event('pointerdown', {bubbles: true}));
    everyone.dispatchEvent(new FocusEvent('focusout', {bubbles: true, relatedTarget: modalElement}));
    modalElement.focus();
    department.checked = true;
    department.focus();
    department.dispatchEvent(new Event('change', {bubbles: true}));
    env.dom.document.dispatchEvent(new Event('pointerup', {bubbles: true}));
    await env.settle();

    assert.equal(panel.dataset.open, 'false');
    assert.equal(env.dom.document.activeElement, toggle, 'โฟกัสกลับมาที่ปุ่ม ไม่ตกไปที่ body');
    assert.equal(env.$('[data-social-audience-label]').textContent, 'เฉพาะแผนกของฉัน (IT)');
    env.dom.cleanup();
});

test('keyboard selection keeps the audience popover open until Enter, and Escape closes only the popover', () => {
    const env = setup();
    const modalElement = env.$('[data-social-post-modal]');
    let modalSawEscape = false;
    modalElement.addEventListener('keydown', (event) => { if (event.key === 'Escape') modalSawEscape = true; });
    click(env.$('[data-social-compose="text"]'));
    const panel = env.$('[data-social-audience-panel]');

    click(env.$('[data-social-audience-toggle]'));
    const department = env.$('[data-social-audience-option][value="department"]');
    department.checked = true;
    department.dispatchEvent(new env.dom.window.Event('change', {bubbles: true}));
    assert.equal(panel.dataset.open, 'true', 'เลื่อนด้วยลูกศรแล้วยังเปิดอยู่');

    department.dispatchEvent(new env.dom.window.KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true}));
    assert.equal(panel.dataset.open, 'false');

    click(env.$('[data-social-audience-toggle]'));
    department.dispatchEvent(new env.dom.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
    assert.equal(panel.dataset.open, 'false');
    assert.equal(modalSawEscape, false);
    assert.equal(env.$('[data-social-audience-label]').textContent, 'เฉพาะแผนกของฉัน (IT)');
    env.dom.cleanup();
});

test('editing a department post preselects it and names the department frozen on the post', async () => {
    const env = setup({}, {responses: [{status: 200, payload: {ok: true, html: card(2)}}]});

    click(env.$('#social-post-2 [data-social-post-edit]'));
    assert.equal(env.$('[data-social-audience-option][value="department"]').checked, true);
    assert.equal(env.$('[data-social-audience-label]').textContent, 'เฉพาะแผนกของฉัน (บัญชีเดิม)');

    env.$('[data-social-post-form]').dispatchEvent(new env.dom.window.Event('submit', {cancelable: true, bubbles: true}));
    await env.settle();
    assert.equal(env.calls[0].body.visibility, 'department');

    click(env.$('#social-post-1 [data-social-post-edit]'));
    assert.equal(env.$('[data-social-audience-option][value="everyone"]').checked, true);
    assert.equal(env.$('[data-social-audience-department-name]').textContent, 'เฉพาะแผนกของฉัน (IT)', 'ชื่อแผนกกลับเป็นของผู้ใช้');
    env.dom.cleanup();
});

test('an account without a department cannot pick the department option', () => {
    const env = setup({departmentDisabled: true});
    click(env.$('[data-social-compose="text"]'));
    const department = env.$('[data-social-audience-option][value="department"]');

    // jsdom ยังสลับ radio ที่ disabled เมื่อ dispatch click สังเคราะห์ ซึ่งเบราว์เซอร์จริงไม่ทำ
    // การคลิกจริงตรวจในเบราว์เซอร์ ส่วน server ปฏิเสธซ้ำเสมอ (SocialVisibilityTest)
    assert.equal(department.disabled, true);
    assert.equal(department.checked, false);
    assert.equal(env.$('[data-social-audience-label]').textContent, 'ทุกคนในองค์กร');
    env.dom.cleanup();
});

/* ---------- สัญญาระหว่าง Blade กับ JavaScript ---------- */

test('social views contain no inline CSS, inline scripts, or inline event handlers', () => {
    for (const source of bladeSources) {
        assert.doesNotMatch(source, /<style/i);
        assert.doesNotMatch(source, /<script/i);
        assert.doesNotMatch(source, /\sstyle="/i);
        assert.doesNotMatch(source, /\son[a-z]+="/i);
    }
});

test('every data-social hook the scripts query exists in the Blade views', () => {
    const blade = bladeSources.join('\n');
    const hooks = new Set();
    for (const source of jsSources) {
        for (const match of source.matchAll(/\[(data-social-[a-z-]+)/g)) hooks.add(match[1]);
    }
    // ปุ่มอีโมจิและปุ่มลบรูปตัวอย่างถูกสร้างโดย JS เอง
    const createdByScript = new Set(['data-social-emoji-option', 'data-social-remove-image', 'data-social-comments-retry', 'data-social-ready']);

    for (const hook of hooks) {
        if (createdByScript.has(hook)) continue;
        assert.ok(blade.includes(hook), `${hook} ไม่มีใน Blade`);
    }
});

test('the scripts never use native dialogs or hardcode roles', () => {
    for (const source of jsSources) {
        assert.doesNotMatch(source, /\b(window\.)?(alert|confirm|prompt)\(/);
        assert.doesNotMatch(source, /['"](admin|viewer)['"]/);
    }
});
