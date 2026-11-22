import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
import {highlightParts, initGlobalSearch} from '../../resources/js/components/global-search.js';

/*
 * ช่องค้นหาบน Topbar (layouts/components/global-search.blade.php + resources/js/components/global-search.js)
 * ทดสอบเส้นทางจริง: ปุ่ม/คีย์ลัด → พิมพ์ → หน่วงเวลา → fetch → วาดผล → เลือกด้วยคีย์บอร์ด → เปิดลิงก์
 */
const bootstrapSource = readFileSync(new URL('../../node_modules/bootstrap/dist/js/bootstrap.bundle.js', import.meta.url), 'utf8');
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const DEBOUNCE = 15;

const payload = (overrides = {}) => ({
    query: 'smart',
    tasks: [
        {id: 7, type: 'task', title: 'ตรวจสอบ Smart Goals Bug', project: 'Smart Goals', status: {key: 'review', label: 'รอตรวจสอบ', tone: 'purple'}, url: '/my-tasks?open_task=7'},
        {id: 9, type: 'task', title: 'ออกแบบหน้า Dashboard', project: null, status: null, url: '/my-tasks?open_task=9'},
    ],
    projects: [{id: 3, type: 'project', title: 'Smart Goals', owner: 'Phunkorn', url: '/my-tasks?view=board&search=Smart+Goals'}],
    more_tasks: false,
    more_projects: false,
    all_results_url: '/my-tasks?view=board&search=smart',
    ...overrides,
});

function setup(t, {fetchImpl, withDropdown = false} = {}) {
    const dom = new JSDOM(`<!doctype html><html><body>
        <header class="topbar">
            ${withDropdown ? `<div class="dropdown"><button type="button" id="bell" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">bell</button>
                <div class="dropdown-menu topbar-slide-menu"><a class="dropdown-item" href="/n">n</a></div></div>` : ''}
            <div class="global-search" data-global-search data-search-url="/search" data-search-min-length="2">
                <button type="button" class="global-search__trigger" data-global-search-trigger aria-haspopup="dialog" aria-expanded="false" aria-controls="globalSearchPanel">
                </button>
                <div class="global-search__panel" id="globalSearchPanel" role="dialog" aria-modal="false" data-global-search-panel>
                    <input type="search" data-global-search-input role="combobox" aria-expanded="false" aria-controls="globalSearchResults">
                    <span data-global-search-loading></span>
                    <button type="button" data-global-search-clear hidden>x</button>
                    <div id="globalSearchResults" role="listbox" data-global-search-results></div>
                    <p data-global-search-message></p>
                    <p role="status" data-global-search-status></p>
                </div>
            </div>
        </header>
        <main id="outside" tabindex="0">content</main>
    </body></html>`, {url: 'https://smartgoal.test/my-tasks', runScripts: 'outside-only', pretendToBeVisual: true});
    t.after(() => dom.window.close());
    dom.window.HTMLElement.prototype.getClientRects = () => [{width: 100, height: 30}];
    dom.window.eval(bootstrapSource);

    const calls = [];
    const navigated = [];
    const fetcher = fetchImpl ?? (async (url, options) => {
        calls.push({url, options});
        return {ok: true, json: async () => payload()};
    });
    const doc = dom.window.document;
    const api = initGlobalSearch(doc, {
        win: dom.window,
        fetchImpl: async (url, options) => {
            if (fetchImpl) calls.push({url, options});
            return fetcher(url, options);
        },
        navigate: (url) => navigated.push(url),
        debounceMs: DEBOUNCE,
    });
    const $ = (selector) => doc.querySelector(selector);
    const root = $('[data-global-search]');
    const input = $('[data-global-search-input]');
    const type = (value) => {
        input.value = value;
        input.dispatchEvent(new dom.window.Event('input', {bubbles: true}));
    };
    const key = (target, keyName, extra = {}) => target.dispatchEvent(new dom.window.KeyboardEvent('keydown', {key: keyName, bubbles: true, cancelable: true, ...extra}));

    return {dom, doc, win: dom.window, api, $, root, input, type, key, calls, navigated};
}

test('highlightParts splits Thai and English text around every case-insensitive match', () => {
    assert.deepEqual(highlightParts('Smart Goals smart', 'SMART'), [
        {text: 'Smart', match: true},
        {text: ' Goals ', match: false},
        {text: 'smart', match: true},
    ]);
    assert.deepEqual(highlightParts('ตรวจสอบงาน', 'สอบ'), [
        {text: 'ตรวจ', match: false},
        {text: 'สอบ', match: true},
        {text: 'งาน', match: false},
    ]);
    assert.deepEqual(highlightParts('งาน', ''), [{text: 'งาน', match: false}]);
    assert.deepEqual(highlightParts('', 'x'), []);
});

test('the trigger opens a non-modal popover, Escape closes it and returns focus to the trigger', (t) => {
    const {$, root, input, doc, key} = setup(t);
    const trigger = $('[data-global-search-trigger]');

    trigger.click();
    assert.ok(root.classList.contains('is-open'));
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(doc.activeElement, input);
    assert.ok(! doc.body.classList.contains('modal-open'), 'ไม่ล็อกการเลื่อนหน้า');
    assert.equal($('[data-global-search-message]').textContent, 'ค้นจากชื่องาน เนื้อหางาน หรือชื่อโปรเจกต์');

    key(input, 'Escape');
    assert.ok(! root.classList.contains('is-open'));
    assert.equal(trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(doc.activeElement, trigger);
});

test('clicking outside closes the popover without stealing focus', (t) => {
    const {$, root, doc} = setup(t);
    const outside = $('#outside');

    $('[data-global-search-trigger]').click();
    outside.focus();
    outside.click();

    assert.ok(! root.classList.contains('is-open'));
    assert.equal(doc.activeElement, outside);
});

test('Ctrl+K and Cmd+K open the search, but not while a modal owns the page', (t) => {
    const {root, doc, input, key, api} = setup(t);

    key(doc.body, 'k', {ctrlKey: true});
    assert.ok(root.classList.contains('is-open'));
    assert.equal(doc.activeElement, input);
    api.close();

    key(doc.body, 'K', {metaKey: true});
    assert.ok(root.classList.contains('is-open'), '⌘ K บน macOS');
    api.close();

    doc.body.classList.add('modal-open');
    key(doc.body, 'k', {ctrlKey: true});
    assert.ok(! root.classList.contains('is-open'));
});

test('typing is debounced into one request, then results render grouped with highlights and status', async (t) => {
    const {$, input, type, calls} = setup(t);
    $('[data-global-search-trigger]').click();

    type('s');
    assert.match($('[data-global-search-message]').textContent, /อย่างน้อย 2 ตัวอักษร/);
    type('sm');
    type('sma');
    type('smart');
    assert.equal(calls.length, 0, 'ยังไม่ยิงระหว่างพิมพ์');

    await wait(DEBOUNCE * 4);
    assert.equal(calls.length, 1, 'ยิงครั้งเดียวหลังหยุดพิมพ์');
    assert.equal(new URL(calls[0].url).searchParams.get('q'), 'smart');
    assert.equal(calls[0].options.headers.Accept, 'application/json');

    const groups = [...$('[data-global-search-results]').querySelectorAll('[role="group"]')];
    assert.deepEqual(groups.map((group) => group.querySelector('.global-search__group-label').textContent), ['งาน', 'โปรเจกต์']);

    const options = [...$('[data-global-search-results]').querySelectorAll('[role="option"]')];
    assert.equal(options.length, 3);
    assert.equal(options[0].getAttribute('href'), '/my-tasks?open_task=7');
    assert.equal(options[0].querySelector('.global-search__option-title mark').textContent, 'Smart');
    assert.equal(options[0].querySelector('.global-search__status--purple').textContent, 'รอตรวจสอบ');
    assert.match(options[0].querySelector('.global-search__option-meta').textContent, /โปรเจกต์: Smart Goals/);
    assert.match(options[1].textContent, /งานที่ไม่อยู่ในโปรเจกต์/);
    assert.match(options[2].textContent, /โปรเจกต์ · Phunkorn/);

    assert.equal(input.getAttribute('aria-expanded'), 'true');
    assert.equal(options[0].getAttribute('aria-selected'), 'true');
    assert.equal(input.getAttribute('aria-activedescendant'), options[0].id);
    assert.equal($('[data-global-search-message]').hidden, true);
    assert.equal($('[data-global-search-status]').textContent, 'พบ 2 งาน และ 1 โปรเจกต์');
});

test('arrow keys move the selection with wrap-around and Enter opens the selected result', async (t) => {
    const {$, input, type, key, navigated} = setup(t);
    $('[data-global-search-trigger]').click();
    type('smart');
    await wait(DEBOUNCE * 4);

    const options = () => [...$('[data-global-search-results]').querySelectorAll('[role="option"]')];
    key(input, 'ArrowDown');
    assert.equal(options()[1].getAttribute('aria-selected'), 'true');
    key(input, 'ArrowDown');
    key(input, 'ArrowDown');
    assert.equal(options()[0].getAttribute('aria-selected'), 'true', 'ล่างสุดวนกลับบนสุด');
    key(input, 'ArrowUp');
    assert.equal(options()[2].getAttribute('aria-selected'), 'true', 'บนสุดวนไปล่างสุด');
    assert.equal(input.getAttribute('aria-activedescendant'), options()[2].id);

    key(input, 'Enter');
    assert.deepEqual(navigated, ['https://smartgoal.test/my-tasks?view=board&search=Smart+Goals']);
});

test('a slow answer for an old query never replaces the results of the newest query', async (t) => {
    const pending = [];
    const {$, type} = setup(t, {
        fetchImpl: (url, options) => new Promise((resolve) => pending.push({url, options, resolve})),
    });
    $('[data-global-search-trigger]').click();

    type('old query');
    await wait(DEBOUNCE * 4);
    type('new query');
    await wait(DEBOUNCE * 4);

    assert.equal(pending.length, 2);
    assert.equal(pending[0].options.signal.aborted, true, 'คำขอเก่าถูกยกเลิกเมื่อเริ่มคำขอใหม่');

    pending[1].resolve({ok: true, json: async () => payload({tasks: [{id: 2, title: 'new query result', project: null, status: null, url: '/n'}], projects: []})});
    await wait(5);
    pending[0].resolve({ok: true, json: async () => payload({tasks: [{id: 1, title: 'old query result', project: null, status: null, url: '/o'}], projects: []})});
    await wait(5);

    const titles = [...$('[data-global-search-results]').querySelectorAll('.global-search__option-title')].map((node) => node.textContent);
    assert.deepEqual(titles, ['new query result']);
});

test('empty and failed searches explain themselves instead of showing stale results', async (t) => {
    let respond = async () => ({ok: true, json: async () => payload({tasks: [], projects: []})});
    const {$, type} = setup(t, {fetchImpl: (...args) => respond(...args)});
    $('[data-global-search-trigger]').click();

    type('nothing');
    await wait(DEBOUNCE * 4);
    assert.equal($('[data-global-search-message]').textContent, 'ไม่พบงานหรือโปรเจกต์ที่ตรงกับ “nothing”');
    assert.equal($('[data-global-search-results]').children.length, 0);

    respond = async () => ({ok: false, status: 500, json: async () => ({})});
    type('broken');
    await wait(DEBOUNCE * 4);
    assert.equal($('[data-global-search-message]').textContent, 'ค้นหาไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    assert.equal($('[data-global-search-results]').children.length, 0, 'ผลของคำค้นก่อนหน้าไม่ค้างอยู่');
});

test('text from the database is rendered as text, never as HTML', async (t) => {
    const {$, type} = setup(t, {
        fetchImpl: async () => ({ok: true, json: async () => payload({
            tasks: [{id: 1, title: '<img src=x onerror="window.pwned=1"> smart', project: '<b>x</b>', status: null, url: '/t'}],
            projects: [],
        })}),
    });
    $('[data-global-search-trigger]').click();
    type('smart');
    await wait(DEBOUNCE * 4);

    const results = $('[data-global-search-results]');
    assert.equal(results.querySelector('img'), null);
    assert.equal(results.querySelector('b'), null);
    assert.match(results.querySelector('.global-search__option-title').textContent, /<img src=x/);
});

test('the clear button empties the query and the results and keeps focus in the field', async (t) => {
    const {$, doc, input, type} = setup(t);
    const clear = $('[data-global-search-clear]');
    $('[data-global-search-trigger]').click();

    type('smart');
    assert.equal(clear.hidden, false);
    await wait(DEBOUNCE * 4);

    clear.click();
    assert.equal(input.value, '');
    assert.equal(clear.hidden, true);
    assert.equal($('[data-global-search-results]').children.length, 0);
    assert.equal(input.getAttribute('aria-expanded'), 'false');
    assert.equal(doc.activeElement, input);
});

test('search and the Bootstrap dropdowns never stay open on top of each other', (t) => {
    const {$, root, doc, key} = setup(t, {withDropdown: true});
    const bell = $('#bell');

    bell.click();
    assert.equal(bell.getAttribute('aria-expanded'), 'true');
    key(doc.body, 'k', {ctrlKey: true});
    assert.ok(root.classList.contains('is-open'));
    assert.equal(bell.getAttribute('aria-expanded'), 'false', 'คีย์ลัดปิดดร็อปดาวน์ที่เปิดค้าง');

    bell.click();
    assert.equal(bell.getAttribute('aria-expanded'), 'true');
    assert.ok(! root.classList.contains('is-open'), 'เปิดดร็อปดาวน์แล้วกล่องค้นหาต้องหลีกทาง');
});

test('initialising twice keeps one set of listeners, and destroy releases them', async (t) => {
    const {doc, win, root, key, api} = setup(t);

    assert.equal(initGlobalSearch(doc, {win}), null);
    api.destroy();
    key(doc.body, 'k', {ctrlKey: true});
    assert.ok(! root.classList.contains('is-open'));
});
