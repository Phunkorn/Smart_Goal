import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
import {initMobileSidebar} from '../../resources/js/components/mobile-sidebar.js';

/*
 * แถบเมนูด้านข้างของจอต่ำกว่า 992px (layouts/components/mobile-sidebar.blade.php + mobile-sidebar.js)
 * markup มีโครงเดียวกับ Blade (hook ตรวจกับไฟล์จริงใน top-navigation-contract.test.js)
 * ทดสอบเส้นทางจริงตั้งแต่กดปุ่ม ☰ จนแถบเปิด โฟกัส inert และการปิดทุกทาง
 */
const bootstrapSource = readFileSync(new URL('../../node_modules/bootstrap/dist/js/bootstrap.bundle.js', import.meta.url), 'utf8');

function setup(t, {desktop = false} = {}) {
    const dom = new JSDOM(`<!doctype html><html><body>
        <header class="topbar">
            <button type="button" class="topbar-menu-btn" data-mobile-sidebar-open aria-controls="mobileSidebar" aria-expanded="false" aria-label="เปิดเมนู">☰</button>
            <div class="dropdown"><button type="button" id="bell" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">bell</button>
                <div class="dropdown-menu topbar-slide-menu"><a class="dropdown-item" href="/n">n</a></div></div>
        </header>
        <div class="mobile-sidebar" id="mobileSidebar" role="dialog" aria-modal="true" data-mobile-sidebar inert>
            <button type="button" class="mobile-sidebar__close" data-mobile-sidebar-close aria-label="ปิดเมนู">x</button>
            <nav class="mobile-nav"><ul>
                <li><a href="/my-tasks" class="mobile-nav__link is-active" aria-current="page" id="mytasks">งานของฉัน</a></li>
                <li class="mobile-nav__group" data-mobile-nav-group>
                    <button type="button" class="mobile-nav__link mobile-nav__toggle" data-mobile-nav-toggle aria-expanded="false" aria-controls="mobile-nav-reports" id="reports">รายงาน</button>
                    <div class="mobile-nav__panel" id="mobile-nav-reports" data-mobile-nav-panel inert>
                        <a href="/reports/projects" class="mobile-nav__sublink" id="projects">รายงานโปรเจกต์</a>
                    </div>
                </li>
            </ul></nav>
        </div>
        <div class="mobile-sidebar-backdrop" data-mobile-sidebar-close aria-hidden="true"></div>
        <main class="main" id="main" tabindex="0">content</main>
    </body></html>`, {url: 'https://smartgoal.test/my-tasks', runScripts: 'outside-only', pretendToBeVisual: true});
    t.after(() => dom.window.close());

    const listeners = [];
    dom.window.matchMedia = () => ({
        matches: desktop,
        addEventListener: (type, listener) => listeners.push(listener),
    });
    dom.window.HTMLElement.prototype.getClientRects = () => [{width: 100, height: 30}];
    dom.window.eval(bootstrapSource);

    const doc = dom.window.document;
    const api = initMobileSidebar(doc, {win: dom.window});
    const $ = (selector) => doc.querySelector(selector);
    const key = (target, name) => target.dispatchEvent(new dom.window.KeyboardEvent('keydown', {key: name, bubbles: true, cancelable: true}));
    const crossBreakpoint = (matches) => listeners.forEach((listener) => listener({matches}));

    return {dom, doc, win: dom.window, api, $, key, crossBreakpoint, sidebar: $('[data-mobile-sidebar]'), opener: $('[data-mobile-sidebar-open]')};
}

const isOpen = (doc) => doc.body.classList.contains('mobile-sidebar-open');

test('starts closed: the menu is inert so Tab cannot reach links that are off screen', (t) => {
    const {doc, sidebar, opener, $} = setup(t);

    assert.equal(isOpen(doc), false);
    assert.equal(sidebar.hasAttribute('inert'), true);
    assert.equal(opener.getAttribute('aria-expanded'), 'false');
    assert.equal($('header.topbar').hasAttribute('inert'), false);
    assert.equal($('#main').hasAttribute('inert'), false);
});

test('the hamburger opens the drawer, locks the page behind it and focuses the current page link', (t) => {
    const {doc, sidebar, opener, $} = setup(t);

    opener.click();

    assert.equal(isOpen(doc), true, 'คลาสบน body คุมทั้งแอนิเมชันและการล็อกการเลื่อนหน้า (CSS)');
    assert.equal(sidebar.hasAttribute('inert'), false);
    assert.equal(opener.getAttribute('aria-expanded'), 'true');
    assert.equal($('header.topbar').hasAttribute('inert'), true, 'Topbar ข้างหลังรับโฟกัสไม่ได้ระหว่างเปิด');
    assert.equal($('#main').hasAttribute('inert'), true);
    assert.equal(doc.activeElement.id, 'mytasks');
    assert.equal(sidebar.getAttribute('style'), null, 'ไม่แก้ style ตรง ๆ');
});

test('X, the backdrop and Escape close the drawer and return focus to the hamburger', (t) => {
    const {doc, opener, $, key} = setup(t);

    for (const closeWith of [
        () => $('.mobile-sidebar__close').click(),
        () => $('.mobile-sidebar-backdrop').click(),
        () => key(doc.activeElement, 'Escape'),
    ]) {
        opener.click();
        assert.equal(isOpen(doc), true);
        closeWith();
        assert.equal(isOpen(doc), false);
        assert.equal($('[data-mobile-sidebar]').hasAttribute('inert'), true);
        assert.equal($('#main').hasAttribute('inert'), false);
        assert.equal(doc.activeElement, opener);
    }
});

test('following a menu link closes the drawer without pulling focus back', (t) => {
    const {doc, opener, $} = setup(t);

    opener.click();
    $('#mytasks').addEventListener('click', (event) => event.preventDefault());
    $('#mytasks').click();

    assert.equal(isOpen(doc), false);
    assert.notEqual(doc.activeElement, opener, 'หน้ากำลังเปลี่ยน ไม่ต้องดึงโฟกัสกลับ');
});

test('group toggles expand and collapse the accordion and only an expanded group is reachable', (t) => {
    const {doc, opener, $} = setup(t);
    const toggle = $('#reports');
    const panel = $('#mobile-nav-reports');

    opener.click();
    toggle.click();
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(toggle.closest('[data-mobile-nav-group]').classList.contains('is-open'), true);
    assert.equal(panel.hasAttribute('inert'), false);
    assert.equal(isOpen(doc), true, 'การกางกลุ่มไม่ปิดแถบเมนู');

    toggle.click();
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(panel.hasAttribute('inert'), true);
});

test('opening the drawer closes an open topbar dropdown first', (t) => {
    const {opener, $} = setup(t);

    $('#bell').click();
    assert.equal($('#bell').getAttribute('aria-expanded'), 'true');
    opener.click();
    assert.equal($('#bell').getAttribute('aria-expanded'), 'false');
});

test('growing past 992px while open closes the drawer (desktop uses the horizontal menu)', (t) => {
    const {doc, opener, crossBreakpoint} = setup(t);

    opener.click();
    crossBreakpoint(true);
    assert.equal(isOpen(doc), false);
    assert.equal(opener.getAttribute('aria-expanded'), 'false');
});

test('initialising twice keeps one set of listeners, and destroy releases them', (t) => {
    const {doc, win, api, opener} = setup(t);

    assert.equal(initMobileSidebar(doc, {win}), null);
    opener.click();
    assert.equal(isOpen(doc), true, 'ผูก listener ครั้งเดียว — คลิกเดียวเปิด ไม่เปิดแล้วปิดทันที');

    api.close();
    api.destroy();
    opener.click();
    assert.equal(isOpen(doc), false);
});
