import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
import {initTopNavigation} from '../../resources/js/components/top-navigation.js';

/*
 * เมนูหลักแนวนอน (layouts/components/top-navigation.blade.php + resources/js/components/top-navigation.js)
 *
 * markup ด้านล่างมีโครงเดียวกับที่ Blade วาด (hook เดียวกันถูกตรวจกับไฟล์ Blade จริงใน
 * top-navigation-contract.test.js) และโหลด Bootstrap ตัวจริง เพื่อทดสอบเส้นทางจริงตั้งแต่คลิกจนเมนูเปิด
 */
const bootstrapSource = readFileSync(new URL('../../node_modules/bootstrap/dist/js/bootstrap.bundle.js', import.meta.url), 'utf8');

const group = (key, label, items, active = false) => `
    <li class="top-nav__item dropdown">
        <button type="button" class="top-nav__link top-nav__toggle${active ? ' is-active' : ''}" data-top-nav-entry
            id="top-nav-${key}" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" aria-controls="top-nav-${key}-menu">
            <span class="top-nav__label">${label}</span>
        </button>
        <div class="dropdown-menu topbar-slide-menu nav-dropdown" id="top-nav-${key}-menu" aria-labelledby="top-nav-${key}">
            <div class="nav-dropdown__section" role="group">
                ${items.map((item) => `<a href="/${item}" class="dropdown-item nav-dropdown__item">${item}</a>`).join('')}
            </div>
        </div>
    </li>`;

function setup(t, {activeGroup = false} = {}) {
    const dom = new JSDOM(`<!doctype html><html><body>
        <header class="topbar">
            <nav class="top-nav" aria-label="เมนูหลัก" data-top-nav>
                <ul class="top-nav__list">
                    <li class="top-nav__item"><a href="/my-tasks" class="top-nav__link" data-top-nav-entry id="mytasks">งานของฉัน</a></li>
                    <li class="top-nav__item"><a href="/daily-logs" class="top-nav__link is-active" data-top-nav-entry aria-current="page" id="daily">บันทึกงานประจำวัน</a></li>
                    ${group('reports', 'รายงาน', ['operational', 'projects'], activeGroup)}
                    ${group('more', 'เพิ่มเติม', ['tickets', 'notifications'])}
                </ul>
            </nav>
        </header>
        <main id="outside" tabindex="0">content</main>
    </body></html>`, {url: 'https://smartgoal.test/daily-logs', runScripts: 'outside-only', pretendToBeVisual: true});
    t.after(() => dom.window.close());
    // jsdom ไม่มี layout engine — Bootstrap กรองรายการที่ "มองเห็น" ด้วย getClientRects
    dom.window.HTMLElement.prototype.getClientRects = () => [{width: 100, height: 30}];
    dom.window.eval(bootstrapSource);

    const doc = dom.window.document;
    const api = initTopNavigation(doc, {win: dom.window});
    const key = (target, keyName) => target.dispatchEvent(new dom.window.KeyboardEvent('keydown', {key: keyName, bubbles: true, cancelable: true}));
    const $ = (selector) => doc.querySelector(selector);

    return {dom, doc, win: dom.window, api, key, $};
}

test('clicking a group opens its menu, a second click closes it, and the chevron state follows aria-expanded', (t) => {
    const {$} = setup(t);
    const toggle = $('#top-nav-reports');
    const menu = $('#top-nav-reports-menu');

    toggle.click();
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    assert.ok(menu.classList.contains('show'));
    // static: ตำแหน่งมาจาก CSS ไม่ใช่ inline transform ของ Popper ที่จะชนกับแอนิเมชัน
    assert.equal(menu.getAttribute('style'), null);

    toggle.click();
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.ok(! menu.classList.contains('show'));
});

test('opening another group closes the first, and clicking outside closes everything', (t) => {
    const {$} = setup(t);

    $('#top-nav-reports').click();
    $('#top-nav-more').click();
    assert.ok(! $('#top-nav-reports-menu').classList.contains('show'));
    assert.ok($('#top-nav-more-menu').classList.contains('show'));

    $('#outside').click();
    assert.ok(! $('#top-nav-more-menu').classList.contains('show'));
    assert.equal($('#top-nav-more').getAttribute('aria-expanded'), 'false');
});

test('Escape closes the open menu and returns focus to its toggle', (t) => {
    const {$, doc, key} = setup(t);
    const toggle = $('#top-nav-more');

    toggle.click();
    $('#top-nav-more-menu .dropdown-item').focus();
    key(doc.activeElement, 'Escape');

    assert.ok(! $('#top-nav-more-menu').classList.contains('show'));
    assert.equal(doc.activeElement, toggle);
});

test('ArrowDown on a toggle opens the menu and walks its items (Bootstrap keyboard support)', (t) => {
    const {$, doc, key} = setup(t);
    const toggle = $('#top-nav-reports');

    toggle.focus();
    key(toggle, 'ArrowDown');
    assert.ok($('#top-nav-reports-menu').classList.contains('show'));
    // ลูกศรลงครั้งแรกเปิดเมนูและโฟกัสรายการแรกทันที (แบบแผนของปุ่มเปิดเมนู)
    assert.equal(doc.activeElement?.getAttribute('href'), '/operational');

    key(doc.activeElement, 'ArrowDown');
    assert.equal(doc.activeElement?.getAttribute('href'), '/projects');
    key(doc.activeElement, 'ArrowUp');
    assert.equal(doc.activeElement?.getAttribute('href'), '/operational');
});

test('ArrowLeft/ArrowRight/Home/End move between top-level entries and wrap around', (t) => {
    const {$, doc, key} = setup(t);

    $('#mytasks').focus();
    key(doc.activeElement, 'ArrowRight');
    assert.equal(doc.activeElement.id, 'daily');
    key(doc.activeElement, 'ArrowRight');
    assert.equal(doc.activeElement.id, 'top-nav-reports');
    key(doc.activeElement, 'End');
    assert.equal(doc.activeElement.id, 'top-nav-more');
    key(doc.activeElement, 'ArrowRight');
    assert.equal(doc.activeElement.id, 'mytasks', 'ขวาสุดวนกลับซ้ายสุด');
    key(doc.activeElement, 'ArrowLeft');
    assert.equal(doc.activeElement.id, 'top-nav-more', 'ซ้ายสุดวนไปขวาสุด');
    key(doc.activeElement, 'Home');
    assert.equal(doc.activeElement.id, 'mytasks');
});

test('ArrowRight inside an open menu closes it and moves to the next top-level entry', (t) => {
    const {$, doc, key} = setup(t);

    $('#top-nav-reports').click();
    $('#top-nav-reports-menu .dropdown-item').focus();
    key(doc.activeElement, 'ArrowRight');

    assert.ok(! $('#top-nav-reports-menu').classList.contains('show'));
    assert.equal(doc.activeElement.id, 'top-nav-more');
});

test('initialising twice does not double the listeners, and destroy removes them', (t) => {
    const {$, doc, key, win, api} = setup(t);

    assert.equal(initTopNavigation(doc, {win}), null, 'ครั้งที่สองไม่ผูกซ้ำ');

    $('#mytasks').focus();
    key(doc.activeElement, 'ArrowRight');
    assert.equal(doc.activeElement.id, 'daily', 'กดครั้งเดียวต้องเลื่อนไปหนึ่งรายการ');

    api.destroy();
    key(doc.activeElement, 'ArrowRight');
    assert.equal(doc.activeElement.id, 'daily', 'หลัง destroy ไม่มี listener ของเราแล้ว');
});
