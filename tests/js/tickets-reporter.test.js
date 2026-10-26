import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {initReporterPage, swapReporterRegions} from '../../resources/js/pages/tickets/reporter.js';
import {initTicketDesk} from '../../resources/js/pages/tickets/desk.js';
import {mountDom} from './helpers/dom.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

/** hook ชุดเดียวกับ tickets/landing + components/reporter-card + readonly-preview */
function reporterMarkup({state = 'waiting', number = 'TK-000001', openOnLoad = false, history = null} = {}) {
    return `<!doctype html><html><body>
<div class="ticket-page ticket-landing" data-ticket-reporter>
    <div class="ticket-reporter" data-ticket-reporter-region>
        <section class="ticket-reporter__card" data-state="${state}">
            <h1 id="ticket-reporter-title">${state}</h1>
            <dd data-ticket-number>${number}</dd>
        </section>
    </div>
    <div class="modal fade ticket-preview" id="ticket-reporter-modal" data-ticket-reporter-modal ${openOnLoad ? 'data-open-on-load' : ''}>
        <div class="modal-content"><h2 data-ticket-readonly-title>${number}</h2></div>
    </div>
    ${history === null ? '' : `<div class="modal fade ticket-preview" id="ticket-history-modal" data-ticket-reporter-history>
        <div class="modal-content"><ul class="ticket-history"><li class="ticket-history__item">${history}</li></ul></div>
    </div>`}
</div>
</body></html>`;
}

const flush = async () => {
    for (let i = 0; i < 10; i++) await new Promise((resolve) => setTimeout(resolve, 0));
};

function setup(markup, {html = null} = {}) {
    const dom = mountDom(markup, {url: 'http://localhost/tickets?ticket=7'});
    const shown = [];
    const requests = [];
    const replaced = [];
    let reloads = 0;
    const location = {
        get href() { return dom.window.location.href; },
        reload: () => { reloads += 1; },
    };
    const history = {state: null, replaceState: (state, title, url) => replaced.push(url)};

    const page = initReporterPage(dom.document, {
        bootstrapApi: () => ({Modal: {getOrCreateInstance: (element) => ({show: () => shown.push(element.id)})}}),
        fetchImpl: async (url) => {
            requests.push(url);

            return {ok: true, text: async () => html};
        },
        history,
        location,
    });

    return {...dom, page, shown, requests, replaced, reloads: () => reloads};
}

test('a deep link opens the read-only modal on load and closing it removes ?ticket from the address', () => {
    const {document, window, shown, replaced, cleanup} = setup(reporterMarkup({openOnLoad: true}));
    try {
        assert.deepEqual(shown, ['ticket-reporter-modal']);

        document.getElementById('ticket-reporter-modal').dispatchEvent(new window.Event('hidden.bs.modal', {bubbles: true}));
        assert.deepEqual(replaced, ['/tickets']);
    } finally {
        cleanup();
    }
});

test('without a deep link nothing opens by itself', () => {
    const {shown, cleanup} = setup(reporterMarkup());
    try {
        assert.deepEqual(shown, []);
    } finally {
        cleanup();
    }
});

test('a ticket notification reloads the card from the server without ?ticket and swaps it in place', async () => {
    const next = reporterMarkup({state: 'active'});
    const {document, window, requests, reloads, cleanup} = setup(reporterMarkup(), {html: next});
    try {
        document.dispatchEvent(new window.CustomEvent('smartgoal:realtime-notification', {detail: {category: 'task'}}));
        await flush();
        assert.equal(requests.length, 0, 'หมวดอื่นไม่โหลด');

        document.dispatchEvent(new window.CustomEvent('smartgoal:realtime-notification', {detail: {category: 'ticket'}}));
        await flush();
        assert.deepEqual(requests, ['http://localhost/tickets']);
        assert.equal(document.querySelector('[data-state]').dataset.state, 'active');
        assert.equal(reloads(), 0);
    } finally {
        cleanup();
    }
});

test('moving to or from the "report" state reloads the page so the submit form is bound properly', async () => {
    const closed = reporterMarkup({state: 'report'});
    const {document, window, reloads, cleanup} = setup(reporterMarkup(), {html: closed});
    try {
        document.dispatchEvent(new window.CustomEvent('smartgoal:realtime-notification', {detail: {category: 'ticket'}}));
        await flush();
        assert.equal(reloads(), 1);
        assert.equal(document.querySelector('[data-state]').dataset.state, 'waiting', 'ไม่แทนที่บางส่วนเมื่อต้องโหลดทั้งหน้า');
    } finally {
        cleanup();
    }
});

test('a refresh waits while the modal is open and runs after it closes', async () => {
    const {document, window, requests, cleanup} = setup(reporterMarkup(), {html: reporterMarkup({state: 'active'})});
    try {
        const modal = document.getElementById('ticket-reporter-modal');
        modal.classList.add('show');
        document.dispatchEvent(new window.CustomEvent('smartgoal:realtime-notification', {detail: {category: 'ticket'}}));
        await flush();
        assert.equal(requests.length, 0);

        modal.classList.remove('show');
        modal.dispatchEvent(new window.Event('hidden.bs.modal', {bubbles: true}));
        await flush();
        assert.equal(requests.length, 1);
        assert.equal(document.querySelector('[data-state]').dataset.state, 'active');
    } finally {
        cleanup();
    }
});

test('the history modal is replaced with the new list when a ticket notification refreshes the page', async () => {
    const {document, window, requests, cleanup} = setup(
        reporterMarkup({history: 'TK-000001 รอเจ้าหน้าที่รับงาน'}),
        {html: reporterMarkup({state: 'active', history: 'TK-000001 เจ้าหน้าที่รับงานแล้ว'})},
    );
    try {
        document.dispatchEvent(new window.CustomEvent('smartgoal:realtime-notification', {detail: {category: 'ticket'}}));
        await flush();
        assert.equal(requests.length, 1);
        assert.equal(document.querySelectorAll('#ticket-history-modal').length, 1, 'ไม่เกิด modal ซ้ำ');
        assert.match(document.querySelector('#ticket-history-modal').textContent, /เจ้าหน้าที่รับงานแล้ว/);
    } finally {
        cleanup();
    }
});

test('a refresh waits while the history modal is open, and adds the history modal once the first ticket exists', async () => {
    const waiting = setup(reporterMarkup({history: 'TK-000001'}), {html: reporterMarkup({state: 'active', history: 'TK-000001 ปิดแล้ว'})});
    try {
        const modal = waiting.document.getElementById('ticket-history-modal');
        modal.classList.add('show');
        waiting.document.dispatchEvent(new waiting.window.CustomEvent('smartgoal:realtime-notification', {detail: {category: 'ticket'}}));
        await flush();
        assert.equal(waiting.requests.length, 0);

        modal.classList.remove('show');
        modal.dispatchEvent(new waiting.window.Event('hidden.bs.modal', {bubbles: true}));
        await flush();
        assert.equal(waiting.requests.length, 1);
        assert.match(waiting.document.querySelector('#ticket-history-modal').textContent, /ปิดแล้ว/);
    } finally {
        waiting.cleanup();
    }

    const first = setup(reporterMarkup(), {html: reporterMarkup({state: 'active', history: 'TK-000001'})});
    try {
        assert.equal(first.document.querySelector('#ticket-history-modal'), null);
        first.document.dispatchEvent(new first.window.CustomEvent('smartgoal:realtime-notification', {detail: {category: 'ticket'}}));
        await flush();
        assert.ok(first.document.querySelector('[data-ticket-reporter] > #ticket-history-modal'));
    } finally {
        first.cleanup();
    }
});

test('coming back with the Back button (bfcache) reloads instead of showing an old card', () => {
    const {window, reloads, cleanup} = setup(reporterMarkup());
    try {
        window.dispatchEvent(Object.assign(new window.Event('pageshow'), {persisted: false}));
        assert.equal(reloads(), 0);
        window.dispatchEvent(Object.assign(new window.Event('pageshow'), {persisted: true}));
        assert.equal(reloads(), 1);
    } finally {
        cleanup();
    }
});

test('a non-reporter page (e.g. login after the session expired) is never swapped in', () => {
    const dom = mountDom(reporterMarkup());
    try {
        const page = dom.document.querySelector('[data-ticket-reporter]');
        assert.equal(swapReporterRegions(page, '<html><body><form action="/login"></form></body></html>'), false);
        assert.equal(dom.document.querySelector('[data-state]').dataset.state, 'waiting');
    } finally {
        dom.cleanup();
    }
});

test('initialising twice binds once, and pages without the reporter card are skipped', () => {
    const {document, page, cleanup} = setup(reporterMarkup());
    try {
        assert.ok(page);
        assert.equal(initReporterPage(document), null);
    } finally {
        cleanup();
    }
    const other = mountDom('<!doctype html><html><body><div data-ticket-list></div></body></html>');
    try {
        assert.equal(initReporterPage(other.document), null);
    } finally {
        other.cleanup();
    }
});

test('the department list opens the deep-linked ticket in the read-only preview on load', async () => {
    const dom = mountDom(`<!doctype html><html><body>
<div data-ticket-list data-ticket-open-preview="/tickets/5/preview">
    <div data-ticket-queue-results></div>
    <div class="modal" id="ticket-preview-modal" data-ticket-preview-modal>
        <div class="modal-content" data-ticket-preview-content></div>
        <template data-ticket-preview-loading><p>กำลังโหลด…</p></template>
    </div>
</div></body></html>`);
    try {
        const shown = [];
        const requests = [];
        initTicketDesk(dom.document, {
            fetchImpl: async (url) => {
                requests.push(url);

                return {ok: true, text: async () => '<h2 id="ticket-preview-title">TK-000005</h2>'};
            },
            bootstrapApi: () => ({Modal: {getOrCreateInstance: (element) => ({show: () => shown.push(element.id)})}}),
            setTimer: () => 0,
            clearTimer: () => {},
        });
        await flush();
        assert.deepEqual(shown, ['ticket-preview-modal']);
        assert.deepEqual(requests, ['/tickets/5/preview']);
        assert.match(dom.document.querySelector('[data-ticket-preview-content]').textContent, /TK-000005/);
    } finally {
        dom.cleanup();
    }
});

test('the Blade views carry every hook reporter.js relies on, with no forms or actions in the read-only modal', () => {
    const landing = read('resources/views/tickets/landing.blade.php');
    const card = read('resources/views/tickets/components/reporter-card.blade.php');
    const readonly = read('resources/views/tickets/components/readonly-preview.blade.php');
    const history = read('resources/views/tickets/components/reporter-history.blade.php');

    for (const hook of ['data-ticket-reporter', 'data-ticket-reporter-modal', 'data-open-on-load', 'modal-dialog-scrollable', 'modal-fullscreen-sm-down']) {
        assert.ok(landing.includes(hook), hook);
    }
    for (const hook of ['data-ticket-reporter-region', 'data-state=', 'data-bs-target="#ticket-reporter-modal"', 'alt=""', 'width="{{ $state[\'size\'][0] }}"']) {
        assert.ok(card.includes(hook), hook);
    }
    // ปุ่มประวัติอยู่ในกลุ่มปุ่มของการ์ด (ไม่มีแถวใต้การ์ด) ชี้ไป modal ประวัติ ซึ่งอยู่ใน data-ticket-reporter เพื่อให้ถูกแทนที่ตามเรียลไทม์
    assert.match(card, /ticket-reporter__actions[\s\S]*ticket-reporter__history[\s\S]*data-bs-target="#ticket-history-modal"[\s\S]*<\/section>/);
    assert.doesNotMatch(card.replace(/\{\{--[\s\S]*?--\}\}/g, ''), /ticket-reporter__last|lastClosed/);
    assert.ok(landing.includes("tickets.components.reporter-history"));
    for (const hook of ['id="ticket-history-modal"', 'data-ticket-reporter-history', 'modal-dialog-scrollable', 'modal-fullscreen-sm-down', 'aria-labelledby="ticket-history-title"']) {
        assert.ok(history.includes(hook), hook);
    }
    assert.doesNotMatch(history.replace(/\{\{--[\s\S]*?--\}\}/g, ''), /<form|name="action"|data-ticket-join/);
    const markup = readonly.replace(/\{\{--[\s\S]*?--\}\}/g, '');
    assert.doesNotMatch(markup, /<form|name="action"|data-ticket-join|data-ticket-action-form|ticket-note|claim-actions/);
    assert.match(read('resources/js/pages/tickets/index.js'), /initReporterPage\(document\)/);
    assert.doesNotMatch(read('resources/js/pages/tickets/reporter.js').replace(/\/\*[\s\S]*?\*\//g, ''), /setInterval|localStorage|\b(alert|confirm|prompt)\(/);
});
