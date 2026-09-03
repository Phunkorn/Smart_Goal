import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom} from './helpers/dom.js';
import {initNotificationCenter} from '../../resources/js/pages/notifications.js';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

const markup = ({pageItems = 1, totalItems = 1, readCount = 0, unread = true} = {}) => `
    <span data-notification-count>1</span>
    <span data-notification-summary class="amber">1 รายการ</span>
    <div data-dropdown-notification-id="1">dropdown item</div>
    <div data-notification-center data-page-items="${pageItems}" data-total-items="${totalItems}" data-read-count="${readCount}">
        <form action="http://localhost/notifications/read" method="post" data-delete-read-form data-delete-count="${readCount}">
            <input name="_method" value="DELETE"><button type="submit">bulk</button>
        </form>
        <div data-notification-list>
            <section data-notification-group>
                <article data-notification-center-item data-notification-id="1" class="${unread ? 'is-unread' : ''}">
                    <form action="http://localhost/notifications/1" method="post" data-delete-notification-form>
                        <input name="_method" value="DELETE"><button type="submit">delete</button>
                    </form>
                </article>
            </section>
        </div>
        <div class="notification-center__pagination">pages</div>
    </div>`;

const confirmedSwal = (calls) => ({
    async fire(options) {
        calls.push(options);
        return {isConfirmed: true};
    },
});

test('single delete confirms with SweetAlert, removes the row, and synchronizes badges and empty state', async () => {
    const mounted = mountDom(markup());
    const calls = [];
    const fetchImpl = async () => ({ok: true, json: async () => ({unread_count: 0, read_count: 0, deleted_count: 1})});

    initNotificationCenter({swal: confirmedSwal(calls), fetchImpl, reload: () => assert.fail('should not reload')});
    mounted.document.querySelector('[data-delete-notification-form]').dispatchEvent(new mounted.window.Event('submit', {bubbles: true, cancelable: true}));
    await tick();

    assert.equal(calls[0].title, 'ลบการแจ้งเตือนนี้?');
    assert.equal(mounted.document.querySelector('[data-notification-count]'), null);
    assert.equal(mounted.document.querySelector('[data-dropdown-notification-id="1"]'), null);
    assert.equal(mounted.document.querySelector('[data-notification-center-item]'), null);
    assert.ok(mounted.document.querySelector('[data-notification-empty]'));
    assert.equal(mounted.document.querySelector('.notification-center__pagination'), null);
    mounted.cleanup();
});

test('deleting the final row of a populated pagination page reloads the filtered URL', async () => {
    const mounted = mountDom(markup({pageItems: 1, totalItems: 26}));
    let reloads = 0;

    initNotificationCenter({
        swal: confirmedSwal([]),
        fetchImpl: async () => ({ok: true, json: async () => ({unread_count: 0, read_count: 0})}),
        reload: () => reloads++,
    });
    mounted.document.querySelector('[data-delete-notification-form]').dispatchEvent(new mounted.window.Event('submit', {bubbles: true, cancelable: true}));
    await tick();

    assert.equal(reloads, 1);
    mounted.cleanup();
});

test('bulk delete confirmation includes the server-provided read count and preserves unread count', async () => {
    const mounted = mountDom(markup({readCount: 12, unread: false}));
    const calls = [];
    let reloads = 0;

    initNotificationCenter({
        swal: confirmedSwal(calls),
        fetchImpl: async () => ({ok: true, json: async () => ({unread_count: 1, read_count: 0, deleted_count: 12})}),
        reload: () => reloads++,
    });
    mounted.document.querySelector('[data-delete-read-form]').dispatchEvent(new mounted.window.Event('submit', {bubbles: true, cancelable: true}));
    await tick();

    assert.match(calls[0].text, /12.*ไม่กระทบรายการที่ยังไม่อ่าน/);
    assert.equal(mounted.document.querySelector('[data-notification-count]').textContent, '1');
    assert.equal(mounted.document.querySelector('[data-delete-read-form] button').disabled, true);
    assert.equal(reloads, 1);
    mounted.cleanup();
});

test('notification redesign keeps filters focused, the full row actionable, and responsive controls usable', async () => {
    const css = await readFile(new URL('../../resources/css/pages/notifications.css', import.meta.url), 'utf8');
    const blade = await readFile(new URL('../../resources/views/notifications/index.blade.php', import.meta.url), 'utf8');
    const js = await readFile(new URL('../../resources/js/pages/notifications.js', import.meta.url), 'utf8');
    const mobile = css.slice(css.indexOf('@media (max-width: 760px)'));
    const tablet = css.slice(css.indexOf('@media (min-width: 761px) and (max-width: 991px)'), css.indexOf('@media (max-width: 760px)'));

    assert.match(css, /\.notification-center\s*\{[^}]*min-width:\s*0/s);
    assert.match(css, /\.notification-center__advanced-filters\s*\{[^}]*grid-template-columns:/s);
    assert.match(css, /\.notification-center__item-link\s*\{[^}]*display:\s*grid/s);
    assert.match(css, /overflow-wrap:\s*anywhere/);
    assert.match(css, /\.notification-center__actions \.dropdown-menu\s*\{[^}]*max-width:\s*calc\(100vw - 24px\)/s);
    assert.match(css, /\.swal2-popup\.notification-confirm\s*\{[^}]*max-width:\s*calc\(100vw - 24px\)/s);
    assert.match(tablet, /\.notification-center__advanced-filters\s*\{[^}]*repeat\(2, minmax\(0, 1fr\)\)/s);
    assert.match(mobile, /\.notification-center__advanced-filters\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\)/s);
    assert.match(mobile, /\.notification-center__item-link\s*\{[^}]*grid-template-columns:/s);
    assert.match(mobile, /-webkit-line-clamp:\s*3/);
    assert.match(blade, /data-bs-boundary="viewport"/);
    assert.match(blade, /class="notification-center__item-link"/);
    assert.match(blade, /data-bs-target="#notificationFilters"/);
    assert.doesNotMatch(blade, />งาน<\/a>/);
    assert.match(js, /swal = globalThis\.Swal/);
    assert.doesNotMatch(js, /\bconfirm\s*\(/);
});
