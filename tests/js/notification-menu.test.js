import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom, click} from './helpers/dom.js';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/**
 * markup ที่ตรงกับดร็อปดาวน์แจ้งเตือนใน resources/views/layouts/app.blade.php
 * ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย test จึงจะยังสะท้อนของจริง
 */
function menuMarkup({unread = 2, read: readCount = 1} = {}) {
    const items = [
        ...Array.from({length: unread}, (_, index) =>
            `<div class="p-2 mb-2 notification-item is-new" data-dropdown-notification-id="u${index}">
                <a class="notification-body"><div class="notification-title">ใหม่ ${index}<span class="notification-new">ใหม่</span></div></a>
            </div>`),
        ...Array.from({length: readCount}, (_, index) =>
            `<div class="p-2 mb-2 notification-item" data-dropdown-notification-id="r${index}">
                <a class="notification-body"><div class="notification-title">อ่านแล้ว ${index}</div></a>
            </div>`),
    ].join('');

    return `
        <meta name="csrf-token" content="test-token">
        <span class="nav-item__count" data-notification-count>${unread}</span>
        <div class="dropdown">
            <button class="icon-btn" data-bs-toggle="dropdown">
                <span class="notification-count" data-notification-count>${unread}</span>
            </button>
            <div class="dropdown-menu notification-menu" data-notification-menu>
                <div class="notification-menu__head">
                    <strong>การแจ้งเตือน</strong>
                    <span class="badge-soft amber" data-notification-summary>${unread} รายการ</span>
                    <span class="notification-menu__actions">
                        <button type="button" class="notification-menu__action" data-notification-read-all></button>
                        <button type="button" class="notification-menu__action" data-notification-clear-read></button>
                    </span>
                </div>
                <div data-notification-dropdown-list>${items}</div>
                <div class="notification-dropdown-empty" data-notification-dropdown-empty hidden>ไม่มีการแจ้งเตือน</div>
            </div>
        </div>`;
}

async function mountMenu(t, {unread = 2, read: readCount = 1, ok = true, confirmed = true} = {}) {
    const env = mountDom();
    t.after(env.cleanup);

    /*
     * โหลดโมดูลก่อนวาง markup เพราะไฟล์ผูกตัวเองกับ document ตอน evaluate
     * ถ้าวาง markup ก่อน การผูกอัตโนมัติจะจับเมนูไปด้วย fetch จริงของ Node
     * แล้ว fetch จำลองในเทสต์จะไม่ถูกใช้เลย ทำให้เทสต์ตรวจไม่เจอของจริง
     */
    const {initNotificationMenu} = await import('../../resources/js/components/notification-menu.js');

    env.document.body.innerHTML = menuMarkup({unread, read: readCount});

    const calls = [];
    const fetchImpl = async (url, options) => {
        calls.push({url, method: options.method, token: options.headers['X-CSRF-TOKEN']});

        return {ok, json: async () => ({})};
    };

    const swalCalls = [];
    env.window.Swal = {
        fire: async (config) => {
            swalCalls.push(config);

            return {isConfirmed: confirmed};
        },
    };

    initNotificationMenu(env.document, fetchImpl);

    return {env, calls, swalCalls, document: env.document};
}

test('mark-all-read calls the shared route and clears every unread marker', async (t) => {
    const {calls, document} = await mountMenu(t);

    click(document.querySelector('[data-notification-read-all]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.deepEqual(calls, [{url: '/notifications/read-all', method: 'POST', token: 'test-token'}]);
    assert.equal(document.querySelectorAll('.is-new').length, 0);
    assert.equal(document.querySelectorAll('.notification-new').length, 0);
    // ตัวเลขทุกจุดต้องกลับเป็นศูนย์พร้อมกัน ทั้งกระดิ่ง เมนูข้าง และป้ายสรุป
    [...document.querySelectorAll('[data-notification-count]')].forEach((badge) => {
        assert.equal(badge.textContent, '0');
        assert.equal(badge.hidden, true);
    });
    assert.equal(document.querySelector('[data-notification-summary]').textContent, '0 รายการ');
    assert.equal(document.querySelector('[data-notification-read-all]').hasAttribute('disabled'), true);
});

test('clearing read items confirms first, then deletes only the read ones', async (t) => {
    const {calls, swalCalls, document} = await mountMenu(t, {unread: 2, read: 3});

    click(document.querySelector('[data-notification-clear-read]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(swalCalls.length, 1);
    assert.match(swalCalls[0].title, /ล้างรายการที่อ่านแล้ว/);
    assert.match(swalCalls[0].text, /3 รายการ/);
    assert.deepEqual(calls, [{url: '/notifications/read', method: 'DELETE', token: 'test-token'}]);
    // รายการที่ยังไม่อ่านต้องอยู่ครบ
    assert.equal(document.querySelectorAll('[data-dropdown-notification-id]').length, 2);
    assert.equal(document.querySelectorAll('.is-new').length, 2);
});

test('cancelling the confirmation deletes nothing', async (t) => {
    const {calls, document} = await mountMenu(t, {unread: 1, read: 2, confirmed: false});

    click(document.querySelector('[data-notification-clear-read]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.deepEqual(calls, []);
    assert.equal(document.querySelectorAll('[data-dropdown-notification-id]').length, 3);
});

test('clearing with nothing read explains instead of calling the endpoint', async (t) => {
    const {calls, swalCalls, document} = await mountMenu(t, {unread: 2, read: 0});

    click(document.querySelector('[data-notification-clear-read]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.deepEqual(calls, []);
    assert.equal(swalCalls[0].icon, 'info');
    assert.match(swalCalls[0].title, /ไม่มีรายการที่อ่านแล้ว/);
});

test('emptying the dropdown reveals the empty state', async (t) => {
    const {document} = await mountMenu(t, {unread: 0, read: 2});

    click(document.querySelector('[data-notification-clear-read]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(document.querySelectorAll('[data-dropdown-notification-id]').length, 0);
    assert.equal(document.querySelector('[data-notification-dropdown-empty]').hasAttribute('hidden'), false);
});

test('a failed request keeps the items and reports the failure', async (t) => {
    const {swalCalls, document} = await mountMenu(t, {unread: 2, read: 1, ok: false});

    click(document.querySelector('[data-notification-read-all]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(document.querySelectorAll('.is-new').length, 2);
    assert.equal(swalCalls.at(-1).icon, 'error');
    // ปุ่มต้องกลับมากดได้ ไม่ค้างเป็น disabled หลังยิงพลาด
    assert.equal(document.querySelector('[data-notification-read-all]').disabled, false);
});

test('initialising twice does not stack duplicate requests', async (t) => {
    const {env, calls, document} = await mountMenu(t);
    const {initNotificationMenu} = await import('../../resources/js/components/notification-menu.js');

    initNotificationMenu(env.document, async () => ({ok: true, json: async () => ({})}));
    click(document.querySelector('[data-notification-read-all]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(calls.length, 1);
});

/*
 * Bootstrap ปิดดร็อปดาวน์ด้วย display:none ซึ่งตัด transition ทิ้ง เมนูจึงกระโดดโผล่
 *
 * เมื่อบังคับเป็น display:block ตลอด ต้องคู่กับ data-bs-display="static" เสมอ
 * มิฉะนั้น Popper จะใส่ transform ให้ตอนเปิดแล้วเท่านั้น เมนูจึงถูกวาดชิดซ้ายหนึ่งเฟรม
 * ก่อนกระตุกไปขวา ซึ่งเป็นอาการที่ผู้ใช้เจอจริง
 */
test('topbar dropdowns slide open from a css-positioned anchor, never mid-flight from popper', async () => {
    const [css, layout] = await Promise.all([
        read('resources/css/components/layout/shared-ui.css'),
        read('resources/views/layouts/app.blade.php'),
    ]);

    const block = css.slice(css.indexOf('.dropdown-menu.topbar-slide-menu {'));
    const closed = block.slice(0, block.indexOf('}'));

    assert.match(closed, /display:\s*block/);
    assert.match(closed, /visibility:\s*hidden/);
    assert.match(closed, /transform:\s*translateY/);
    assert.match(closed, /transition:[^;]*transform/);
    assert.doesNotMatch(closed, /display:\s*none/);
    assert.match(css, /prefers-reduced-motion/);

    /*
     * Bootstrap ให้ top/right/left ผ่าน .dropdown-menu-end[data-bs-popper] แล้วถอด
     * attribute นั้นทิ้งตอนปิด เมนูที่เป็น display:block ตลอดจึงต้องมีตำแหน่งของตัวเอง
     * มิฉะนั้นจะดีดไปชิดซ้ายขณะเฟดออก ซึ่งเป็นบั๊กที่ผู้ใช้เจอจริง
     */
    const anchored = css.slice(css.indexOf('.dropdown-menu.dropdown-menu-end.topbar-slide-menu {'));
    const position = anchored.slice(0, anchored.indexOf('}'));

    assert.match(position, /top:\s*100%/);
    assert.match(position, /right:\s*0/);
    assert.match(position, /left:\s*auto/);
    // ต้องไม่ผูกตำแหน่งไว้กับ attribute ที่ Bootstrap ถอดออกตอนปิด
    assert.doesNotMatch(css.slice(css.indexOf('.dropdown-menu.topbar-slide-menu')), /topbar-slide-menu\[data-bs-popper\]/);

    // ทุกเมนูที่ใช้คลาสสไลด์ต้องถูกเปิดด้วยปุ่มที่เป็น static ไม่งั้นจะกลับไปกระตุกเหมือนเดิม
    const slideMenus = [...layout.matchAll(/class="dropdown-menu[^"]*topbar-slide-menu[^"]*"/g)];
    assert.equal(slideMenus.length, 2, 'กระดิ่งแจ้งเตือนและงานประจำใช้การสไลด์ชุดเดียวกัน');
    assert.equal([...layout.matchAll(/data-bs-toggle="dropdown" data-bs-display="static"/g)].length, 2);
});

test('the dropdown markup exposes both icon actions and loads the component globally', async () => {
    const [layout, entry] = await Promise.all([
        read('resources/views/layouts/app.blade.php'),
        read('resources/js/components/realtime-sync.js'),
    ]);

    assert.match(layout, /data-notification-menu/);
    assert.match(layout, /data-notification-read-all/);
    assert.match(layout, /data-notification-clear-read/);
    assert.match(layout, /bi-check2-all/);
    assert.match(layout, /bi-trash3/);
    // p-2 ของ Bootstrap เป็น !important จะทำให้ padding ค้างตอนย่อความสูง
    assert.doesNotMatch(layout, /dropdown-menu-end p-2 notification-menu/);
    assert.match(entry, /import '\.\/notification-menu\.js';/);
});
