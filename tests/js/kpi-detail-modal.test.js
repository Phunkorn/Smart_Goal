import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click, pressKey} from './helpers/dom.js';

/**
 * markup ที่ตรงกับ reports/components/kpi-modal.blade.php และการ์ดใน reports/projects/index.blade.php
 * (การ์ดของ reports/components/operational/kpis.blade.php ใช้ hook ชุดเดียวกัน)
 * ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย test จึงจะยังสะท้อนของจริง
 */
function pageMarkup() {
    const card = (key, label) => `
        <button type="button" class="project-report__kpi" data-project-kpi="${key}"
            data-kpi-open data-kpi-label="${label}" data-kpi-url="/reports/projects/kpis/${key}?month=2026-08" aria-haspopup="dialog">
            <span>${label}</span><strong>3</strong>
        </button>`;

    return `
        <div class="project-report">
            <section class="project-report__kpis">${card('owned', 'งานที่รับผิดชอบ')}${card('cross', 'งานข้ามแผนก')}</section>
            <div class="subtask-modal report-kpi-modal" role="dialog" aria-modal="true" aria-labelledby="reportKpiModalTitle" data-kpi-modal data-kpi-state="idle" hidden>
                <div class="subtask-modal__panel">
                    <header>
                        <h2 id="reportKpiModalTitle" data-kpi-modal-title></h2>
                        <button type="button" data-kpi-modal-close aria-label="ปิด"></button>
                    </header>
                    <div data-kpi-modal-body aria-live="polite" aria-busy="false"></div>
                </div>
                <template data-kpi-modal-loading><div class="report-kpi-detail report-kpi-detail--loading" role="status">กำลังโหลดรายการ…</div></template>
                <template data-kpi-modal-error><div class="report-kpi-detail report-kpi-detail--error" role="alert"><strong>โหลดรายการไม่สำเร็จ</strong><button type="button" data-kpi-retry>ลองอีกครั้ง</button></div></template>
            </div>
        </div>`;
}

/** HTML ชิ้นเดียวกับที่ reports/components/kpi-detail/projects.blade.php ตอบกลับ */
function fragment(key, topics, {page = 1, lastPage = 1} = {}) {
    const pager = lastPage > 1
        ? `<nav><button type="button" data-kpi-page-url="/reports/projects/kpis/${key}?page=${page - 1}" ${page <= 1 ? 'disabled' : ''}>ก่อนหน้า</button>
            <button type="button" data-kpi-page-url="/reports/projects/kpis/${key}?page=${page + 1}" ${page >= lastPage ? 'disabled' : ''}>ถัดไป</button></nav>`
        : '';

    return `<div class="report-kpi-detail" data-kpi-detail data-kpi-key="${key}" data-kpi-total="${topics.length}">
        <ol class="report-kpi-detail__list">${topics.map((topic) => `<li><a class="report-kpi-detail__title" href="/tasks/1">${topic}</a></li>`).join('')}</ol>
        <footer><p tabindex="-1" data-kpi-detail-focus>หน้า ${page}</p>${pager}</footer>
    </div>`;
}

/** fetch ปลอมที่ตอบเมื่อ test สั่ง — พิสูจน์สถานะกำลังโหลดและคำตอบที่มาช้าได้ */
function controlledFetch() {
    const calls = [];
    const fetchImpl = (url, options) => new Promise((resolve, reject) => {
        calls.push({url, options, resolve, reject});
    });
    const respond = (index, html, status = 200) => calls[index].resolve({ok: status >= 200 && status < 300, status, text: async () => html});

    return {fetchImpl, calls, respond};
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

async function mountPage(t) {
    const env = mountDom();
    t.after(env.cleanup);

    const [{initKpiDetailModal}, {modalStack}] = await Promise.all([
        import('../../resources/js/components/kpi-detail-modal.js'),
        import('../../resources/js/components/modal-stack.js'),
    ]);

    env.document.body.innerHTML = pageMarkup();
    const page = env.document.querySelector('.project-report');
    const stack = modalStack(env.document);
    const network = controlledFetch();
    const controller = initKpiDetailModal(page, {stack, fetchImpl: network.fetchImpl});

    return {
        env,
        page,
        stack,
        network,
        controller,
        initKpiDetailModal,
        document: env.document,
        modal: env.document.querySelector('[data-kpi-modal]'),
        body: env.document.querySelector('[data-kpi-modal-body]'),
        cards: [...env.document.querySelectorAll('[data-kpi-open]')],
    };
}

const titles = (body) => [...body.querySelectorAll('.report-kpi-detail__title')].map((node) => node.textContent);

test('clicking a card opens the modal in a loading state, then shows the rows the server returned', async (t) => {
    const {document, modal, body, cards, network} = await mountPage(t);

    cards[1].focus();
    click(cards[1]);

    assert.equal(modal.hidden, false);
    assert.equal(document.body.classList.contains('modal-open'), true);
    assert.equal(document.getElementById('reportKpiModalTitle').textContent, 'งานข้ามแผนก');
    assert.equal(modal.dataset.kpiState, 'loading');
    assert.equal(body.getAttribute('aria-busy'), 'true');
    assert.match(body.textContent, /กำลังโหลดรายการ/);
    // ขอ URL ของการ์ดที่กด และบอก server ว่าเป็นคำขอ HTML ชิ้นเดียว
    assert.equal(network.calls.length, 1);
    assert.equal(network.calls[0].url, '/reports/projects/kpis/cross?month=2026-08');
    assert.equal(network.calls[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
    // โฟกัสอยู่ในกล่อง (ปุ่มปิดเป็นสิ่งเดียวที่โฟกัสได้ระหว่างโหลด)
    assert.equal(document.activeElement, modal.querySelector('[data-kpi-modal-close]'));

    network.respond(0, fragment('cross', ['เซลส์มอบงานให้ไอที', 'งานเซลส์ที่ไอทีไปช่วย']));
    await settle();

    assert.equal(modal.dataset.kpiState, 'ready');
    assert.equal(body.getAttribute('aria-busy'), 'false');
    assert.deepEqual(titles(body), ['เซลส์มอบงานให้ไอที', 'งานเซลส์ที่ไอทีไปช่วย']);
    assert.equal(body.querySelector('[data-kpi-detail]').dataset.kpiTotal, '2');
});

test('paging loads the next page in place and moves focus to the range line', async (t) => {
    const {document, body, cards, network} = await mountPage(t);

    click(cards[0]);
    network.respond(0, fragment('owned', ['งาน 1', 'งาน 2'], {page: 1, lastPage: 2}));
    await settle();

    click(body.querySelector('[data-kpi-page-url$="page=2"]'));
    assert.equal(network.calls[1].url, '/reports/projects/kpis/owned?page=2');
    assert.equal(body.getAttribute('aria-busy'), 'true');

    network.respond(1, fragment('owned', ['งาน 11'], {page: 2, lastPage: 2}));
    await settle();

    assert.deepEqual(titles(body), ['งาน 11']);
    assert.equal(document.activeElement, body.querySelector('[data-kpi-detail-focus]'));

    // ปุ่มที่ปิดใช้งานไม่ยิงคำขอ
    click(body.querySelector('[data-kpi-page-url$="page=3"]'));
    assert.equal(network.calls.length, 2);
});

test('a failed request or a page that is not the fragment shows an error with a working retry', async (t) => {
    const {body, modal, cards, network} = await mountPage(t);

    click(cards[0]);
    network.respond(0, 'Server Error', 500);
    await settle();

    assert.equal(modal.dataset.kpiState, 'error');
    assert.equal(body.getAttribute('aria-busy'), 'false');
    assert.match(body.querySelector('[role="alert"]').textContent, /โหลดรายการไม่สำเร็จ/);

    click(body.querySelector('[data-kpi-retry]'));
    assert.equal(network.calls[1].url, '/reports/projects/kpis/owned?month=2026-08');
    // session หมดอายุ: ได้หน้า login กลับมาแบบ 200 ต้องไม่ถูกยัดลงกล่อง
    network.respond(1, '<!doctype html><html><body><form action="/login"></form></body></html>');
    await settle();
    assert.equal(modal.dataset.kpiState, 'error');
    assert.equal(body.querySelector('form'), null);

    click(body.querySelector('[data-kpi-retry]'));
    network.respond(2, fragment('owned', ['งาน 1']));
    await settle();
    assert.equal(modal.dataset.kpiState, 'ready');
    assert.deepEqual(titles(body), ['งาน 1']);
});

test('an answer that arrives after a newer request is ignored', async (t) => {
    const {body, modal, cards, stack, network} = await mountPage(t);

    click(cards[0]);
    stack.close(modal);
    click(cards[1]);
    network.respond(1, fragment('cross', ['งานข้ามแผนก']));
    await settle();
    network.respond(0, fragment('owned', ['งานที่รับผิดชอบ (มาช้า)']));
    await settle();

    assert.deepEqual(titles(body), ['งานข้ามแผนก']);
    assert.equal(modal.querySelector('[data-kpi-modal-title]').textContent, 'งานข้ามแผนก');
});

test('escape, the close button and the backdrop close it and focus returns to the card', async (t) => {
    const {document, modal, cards} = await mountPage(t);

    cards[0].focus();
    click(cards[0]);
    pressKey(document, 'Escape');
    assert.equal(modal.hidden, true);
    assert.equal(document.body.classList.contains('modal-open'), false);
    assert.equal(document.activeElement, cards[0]);

    cards[1].focus();
    click(cards[1]);
    click(modal.querySelector('[data-kpi-modal-close]'));
    assert.equal(modal.hidden, true);
    assert.equal(document.activeElement, cards[1]);

    click(cards[1]);
    click(modal);
    assert.equal(modal.hidden, true);
});

test('initialising twice adds no second listener and pages without a modal are skipped', async (t) => {
    const {document, page, stack, network, initKpiDetailModal, cards, modal} = await mountPage(t);

    assert.equal(initKpiDetailModal(page, {stack, fetchImpl: network.fetchImpl}), null);
    click(cards[0]);
    assert.equal(network.calls.length, 1);
    assert.equal(stack.stack.length, 1);
    stack.close(modal);

    const detailsPage = document.createElement('div');
    assert.equal(initKpiDetailModal(detailsPage, {stack, fetchImpl: network.fetchImpl}), null);
});

test('both report pages use the one shared modal, script and stylesheet', async () => {
    const {readFileSync} = await import('node:fs');
    const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

    for (const entry of ['resources/js/pages/reports/projects.js', 'resources/js/pages/reports/operational.js']) {
        assert.match(read(entry), /import \{initKpiDetailModal\} from '\.\.\/\.\.\/components\/kpi-detail-modal\.js'/);
        assert.match(read(entry), /initKpiDetailModal\(page\)/);
    }
    for (const css of ['resources/css/pages/report-projects.css', 'resources/css/pages/report-operational.css']) {
        assert.match(read(css), /@import '\.\.\/components\/report-kpi-modal\.css';/);
    }
    for (const view of ['resources/views/reports/projects/index.blade.php', 'resources/views/reports/operational.blade.php', 'resources/views/reports/operational/team.blade.php']) {
        assert.equal(read(view).match(/@include\('reports\.components\.kpi-modal'/g)?.length, 1, view);
    }
    // การ์ดของทั้งสองรายงานเป็นปุ่ม ไม่ใช่ article ที่คลิกไม่ได้ด้วยคีย์บอร์ด
    assert.match(read('resources/views/reports/components/operational/kpis.blade.php'), /<button type="button" class="operational-kpi [^>]*\s+data-kpi-open/);
    assert.doesNotMatch(read('resources/views/reports/components/operational/kpis.blade.php'), /<article/);
    // กล่องไม่ประกอบ HTML จากข้อความเอง — ใส่เฉพาะ fragment ที่ Blade escape แล้วผ่าน <template>
    assert.doesNotMatch(read('resources/js/components/kpi-detail-modal.js'), /insertAdjacentHTML|outerHTML|\.innerHTML\s*\+=|document\.write/);
});
