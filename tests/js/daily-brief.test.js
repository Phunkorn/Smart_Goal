import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {createModalStack} from '../../resources/js/components/modal-stack.js';
import {initDailyBrief, isModifiedClick, resultForStatus} from '../../resources/js/components/daily-brief.js';
import {click, mountDom, pressKey} from './helpers/dom.js';

// ตัดคอมเมนต์ Blade ออกก่อน เพราะคอมเมนต์อธิบายกติกา "ห้ามใช้ {!! !!}" เอง
const withoutBladeComments = (source) => source.replace(/\{\{--[\s\S]*?--\}\}/g, '');
const modalSource = withoutBladeComments(readFileSync(new URL('../../resources/views/daily-brief/modal.blade.php', import.meta.url), 'utf8'));
const cardSource = withoutBladeComments(readFileSync(new URL('../../resources/views/daily-brief/partials/announcement-card.blade.php', import.meta.url), 'utf8'));
const scriptSource = readFileSync(new URL('../../resources/js/components/daily-brief.js', import.meta.url), 'utf8');
const layoutSource = readFileSync(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');

/** markup ที่ตรงกับ resources/views/daily-brief/modal.blade.php (เฉพาะ hook ที่ JS ใช้) */
const markup = `<!doctype html><html><head><meta name="csrf-token" content="csrf-123"></head><body>
    <main><button id="outside">outside</button></main>
    <div class="daily-brief" data-daily-brief hidden role="dialog" aria-modal="true"
        data-acknowledge-url="/daily-brief/acknowledge" data-brief-date="2026-09-25">
        <div class="daily-brief__panel">
            <button type="button" data-daily-brief-dismiss>x</button>
            <a href="/my-tasks?open_task=7" data-daily-brief-link id="task-link">task</a>
            <button type="button" data-daily-brief-expand="project" id="expand">ดูงานวันนี้ทั้งหมด 12 งาน</button>
            <footer><button type="button" data-daily-brief-acknowledge>รับทราบ</button></footer>
        </div>
    </div>
    <div class="daily-brief-list" data-daily-brief-list="project" hidden role="dialog" aria-modal="true">
        <div class="daily-brief-list__panel">
            <button type="button" data-daily-brief-list-close id="list-close">x</button>
            <a href="/my-tasks?open_task=12" data-daily-brief-link id="list-task">task 12</a>
        </div>
    </div>
</body></html>`;

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

function setup(responder = () => ({status: 200})) {
    const dom = mountDom(markup);
    const calls = [];
    const navigations = [];
    let reloads = 0;
    const fetchImpl = async (url, init) => {
        calls.push({url, init});
        const response = responder(calls.length);
        if (response instanceof Error) throw response;

        return response;
    };
    const stack = createModalStack(dom.document);
    const api = initDailyBrief(dom.document, {
        stack,
        fetchImpl,
        navigate: (url) => navigations.push(url),
        reload: () => { reloads += 1; },
    });
    const modal = dom.document.querySelector('[data-daily-brief]');

    return {dom, api, modal, calls, navigations, stack, reloads: () => reloads};
}

test('opens itself through the shared modal stack on page load', () => {
    const {dom, modal, stack} = setup();

    assert.equal(modal.hidden, false);
    assert.equal(dom.document.body.classList.contains('modal-open'), true);
    assert.equal(modal.dataset.modalBackdrop, 'on');
    assert.equal(stack.isTop(modal), true);
    dom.cleanup();
});

test('acknowledge posts the brief date with the CSRF token and closes the modal', async () => {
    const {dom, modal, calls} = setup();

    click(modal.querySelector('[data-daily-brief-acknowledge]'));
    await tick();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/daily-brief/acknowledge');
    assert.equal(calls[0].init.method, 'POST');
    assert.equal(calls[0].init.headers['X-CSRF-TOKEN'], 'csrf-123');
    assert.deepEqual(JSON.parse(calls[0].init.body), {brief_date: '2026-09-25'});
    assert.equal(modal.hidden, true);
    assert.equal(dom.document.body.classList.contains('modal-open'), false);
    dom.cleanup();
});

test('a stale brief from before midnight reloads instead of closing', async () => {
    const {dom, modal, reloads} = setup(() => ({status: 409}));

    click(modal.querySelector('[data-daily-brief-acknowledge]'));
    await tick();

    assert.equal(reloads(), 1);
    assert.equal(modal.hidden, false);
    dom.cleanup();
});

test('a failed request keeps the modal open and explains inside it', async () => {
    const {dom, modal} = setup(() => new Error('offline'));
    const button = modal.querySelector('[data-daily-brief-acknowledge]');

    click(button);
    await tick();

    assert.equal(modal.hidden, false);
    assert.match(modal.querySelector('[data-daily-brief-error]').textContent, /ไม่สำเร็จ/);
    assert.equal(modal.querySelector('[data-daily-brief-error]').getAttribute('role'), 'alert');
    assert.equal(button.disabled, false);
    dom.cleanup();
});

test('the close button dismisses without recording anything', () => {
    const {dom, modal, calls} = setup();

    click(modal.querySelector('[data-daily-brief-dismiss]'));

    assert.equal(modal.hidden, true);
    assert.equal(calls.length, 0);
    assert.equal(dom.document.body.classList.contains('modal-open'), false);
    dom.cleanup();
});

test('Escape dismisses without recording anything', () => {
    const {dom, modal, calls} = setup();

    pressKey(dom.document, 'Escape');

    assert.equal(modal.hidden, true);
    assert.equal(calls.length, 0);
    dom.cleanup();
});

test('opening a task from the brief acknowledges first and then navigates', async () => {
    const {dom, modal, calls, navigations} = setup();

    click(modal.querySelector('#task-link'));
    await tick();

    assert.equal(calls.length, 1);
    assert.deepEqual(navigations, ['http://localhost/my-tasks?open_task=7']);
    dom.cleanup();
});

test('navigation still happens when acknowledging fails', async () => {
    const {dom, modal, navigations} = setup(() => new Error('offline'));

    click(modal.querySelector('#task-link'));
    await tick();

    assert.equal(navigations.length, 1);
    dom.cleanup();
});

test('initializing twice does not register duplicate listeners', async () => {
    const {dom, modal, calls} = setup();

    assert.equal(initDailyBrief(dom.document), null);
    click(modal.querySelector('[data-daily-brief-acknowledge]'));
    await tick();

    assert.equal(calls.length, 1);
    dom.cleanup();
});

test('see-all opens the full list on top of the brief without leaving the page', () => {
    const {dom, modal, calls, navigations, stack} = setup();
    const list = dom.document.querySelector('[data-daily-brief-list="project"]');

    click(dom.document.querySelector('#expand'));

    assert.equal(list.hidden, false);
    assert.equal(stack.isTop(list), true);
    // สรุปยังเปิดอยู่ข้างล่าง แต่ถูกพักไว้ระหว่างที่กล่องรายการอยู่บนสุด
    assert.equal(modal.hidden, false);
    assert.equal(modal.hasAttribute('inert'), true);
    assert.equal(calls.length, 0);
    assert.equal(navigations.length, 0);
    dom.cleanup();
});

test('Escape and the close button return to the brief instead of closing it', () => {
    const {dom, modal, calls} = setup();
    const list = dom.document.querySelector('[data-daily-brief-list="project"]');

    click(dom.document.querySelector('#expand'));
    pressKey(dom.document, 'Escape');
    assert.equal(list.hidden, true);
    assert.equal(modal.hidden, false);
    assert.equal(modal.hasAttribute('inert'), false);

    click(dom.document.querySelector('#expand'));
    click(dom.document.querySelector('#list-close'));
    assert.equal(list.hidden, true);
    assert.equal(modal.hidden, false);
    assert.equal(dom.document.body.classList.contains('modal-open'), true);
    assert.equal(calls.length, 0);
    dom.cleanup();
});

test('a task in the full list acknowledges first and then navigates', async () => {
    const {dom, calls, navigations} = setup();

    click(dom.document.querySelector('#expand'));
    click(dom.document.querySelector('#list-task'));
    await tick();

    assert.equal(calls.length, 1);
    assert.deepEqual(navigations, ['http://localhost/my-tasks?open_task=12']);
    dom.cleanup();
});

test('see-all is a button that opens a dialog, not a link that leaves the brief', () => {
    assert.match(modalSource, /<button type="button" class="daily-brief__more" data-daily-brief-expand="project"/);
    assert.match(modalSource, /<button type="button" class="daily-brief__more" data-daily-brief-expand="routine"/);
    assert.doesNotMatch(modalSource, /<a class="daily-brief__more"/);
});

test('status and click helpers', () => {
    assert.equal(resultForStatus(200), 'ok');
    assert.equal(resultForStatus(409), 'stale');
    assert.equal(resultForStatus(419), 'error');
    assert.equal(isModifiedClick({button: 0}), false);
    assert.equal(isModifiedClick({button: 0, ctrlKey: true}), true);
    assert.equal(isModifiedClick({button: 1}), true);
});

test('announcement body is only rendered escaped and the brief never uses native dialogs', () => {
    assert.match(cardSource, /\{!! nl2br\(e\(\$announcement\['body'\]\)\) !!\}/);
    assert.equal((cardSource.match(/\{!!/g) || []).length, 1);
    assert.doesNotMatch(modalSource, /\{!!/);
    assert.doesNotMatch(scriptSource, /\b(alert|confirm|prompt)\(/);
});

test('task rows carry no status circle icon (removed at the owner request)', () => {
    const rowSource = readFileSync(new URL('../../resources/views/daily-brief/partials/task-row.blade.php', import.meta.url), 'utf8');
    const cssSource = readFileSync(new URL('../../resources/css/components/daily-brief.css', import.meta.url), 'utf8');

    assert.doesNotMatch(rowSource, /daily-brief__mark/);
    assert.doesNotMatch(cssSource, /daily-brief__mark/);
});

test('entrance animation replays on every open and respects reduced motion', () => {
    const cssSource = readFileSync(new URL('../../resources/css/components/daily-brief.css', import.meta.url), 'utf8');

    assert.match(cssSource, /\.daily-brief__panel\s*\{[^}]*animation:\s*daily-brief-enter/);
    assert.match(cssSource, /\.daily-brief__card\s*\{[^}]*animation:\s*daily-brief-rise/);
    // keyframes มีแค่ from จึงต้อง fill แบบ backwards เพื่อไม่ให้แอนิเมชันที่จบแล้วค้าง transform ทับ hover
    assert.doesNotMatch(cssSource, /animation:\s*daily-brief-[^;]*\bboth;/);
    assert.match(cssSource, /@media \(prefers-reduced-motion: reduce\)\s*\{[\s\S]*animation:\s*none !important/);
});

test('the autofocused acknowledge button shows no focus outline ring', () => {
    const cssSource = readFileSync(new URL('../../resources/css/components/daily-brief.css', import.meta.url), 'utf8');

    assert.match(cssSource, /\.daily-brief__acknowledge:focus,\s*\.daily-brief__acknowledge:focus-visible\s*\{\s*outline:\s*none;/);
    // คีย์บอร์ดยังต้องเห็นว่าปุ่มถูกโฟกัส ผ่านเงาแบบเดียวกับ hover
    assert.match(cssSource, /\.daily-brief__acknowledge:focus-visible:not\(:disabled\)\s*\{\s*box-shadow:/);
});

test('layout loads the brief assets only when the brief is pending', () => {
    assert.match(layoutSource, /@if\(! empty\(\$dailyBrief\)\)\s*@vite\('resources\/css\/components\/daily-brief\.css'\)/);
    assert.match(layoutSource, /@include\('daily-brief\.modal', \['brief' => \$dailyBrief\]\)/);
    assert.match(layoutSource, /@if\(! empty\(\$dailyBrief\)\)\s*@vite\('resources\/js\/components\/daily-brief\.js'\)/);
});
