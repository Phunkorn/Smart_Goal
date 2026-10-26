import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {initTicketDetail} from '../../resources/js/pages/tickets/detail.js';
import {click, mountDom} from './helpers/dom.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

/** markup ที่ตรงกับ tickets/show.blade.php + components/actions, status-modal (เฉพาะ hook ที่ JS ใช้) */
const markup = ({openOnLoad = false} = {}) => `<!doctype html><html><body>
<div data-ticket-detail>
    <form id="start" method="POST" action="/tickets/1/status" data-ticket-action-form>
        <button type="submit">รับงาน</button>
    </form>
    <button type="button" id="follow-up-button" data-bs-target="#ticket-modal-follow-up" data-ticket-confirm-open
        data-ticket-confirm="ต้องการปรับสถานะเป็น งานที่ต้องติดตาม ใช่ไหม?">ติดตามงาน</button>
    <button type="button" id="cancel-button" data-bs-target="#ticket-modal-cancel" data-ticket-confirm-open
        data-ticket-confirm="ต้องการยกเลิกงานนี้ใช่ไหม?">ยกเลิกงาน</button>
    <div class="modal" id="ticket-modal-close" data-ticket-modal ${openOnLoad ? 'data-open-on-load="true"' : ''}>
        <form id="close" method="POST" action="/tickets/1/status" data-ticket-action-form>
            <textarea name="note" required></textarea>
            <button type="submit">ปิดงาน</button>
        </form>
    </div>
    <div class="modal" id="ticket-modal-follow-up" data-ticket-modal><form data-ticket-action-form><textarea name="note" required></textarea></form></div>
    <div class="modal" id="ticket-modal-cancel" data-ticket-modal><form data-ticket-action-form><textarea name="note" required></textarea></form></div>
</div>
</body></html>`;

function setup(options = {}, dialogAnswer = true) {
    const dom = mountDom(markup(options));
    const dialogs = [];
    const shown = [];

    initTicketDetail(dom.document, {
        bootstrapApi: {Modal: {getOrCreateInstance: (element) => ({show: () => shown.push(element.id)})}},
        swal: () => ({
            fire: (options) => {
                dialogs.push(options);

                return Promise.resolve({isConfirmed: dialogAnswer});
            },
        }),
    });

    return {...dom, dialogs, shown};
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

test('follow-up and cancel ask with SweetAlert first, then open their own reason modal', async () => {
    const {document, dialogs, shown, cleanup} = setup();
    try {
        click(document.querySelector('#follow-up-button'));
        await flush();
        assert.match(dialogs[0].text, /งานที่ต้องติดตาม/);
        assert.deepEqual(shown, ['ticket-modal-follow-up']);

        click(document.querySelector('#cancel-button'));
        await flush();
        assert.match(dialogs[1].text, /ยกเลิกงาน/);
        assert.deepEqual(shown, ['ticket-modal-follow-up', 'ticket-modal-cancel']);
    } finally {
        cleanup();
    }
});

test('answering no opens nothing', async () => {
    const {document, dialogs, shown, cleanup} = setup({}, false);
    try {
        click(document.querySelector('#cancel-button'));
        await flush();
        assert.equal(dialogs.length, 1);
        assert.deepEqual(shown, []);
    } finally {
        cleanup();
    }
});

test('an action form cannot be submitted twice', () => {
    const {document, window, cleanup} = setup();
    try {
        const form = document.querySelector('#start');
        const first = new window.Event('submit', {bubbles: true, cancelable: true});
        form.dispatchEvent(first);
        assert.equal(first.defaultPrevented, false);
        assert.equal(form.querySelector('button').disabled, true);
        assert.equal(form.querySelector('button').getAttribute('aria-busy'), 'true');

        const second = new window.Event('submit', {bubbles: true, cancelable: true});
        form.dispatchEvent(second);
        assert.equal(second.defaultPrevented, true);
    } finally {
        cleanup();
    }
});

test('the modal that failed validation opens again on load, and no other', () => {
    const reopened = setup({openOnLoad: true});
    try {
        assert.deepEqual(reopened.shown, ['ticket-modal-close']);
    } finally {
        reopened.cleanup();
    }

    const normal = setup();
    try {
        assert.deepEqual(normal.shown, []);
    } finally {
        normal.cleanup();
    }
});

test('init is idempotent', () => {
    const {document, dialogs, cleanup} = setup();
    try {
        assert.equal(initTicketDetail(document), false);
        click(document.querySelector('#cancel-button'));
        assert.equal(dialogs.length, 1, 'one listener, one dialog');
    } finally {
        cleanup();
    }
});

test('the hooks detail.js relies on exist in the Blade components', () => {
    const show = read('resources/views/tickets/show.blade.php');
    const actions = read('resources/views/tickets/components/actions.blade.php');
    const modal = read('resources/views/tickets/components/status-modal.blade.php');

    assert.ok(show.includes('data-ticket-detail'));
    assert.ok(actions.includes('data-ticket-action-form'));
    // ติดตามงาน / ยกเลิกงาน: ยืนยันก่อนแล้วจึงเปิด modal ของตัวเอง (ไม่ใช้ data-bs-toggle — เปิดหลังกดยืนยันเท่านั้น)
    assert.match(actions, /data-bs-target="#ticket-modal-follow-up" data-ticket-confirm-open\s+data-ticket-confirm="/);
    assert.match(actions, /data-bs-target="#ticket-modal-cancel" data-ticket-confirm-open\s+data-ticket-confirm="/);
    assert.doesNotMatch(actions, /data-ticket-leave|data-ticket-note-/, 'ไม่มีปุ่มออกจาก Ticket / บันทึก / แนบรูปแยก');
    assert.match(show, /'action' => Ticket::ACTION_CANCEL, 'title' => 'ยกเลิกงาน'/);
    assert.ok(modal.includes('data-ticket-modal'));
    assert.ok(modal.includes('data-open-on-load="true"'));
});

test('detail and list scripts never decide permissions or poll the server', () => {
    for (const path of ['resources/js/pages/tickets/detail.js', 'resources/js/pages/tickets/index.js']) {
        // ตัดคอมเมนต์ออกก่อน — คอมเมนต์ที่อธิบายว่า "ห้ามใช้ confirm()" ต้องไม่ทำให้ตรวจผิด
        const source = read(path).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\s\/\/.*$/gm, '');
        assert.doesNotMatch(source, /\bfetch\(|setInterval|XMLHttpRequest/, path);
        assert.doesNotMatch(source, /\b(role|isAgent|is_agent|admin)\b/, path);
        assert.doesNotMatch(source, /\b(alert|confirm|prompt)\(/, path);
    }
});

test('every ticket modal close button has a 44px touch target', () => {
    const css = readFileSync(new URL('../../resources/css/pages/tickets/base.css', import.meta.url), 'utf8');
    const rule = css.match(/\.ticket-page \.modal \.btn-close \{([^}]*)\}/)?.[1] ?? '';

    // 1em (16px) + 14px × 2 = 44px
    assert.match(rule, /box-sizing: content-box;/);
    assert.match(rule, /width: 1em;/);
    assert.match(rule, /padding: 14px;/);
});
