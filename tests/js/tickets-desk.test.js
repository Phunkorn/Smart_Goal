import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {AUTO_REFRESH_INTERVAL, initTicketDesk, swapDeskRegions} from '../../resources/js/pages/tickets/desk.js';
import {click, mountDom} from './helpers/dom.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

/** hook ชุดเดียวกับ tickets/index + components/overview, queue, claim-actions, preview-modal */
function deskMarkup({stamp = '09:00', total = 2, rows = ['TK-000001']} = {}) {
    return `<!doctype html><html><head><meta name="csrf-token" content="token-123"></head><body>
<div class="ticket-page ticket-desk" data-ticket-list>
    <p class="ticket-desk__stamp" data-ticket-stamp aria-live="polite">อัปเดตอัตโนมัติ · ข้อมูล ณ ${stamp}</p>
    <section class="ticket-overview" data-ticket-overview><strong id="total">${total}</strong></section>
    <section class="ticket-queue panel" id="ticket-queue">
        <form data-auto-submit-form><input id="ticket-search" name="q"></form>
        <div class="ticket-queue__results" data-ticket-queue-results>
            <table><tbody>
                ${rows.map((number, index) => `<tr data-ticket-row="${index + 1}">
                    <td><form method="POST" action="/tickets/${index + 1}/status" data-ticket-action-form>
                        <input type="hidden" name="action" value="start">
                        <button type="submit" class="ticket-button ticket-button--primary ticket-actions__button">รับงาน</button>
                    </form></td>
                    <td><a class="ticket-queue__open" href="/tickets/${index + 1}" data-ticket-preview="/tickets/${index + 1}/preview">ดูรายละเอียด ${number}</a></td>
                </tr>`).join('')}
            </tbody></table>
        </div>
    </section>
    <div class="modal fade ticket-preview" id="ticket-preview-modal" data-ticket-preview-modal>
        <div class="modal-dialog"><div class="modal-content" data-ticket-preview-content aria-busy="true"></div></div>
        <template data-ticket-preview-loading><div class="modal-body ticket-preview__state" role="status">กำลังโหลด…</div></template>
    </div>
</div>
</body></html>`;
}

const PREVIEW = `<header class="modal-header"><h2 id="ticket-preview-title">TK-000001</h2></header>
<div class="modal-body">รายละเอียดปัญหา</div>
<footer class="modal-footer"><div class="ticket-actions">
    <form method="POST" action="/tickets/1/participants" data-ticket-action-form>
        <button type="submit" class="ticket-button ticket-button--outline ticket-actions__button" data-ticket-join>เข้าร่วม Ticket</button>
    </form>
</div></footer>`;

const flush = async () => {
    for (let i = 0; i < 10; i++) await new Promise((resolve) => setTimeout(resolve, 0));
};

const response = (body, {status = 200} = {}) => ({
    ok: status >= 200 && status < 300,
    status,
    text: async () => (typeof body === 'string' ? body : JSON.stringify(body)),
    json: async () => (typeof body === 'string' ? JSON.parse(body) : body),
});

function setup({routes = {}} = {}) {
    const dom = mountDom(deskMarkup(), {url: 'http://localhost/tickets?status=new'});
    const requests = [];
    const toasts = [];
    const shown = [];
    const timers = [];

    const fetchImpl = async (url, options = {}) => {
        // fetch ต้องได้ URL เป็นข้อความเสมอ — HTMLInputElement กลายเป็น "/[object HTMLInputElement]" (บั๊กที่เคยเกิดจริง)
        assert.equal(typeof url, 'string', `fetch URL must be a string, got ${url}`);
        const key = `${options.method || 'GET'} ${new URL(url, 'http://localhost').pathname}${new URL(url, 'http://localhost').search}`;
        requests.push({key, options});
        const handler = routes[key];
        if (! handler) throw new Error(`unexpected request ${key}`);

        return handler(options);
    };

    const desk = initTicketDesk(dom.document, {
        fetchImpl,
        bootstrapApi: () => ({Modal: {getOrCreateInstance: (element) => ({show: () => shown.push(element.id)})}}),
        swal: () => ({fire: (options) => toasts.push(options)}),
        setTimer: (callback, wait) => {
            timers.push({callback, wait});

            return timers.length;
        },
        clearTimer: () => {},
    });

    return {...dom, desk, requests, toasts, shown, timers};
}

test('a new ticket syncs the overview, queue and stamp by itself (no refresh button) without touching the search form', async () => {
    const {document, window, requests, timers, cleanup} = setup({
        routes: {'GET /tickets?status=new': () => response(deskMarkup({stamp: '09:05', total: 3, rows: ['TK-000002', 'TK-000001']}))},
    });
    try {
        assert.equal(document.querySelector('[data-ticket-refresh]'), null, 'ไม่มีปุ่มรีเฟรชให้กดเอง');
        assert.equal(timers.at(-1).wait, 15000, 'รอบตรวจสำรองทุก 15 วินาที');
        document.querySelector('#ticket-search').value = 'กำลังพิมพ์';
        const searchBox = document.querySelector('#ticket-search');

        // Ticket ใหม่เข้ามา → realtime-sync ส่ง event หมวด ticket → คิวโหลดใหม่เอง
        document.dispatchEvent(new window.CustomEvent('smartgoal:realtime-notification', {detail: {category: 'ticket', type: 'ticket_created'}}));
        await flush();

        assert.deepEqual(requests.map((request) => request.key), ['GET /tickets?status=new']);
        assert.equal(document.querySelector('#total').textContent, '3');
        assert.match(document.querySelector('[data-ticket-stamp]').textContent, /09:05/);
        assert.equal(document.querySelectorAll('[data-ticket-row]').length, 2);
        assert.equal(document.querySelector('#ticket-search'), searchBox, 'ฟอร์มค้นหาไม่ถูกแทนที่');
        assert.equal(searchBox.value, 'กำลังพิมพ์');
        assert.equal(timers.at(-1).wait, AUTO_REFRESH_INTERVAL, 'ตั้งรอบตรวจถัดไป');
    } finally {
        cleanup();
    }
});

test('a page that is not the desk (for example the login page) leaves the queue alone', () => {
    const dom = mountDom(deskMarkup());
    try {
        const page = dom.document.querySelector('[data-ticket-list]');
        assert.equal(swapDeskRegions(page, '<html><body><form action="/login"></form></body></html>'), false);
        assert.equal(dom.document.querySelectorAll('[data-ticket-row]').length, 1);
    } finally {
        dom.cleanup();
    }
});

test('ticket notifications from realtime-sync and the auto timer reload the queue; other categories do not', async () => {
    const {document, window, requests, timers, cleanup} = setup({
        routes: {'GET /tickets?status=new': () => response(deskMarkup({rows: ['TK-000003', 'TK-000002', 'TK-000001']}))},
    });
    try {
        document.dispatchEvent(new window.CustomEvent('smartgoal:realtime-notification', {detail: {category: 'task'}}));
        await flush();
        assert.equal(requests.length, 0);

        document.dispatchEvent(new window.CustomEvent('smartgoal:realtime-notification', {detail: {category: 'ticket', type: 'ticket_created'}}));
        await flush();
        assert.equal(requests.length, 1);
        assert.equal(document.querySelectorAll('[data-ticket-row]').length, 3);

        timers.at(-1).callback();
        await flush();
        assert.equal(requests.length, 2, 'รอบตรวจอัตโนมัติขอข้อมูลใหม่');
    } finally {
        cleanup();
    }
});

test('the auto refresh waits while focus is inside the queue', async () => {
    const {document, requests, timers, cleanup} = setup({
        routes: {'GET /tickets?status=new': () => response(deskMarkup())},
    });
    try {
        document.querySelector('.ticket-queue__open').focus();
        timers.at(-1).callback();
        await flush();
        assert.equal(requests.length, 0);
    } finally {
        cleanup();
    }
});

test('"ดูรายละเอียด" opens the preview modal and loads its content; modified clicks keep the link', async () => {
    const {document, window, requests, shown, cleanup} = setup({
        routes: {'GET /tickets/1/preview': () => response(PREVIEW)},
    });
    try {
        const link = document.querySelector('[data-ticket-preview]');
        const ctrlClick = new window.MouseEvent('click', {bubbles: true, cancelable: true, ctrlKey: true});
        link.dispatchEvent(ctrlClick);
        assert.equal(ctrlClick.defaultPrevented, false, 'Ctrl+คลิก เปิดแท็บใหม่ตามปกติ');
        assert.equal(shown.length, 0);

        const plain = new window.MouseEvent('click', {bubbles: true, cancelable: true});
        link.dispatchEvent(plain);
        assert.equal(plain.defaultPrevented, true);
        assert.deepEqual(shown, ['ticket-preview-modal']);
        assert.match(document.querySelector('[data-ticket-preview-content]').textContent, /กำลังโหลด/);

        await flush();
        assert.deepEqual(requests.map((request) => request.key), ['GET /tickets/1/preview']);
        const content = document.querySelector('[data-ticket-preview-content]');
        assert.equal(content.querySelector('#ticket-preview-title').textContent, 'TK-000001');
        assert.equal(content.getAttribute('aria-busy'), 'false');

        // ปิด modal → โฟกัสกลับไปที่ลิงก์ที่เปิด
        document.querySelector('[data-ticket-preview-modal]').dispatchEvent(new window.Event('hidden.bs.modal'));
        assert.equal(document.activeElement, link);
    } finally {
        cleanup();
    }
});

test('a failed preview shows an error inside the modal instead of leaving it loading', async () => {
    const {document, cleanup} = setup({routes: {'GET /tickets/1/preview': () => response('', {status: 500})}});
    try {
        click(document.querySelector('[data-ticket-preview]'));
        await flush();
        const state = document.querySelector('[data-ticket-preview-content] .ticket-preview__state');
        assert.match(state.textContent, /โหลดรายละเอียดไม่สำเร็จ/);
        assert.ok(state.classList.contains('is-error'));
    } finally {
        cleanup();
    }
});

test('claiming from a row posts over AJAX with the CSRF token, shows the server message and reloads the queue', async () => {
    const {document, window, requests, toasts, cleanup} = setup({
        routes: {
            'POST /tickets/1/status': () => response({ok: true, message: 'รับงานแล้ว — สถานะปัจจุบัน: กำลังดำเนินการ', status: 'active'}),
            'GET /tickets?status=new': () => response(deskMarkup({rows: ['TK-000001']})),
        },
    });
    try {
        const form = document.querySelector('[data-ticket-action-form]');
        // เบราว์เซอร์จริง: <input name="action"> บัง form.action (jsdom ไม่จำลองพฤติกรรมนี้ จึงจำลองเอง)
        Object.defineProperty(form, 'action', {get: () => form.querySelector('[name="action"]')});
        const submit = new window.Event('submit', {bubbles: true, cancelable: true});
        form.dispatchEvent(submit);
        assert.equal(submit.defaultPrevented, true, 'ไม่ออกจากหน้า');
        assert.equal(form.querySelector('button').disabled, true, 'กันกดซ้ำระหว่างส่ง');

        // กดซ้ำระหว่างรอ — ไม่ส่งรอบที่สอง
        form.dispatchEvent(new window.Event('submit', {bubbles: true, cancelable: true}));
        await flush();

        const posts = requests.filter((request) => request.key.startsWith('POST'));
        assert.equal(posts.length, 1);
        assert.equal(posts[0].options.headers.Accept, 'application/json');
        assert.equal(posts[0].options.headers['X-CSRF-TOKEN'], 'token-123');
        assert.equal(posts[0].options.body.get('action'), 'start');
        assert.deepEqual(toasts.map((toast) => [toast.icon, toast.title]), [['success', 'รับงานแล้ว — สถานะปัจจุบัน: กำลังดำเนินการ']]);
        assert.ok(requests.some((request) => request.key === 'GET /tickets?status=new'), 'โหลดคิวใหม่หลังรับงาน');
    } finally {
        cleanup();
    }
});

test('a conflict (409) shows the server message and still reloads the latest queue', async () => {
    const {document, window, requests, toasts, cleanup} = setup({
        routes: {
            'POST /tickets/1/status': () => response({ok: false, message: 'มีเจ้าหน้าที่คนอื่นเปลี่ยนสถานะไปก่อนแล้ว'}, {status: 409}),
            'GET /tickets?status=new': () => response(deskMarkup()),
        },
    });
    try {
        document.querySelector('[data-ticket-action-form]').dispatchEvent(new window.Event('submit', {bubbles: true, cancelable: true}));
        await flush();
        // ถูกปฏิเสธ → SweetAlert ที่ต้องกดรับทราบ ไม่ใช่ toast ที่หายเอง
        assert.equal(toasts.length, 1);
        assert.equal(toasts[0].toast, undefined);
        assert.equal(toasts[0].icon, 'warning');
        assert.match(toasts[0].text, /ไปก่อนแล้ว/);
        assert.ok(requests.some((request) => request.key === 'GET /tickets?status=new'));
    } finally {
        cleanup();
    }
});

test('joining from the modal reloads both the modal and the queue', async () => {
    const {document, window, requests, cleanup} = setup({
        routes: {
            'GET /tickets/1/preview': () => response(PREVIEW),
            'POST /tickets/1/participants': () => response({ok: true, message: 'เข้าร่วม Ticket แล้ว'}),
            'GET /tickets?status=new': () => response(deskMarkup()),
        },
    });
    try {
        click(document.querySelector('[data-ticket-preview]'));
        await flush();
        document.querySelector('[data-ticket-preview-content] [data-ticket-action-form]')
            .dispatchEvent(new window.Event('submit', {bubbles: true, cancelable: true}));
        await flush();

        assert.deepEqual(requests.map((request) => request.key), [
            'GET /tickets/1/preview', 'POST /tickets/1/participants', 'GET /tickets?status=new', 'GET /tickets/1/preview',
        ]);
    } finally {
        cleanup();
    }
});

test('initializing twice does not bind listeners twice, and pages without the queue are skipped', () => {
    const {document, desk, cleanup} = setup();
    try {
        assert.ok(desk);
        assert.equal(initTicketDesk(document, {fetchImpl: async () => response('')}), null);
    } finally {
        cleanup();
    }

    const landing = mountDom('<!doctype html><html><body><div data-ticket-list></div></body></html>');
    try {
        assert.equal(initTicketDesk(landing.document, {fetchImpl: async () => response('')}), null);
    } finally {
        landing.cleanup();
    }
});

test('the Blade views carry every hook desk.js uses, with the requested button classes', () => {
    const index = read('resources/views/tickets/index.blade.php');
    const queue = read('resources/views/tickets/components/queue.blade.php');
    const claim = read('resources/views/tickets/components/claim-actions.blade.php');
    const modal = read('resources/views/tickets/components/preview-modal.blade.php');
    const overview = read('resources/views/tickets/components/overview.blade.php');

    // ไม่มีปุ่มรีเฟรช — เวลาข้อมูลชุดเดียวกับรายการของหัวหน้าแผนก (components/desk-stamp) และ desk.js แทนที่เอง
    const stamp = read('resources/views/tickets/components/desk-stamp.blade.php');
    for (const page of [index, read('resources/views/tickets/department.blade.php')]) {
        assert.doesNotMatch(page, /data-ticket-refresh|ticket-desk__actions/);
        assert.match(page, /@include\('tickets\.components\.desk-stamp'\)/);
    }
    assert.match(stamp, /data-ticket-stamp aria-live="polite"/);
    assert.match(stamp, /อัปเดตอัตโนมัติ/);
    assert.doesNotMatch(read('resources/js/pages/tickets/desk.js'), /data-ticket-refresh/);
    assert.match(index, /@include\('tickets\.components\.preview-modal'\)/);
    assert.doesNotMatch(index, /data-ticket-refresh-hint/);
    assert.match(overview, /data-ticket-overview/);
    assert.match(queue, /data-ticket-queue-results/);
    assert.match(queue, /class="ticket-queue__open"[^>]*\s+data-ticket-preview="\{\{ route\('tickets\.preview', \$ticket\) \}\}"/);
    assert.match(queue, /@include\('tickets\.components\.claim-actions', \['compact' => true\]\)/);
    // แถวของคิว: ปุ่มไอคอน · หน้ารายละเอียด: ปุ่มข้อความ — รับงานสีเขียวทั้งสองแบบ ไม่มี primary
    assert.match(claim, /class="ticket-button ticket-button--success ticket-button--icon ticket-actions__button"/);
    assert.match(claim, /class="ticket-button ticket-button--outline ticket-button--icon ticket-actions__button" data-ticket-join/);
    assert.match(claim, /class="ticket-button ticket-button--success ticket-actions__button"/);
    assert.doesNotMatch(claim, /ticket-button--primary/);
    // modal ไม่มีปุ่มรับงาน
    assert.match(read('resources/views/tickets/components/preview.blade.php'), /@include\('tickets\.components\.claim-actions', \['canStart' => false\]\)/);
    // ห้ามอ่าน URL จาก form.action (ถูก <input name="action"> บัง)
    assert.doesNotMatch(read('resources/js/pages/tickets/desk.js').replace(/\/\/.*$/gm, ''), /form\.action\b/);
    assert.match(claim, /class="ticket-button ticket-button--outline ticket-actions__button" data-ticket-join/);
    for (const hook of ['data-ticket-preview-modal', 'data-ticket-preview-content', 'data-ticket-preview-loading', 'modal-dialog-scrollable']) {
        assert.ok(modal.includes(hook), hook);
    }

    // ปุ่มรับงาน/เข้าร่วมมีที่มาเดียว — การ์ดการดำเนินการของหน้ารายละเอียดก็ใช้ component เดียวกัน
    const actions = read('resources/views/tickets/components/actions.blade.php');
    assert.match(actions, /@include\('tickets\.components\.claim-actions'/);
    assert.doesNotMatch(actions, /Ticket::ACTION_START/);
});
