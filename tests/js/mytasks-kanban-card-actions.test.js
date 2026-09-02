import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click} from './helpers/dom.js';

/*
 * บอร์ดใน data-view="table" เคยเปลี่ยนสถานะได้ทางเดียวคือการลากการ์ด
 *
 * ปัญหาจริงที่ผู้ใช้เจอ: บนมือถือ (≤760px) คอลัมน์อื่นถูกซ่อนด้วย is-mobile-selected
 * และ HTML5 drag event ไม่ยิงบน touch — ผู้ใช้จึงเปลี่ยนสถานะจากบอร์ดไม่ได้เลย
 * ส่วนบนเดสก์ท็อป การลากผิดช่องทำให้การ์ดเด้งกลับเงียบ ๆ โดยไม่บอกเหตุผล
 *
 * test ชุดนี้จึงคุมว่า "ขั้นถัดไป" ต้องเป็นปุ่มกดได้จริง และการลากที่ล้มเหลวต้องอธิบาย
 */

let fixtureCount = 0;

const statuses = [
    ['5', 'พักงาน'],
    ['2', 'กำลังทำ'],
    ['3', 'รอตรวจสอบ'],
    ['6', 'ล่าช้า'],
    ['4', 'เสร็จแล้ว'],
];

async function boot(t, {cards, management}) {
    const env = mountDom();
    t.after(env.cleanup);

    const columns = statuses.map(([status]) => {
        const owned = cards.filter((card) => String(card.status) === status);
        const articles = owned.map((card) =>
            `<article class="mytasks-kanban__card" data-kanban-card data-id="${card.id}" data-status="${card.status}" data-priority="2">
                <strong data-kanban-title>งาน ${card.id}</strong>
            </article>`).join('');

        return `<section class="mytasks-kanban__column" data-kanban-column="${status}">
            <header><span>${statuses.find(([value]) => value === status)[1]}</span><b data-kanban-count>0</b></header>
            <div class="mytasks-kanban__cards">${articles}</div>
        </section>`;
    }).join('');

    env.document.body.innerHTML = `
        <div data-workspace data-status-template="/tasks/__ID__/status">
            <section data-kanban>
                <div data-kanban-panel="0">
                    <div>${columns}</div>
                </div>
            </section>
            <div class="notion-toast" data-toast></div>
        </div>
        <script type="application/json" data-task-management-data>${JSON.stringify(management)}</script>`;

    const requests = [];
    env.window.fetch = async (url, options) => {
        const payload = JSON.parse(options.body);
        requests.push({url, payload});
        return {
            ok: true,
            json: async () => ({job_status: payload.job_status}),
        };
    };
    globalThis.fetch = env.window.fetch;

    fixtureCount += 1;
    await import(`../../resources/js/pages/mytasks/table-kanban.js?card-actions=${fixtureCount}`);

    return {
        ...env,
        requests,
        card: (id) => env.document.querySelector(`[data-kanban-card][data-id="${id}"]`),
        badge: (id) => env.document.querySelector(`[data-kanban-card][data-id="${id}"] [data-kanban-next]`),
        column: (status) => env.document.querySelector(`[data-kanban-column="${status}"]`),
        toast: () => env.document.querySelector('[data-toast]'),
    };
}

/** จำลอง drag-and-drop ของเบราว์เซอร์เท่าที่ jsdom รองรับ (ไม่มี DataTransfer จริง) */
function dragCardTo(window, card, column) {
    const transfer = {effectAllowed: '', dropEffect: '', setData() {}, getData: () => card.dataset.id};
    const fire = (target, type) => {
        const event = new window.Event(type, {bubbles: true, cancelable: true});
        event.dataTransfer = transfer;
        target.dispatchEvent(event);
        return event;
    };

    fire(card, 'dragstart');
    fire(column, 'dragover');
    fire(column, 'drop');
}

test('a card that can move forward offers a real button, not just a label', async (t) => {
    // งานที่พักไว้ ผู้ใช้กดกลับมาทำได้เอง — เป็นเส้นทางเดียวที่ใช้ได้บนมือถือ
    const ui = await boot(t, {
        cards: [{id: '11', status: '5'}],
        management: {11: {transitions: {can_edit: true, is_final: false, allowed_statuses: [5, 2, 3]}}},
    });

    const badge = ui.badge('11');
    assert.equal(badge.tagName, 'BUTTON', 'ป้ายขั้นถัดไปต้องกดได้ ไม่ใช่ span');
    assert.equal(badge.dataset.kanbanNextStatus, '2');
    assert.equal(badge.textContent, 'กลับมาทำ');
    // ต้องบอกปลายทางด้วยชื่อคอลัมน์จริง ไม่ใช่เลขสถานะ
    assert.match(badge.getAttribute('aria-label'), /กำลังทำ/);

    click(badge);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(ui.requests.length, 1, 'กดปุ่มแล้วต้องยิง PATCH จริง');
    assert.equal(ui.requests[0].url, '/tasks/11/status');
    assert.equal(ui.requests[0].payload.job_status, 2);
    assert.equal(ui.card('11').dataset.status, '2');
    assert.equal(ui.column('2').contains(ui.card('11')), true, 'การ์ดต้องย้ายไปคอลัมน์ปลายทางจริง');
});

test('a card with no available move stays a plain label so it cannot be clicked', async (t) => {
    // คนทำงานที่ส่งตรวจแล้วต้องรอผู้มอบหมาย ปุ่มที่กดไม่ได้จริงจะยิ่งทำให้สับสน
    const ui = await boot(t, {
        cards: [{id: '12', status: '3'}],
        management: {12: {transitions: {can_edit: true, is_final: false, allowed_statuses: [3]}}},
    });

    const badge = ui.badge('12');
    assert.equal(badge.tagName, 'SPAN');
    assert.equal(badge.textContent, 'รอผู้มอบหมายตรวจสอบ');
    assert.equal(ui.card('12').draggable, false);
    assert.equal(ui.card('12').classList.contains('is-locked'), true);
});

test('dropping a card where it cannot go explains why instead of silently bouncing back', async (t) => {
    const ui = await boot(t, {
        cards: [{id: '13', status: '2'}],
        management: {13: {transitions: {can_edit: true, is_final: false, allowed_statuses: [2, 3]}}},
    });

    dragCardTo(ui.window, ui.card('13'), ui.column('4'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(ui.requests.length, 0, 'สถานะที่ server ไม่อนุญาต ต้องไม่ถูกยิงออกไป');
    assert.equal(ui.card('13').dataset.status, '2', 'การ์ดต้องอยู่ที่เดิม');
    assert.notEqual(ui.toast().textContent.trim(), '', 'ต้องบอกเหตุผล ไม่ใช่เด้งกลับเงียบ ๆ');
    assert.equal(ui.toast().classList.contains('show'), true);
});

test('hovering a card points at its destination column before any drag begins', async (t) => {
    const ui = await boot(t, {
        cards: [{id: '14', status: '2'}],
        management: {14: {transitions: {can_edit: true, is_final: false, allowed_statuses: [2, 3, 5]}}},
    });

    ui.card('14').dispatchEvent(new ui.window.Event('mouseenter', {bubbles: false}));

    assert.equal(ui.column('3').classList.contains('is-drop-suggested'), true, 'ต้องชี้ปลายทางที่แนะนำ');
    assert.equal(ui.column('5').classList.contains('is-drop-suggested'), false, 'ชี้เฉพาะขั้นถัดไป ไม่ใช่ทุกช่องที่ไปได้');

    ui.card('14').dispatchEvent(new ui.window.Event('mouseleave', {bubbles: false}));
    assert.equal(ui.column('3').classList.contains('is-drop-suggested'), false);
});
