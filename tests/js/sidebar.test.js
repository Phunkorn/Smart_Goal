import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {click, mountDom, pressKey} from './helpers/dom.js';
import {DESKTOP_MIN_WIDTH, initSidebar} from '../../resources/js/components/sidebar.js';

/*
 * แถบข้างของ layouts/app.blade.php — โครงย่อที่มีเฉพาะ hook ที่สคริปต์ใช้จริง
 * (เทสต์สุดท้ายยืนยันว่า layout ยังมี hook ชุดนี้ครบ)
 */
const markup = `<!doctype html><html><body>
    <aside class="sidebar" id="appSidebar">
        <button type="button" class="sidebar-close" data-sidebar-close>x</button>
        <nav class="sidebar-nav">
            <a href="/mytasks" class="nav-item"><i></i><span class="nav-item__label">งานของฉัน</span></a>
            <details class="report-nav">
                <summary class="nav-item report-nav__toggle"><span class="nav-item__label">รายงาน</span></summary>
                <a href="/reports/projects" class="nav-item report-nav__link"><span class="nav-item__label">รายงานโปรเจกต์</span></a>
            </details>
        </nav>
    </aside>
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <header class="topbar">
        <button type="button" class="mobile-menu-btn" aria-expanded="false" data-sidebar-open>menu</button>
    </header>
    <main class="main"><button type="button" id="outside">outside</button></main>
</body></html>`;

function setup(width, {collapsed = false} = {}) {
    const env = mountDom(markup);
    const {window, document} = env;
    window.innerWidth = width;
    if (collapsed) document.body.classList.add('sidebar-collapsed');
    const saved = new Map();
    const storage = {setItem: (key, value) => saved.set(key, value)};
    initSidebar(document, {win: window, storage});

    return {
        ...env,
        saved,
        body: document.body,
        menu: document.querySelector('[data-sidebar-open]'),
        resize(next) {
            window.innerWidth = next;
            window.dispatchEvent(new window.Event('resize'));
        },
    };
}

test('Escape บนเดสก์ท็อปไม่ย่อแถบข้าง (เช่น Escape ที่ใช้ปิด modal อื่น)', () => {
    const {body, document, saved, cleanup} = setup(1366);
    pressKey(document.getElementById('outside'), 'Escape');

    assert.equal(body.classList.contains('sidebar-collapsed'), false);
    assert.equal(saved.size, 0, 'ไม่เขียนสถานะย่อ');
    cleanup();
});

test('ปุ่มเมนูบนเดสก์ท็อปย่อ/กางแถบ จำสถานะ และใส่ tooltip เฉพาะตอนย่อ', () => {
    const {body, menu, document, saved, cleanup} = setup(1366);
    const link = document.querySelector('a.nav-item');

    click(menu);
    assert.equal(body.classList.contains('sidebar-collapsed'), true);
    assert.equal(saved.get('smartgoal.sidebar.collapsed'), '1');
    assert.equal(menu.getAttribute('aria-expanded'), 'false');
    assert.equal(link.getAttribute('title'), 'งานของฉัน');

    click(menu);
    assert.equal(body.classList.contains('sidebar-collapsed'), false);
    assert.equal(saved.get('smartgoal.sidebar.collapsed'), '0');
    assert.equal(menu.getAttribute('aria-expanded'), 'true');
    assert.equal(link.hasAttribute('title'), false);
    cleanup();
});

test('drawer จอเล็ก: เปิดแล้วโฟกัสปุ่มปิด · Escape ปิดและคืนโฟกัสปุ่มเมนู', () => {
    const {body, menu, document, cleanup} = setup(375);

    click(menu);
    assert.equal(body.classList.contains('sidebar-open'), true);
    assert.equal(menu.getAttribute('aria-expanded'), 'true');
    assert.equal(document.activeElement, document.querySelector('.sidebar-close'));

    pressKey(document.activeElement, 'Escape');
    assert.equal(body.classList.contains('sidebar-open'), false);
    assert.equal(menu.getAttribute('aria-expanded'), 'false');
    assert.equal(document.activeElement, menu);
    cleanup();
});

test('drawer จอเล็ก: ปิดด้วย backdrop และปิดเมื่อคลิกลิงก์ — ไม่ย่อแถบของเดสก์ท็อป', () => {
    const {body, menu, document, saved, cleanup} = setup(375);

    click(menu);
    click(document.querySelector('.sidebar-backdrop'));
    assert.equal(body.classList.contains('sidebar-open'), false);
    assert.equal(document.activeElement, menu);

    click(menu);
    document.querySelector('a.nav-item').addEventListener('click', (event) => event.preventDefault());
    click(document.querySelector('a.nav-item'));
    assert.equal(body.classList.contains('sidebar-open'), false);

    assert.equal(body.classList.contains('sidebar-collapsed'), false);
    assert.equal(saved.size, 0);
    cleanup();
});

test('drawer จอเล็ก: Tab ไม่ไปลิงก์ที่ซ่อนนอกจอ และไม่หลุดไปหลัง backdrop (inert)', () => {
    const {body, menu, document, cleanup} = setup(375);
    const sidebar = document.getElementById('appSidebar');
    const behind = [...document.querySelectorAll('.topbar, main.main')];
    const inert = (element) => element.hasAttribute('inert');

    // ปิดอยู่: drawer อยู่นอกจอด้วย transform จึงต้อง inert ส่วนหน้าหลักใช้งานได้
    assert.equal(inert(sidebar), true);
    assert.deepEqual(behind.map(inert), [false, false]);

    click(menu);
    assert.equal(inert(sidebar), false);
    assert.deepEqual(behind.map(inert), [true, true]);
    assert.equal(document.activeElement, document.querySelector('.sidebar-close'), 'โฟกัสเข้า drawer หลังถอด inert');

    pressKey(document.activeElement, 'Escape');
    assert.equal(inert(sidebar), true);
    assert.deepEqual(behind.map(inert), [false, false]);
    assert.equal(document.activeElement, menu, 'คืนโฟกัสได้เพราะ Topbar ไม่ inert แล้ว');
    assert.equal(body.classList.contains('sidebar-open'), false);
    cleanup();
});

test('เดสก์ท็อปไม่มี inert ทั้งตอนกางและย่อ · ข้ามไปเดสก์ท็อปขณะ drawer เปิดก็ถอด inert ทั้งหมด', () => {
    const desktop = setup(1366);
    const all = () => [...desktop.document.querySelectorAll('#appSidebar, .topbar, main.main')].map((element) => element.hasAttribute('inert'));
    assert.deepEqual(all(), [false, false, false]);
    click(desktop.menu);
    assert.deepEqual(all(), [false, false, false]);
    desktop.cleanup();

    const mobile = setup(768);
    click(mobile.menu);
    mobile.resize(1366);
    assert.deepEqual([...mobile.document.querySelectorAll('#appSidebar, .topbar, main.main')].map((element) => element.hasAttribute('inert')), [false, false, false]);
    mobile.cleanup();
});

test('resize ภายในจอเล็ก (แถบ URL มือถือหด/ขยาย) ไม่ปิด drawer · ข้ามไปเดสก์ท็อปจึงปิด', () => {
    const {body, menu, resize, cleanup} = setup(390);

    click(menu);
    resize(390);
    resize(768);
    assert.equal(body.classList.contains('sidebar-open'), true);

    resize(DESKTOP_MIN_WIDTH);
    assert.equal(body.classList.contains('sidebar-open'), false);
    cleanup();
});

test('กดหัวกลุ่มรายงานตอนย่อ → กางแถบพร้อมเปิดกลุ่ม', () => {
    const {body, document, saved, cleanup} = setup(1366, {collapsed: true});
    const group = document.querySelector('.report-nav');

    click(group.querySelector('.report-nav__toggle'));
    assert.equal(body.classList.contains('sidebar-collapsed'), false);
    assert.equal(group.open, true);
    assert.equal(saved.get('smartgoal.sidebar.collapsed'), '0');
    cleanup();
});

test('เรียก initSidebar ซ้ำไม่ผูก listener ซ้ำ (คลิกหนึ่งครั้ง = สลับหนึ่งครั้ง)', () => {
    const {body, menu, window, document, cleanup} = setup(1366);
    assert.equal(initSidebar(document, {win: window, storage: null}), null);

    click(menu);
    assert.equal(body.classList.contains('sidebar-collapsed'), true);
    cleanup();
});

test('layout โหลดสคริปต์แถบข้างผ่าน Vite และมี hook ที่สคริปต์ใช้ครบ ไม่มีสคริปต์แถบข้างแบบ inline ซ้ำ', () => {
    const layout = fs.readFileSync('resources/views/layouts/app.blade.php', 'utf8');
    const vite = fs.readFileSync('vite.config.js', 'utf8');

    assert.match(layout, /@vite\('resources\/js\/components\/sidebar\.js'\)/);
    assert.match(vite, /'resources\/js\/components\/sidebar\.js'/);
    for (const hook of ['id="appSidebar"', 'class="sidebar-close"', 'data-sidebar-open', 'class="sidebar-backdrop" data-sidebar-close', '<header class="topbar">', '<main class="main">']) {
        assert.ok(layout.includes(hook), hook);
    }
    assert.doesNotMatch(layout, /addEventListener\('resize'/, 'พฤติกรรมแถบข้างมีที่เดียวคือ sidebar.js');
});
