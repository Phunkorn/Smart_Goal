import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';

let fixtureCount = 0;

const statuses = [
    ['5', 'พักงาน'],
    ['2', 'กำลังทำ'],
    ['3', 'รอตรวจสอบ'],
    ['6', 'ล่าช้า'],
    ['4', 'เสร็จแล้ว'],
];

async function boot(t) {
    const env = mountDom();
    t.after(env.cleanup);

    const tabs = statuses.map(([status, label], index) => `
        <button type="button" data-kanban-status-tab="${status}"
                class="${index === 0 ? 'is-selected' : ''}"
                aria-selected="${index === 0 ? 'true' : 'false'}">
            <span>${label}</span><b data-kanban-tab-count>0</b>
        </button>`).join('');
    const columns = statuses.map(([status], index) => `
        <section data-kanban-column="${status}" class="${index === 0 ? 'is-mobile-selected' : ''}">
            <header><b data-kanban-count>0</b></header>
            <div class="mytasks-kanban__cards">
                ${status === '5' ? '<article data-kanban-card data-id="1" data-status="5" data-priority="2"></article>' : ''}
                ${status === '2' ? '<article data-kanban-card data-id="2" data-status="2" data-priority="2"></article><article data-kanban-card data-id="3" data-status="2" data-priority="2"></article>' : ''}
            </div>
        </section>`).join('');

    env.document.body.innerHTML = `
        <div data-workspace data-status-template="/tasks/__ID__/status">
            <section data-kanban>
                <div data-kanban-panel="0">
                    <nav data-kanban-status-tabs>${tabs}</nav>
                    <div>${columns}</div>
                </div>
            </section>
        </div>
        <script type="application/json" data-task-management-data>{}</script>`;

    fixtureCount += 1;
    const module = await import(`../../resources/js/pages/mytasks/table-kanban.js?mobile-tabs=${fixtureCount}`);
    return {
        ...env,
        ...module,
        panel: env.document.querySelector('[data-kanban-panel]'),
        tabs: [...env.document.querySelectorAll('[data-kanban-status-tab]')],
        columns: [...env.document.querySelectorAll('[data-kanban-column]')],
    };
}

test('mobile selector keeps all five existing status columns and synchronizes counts', async (t) => {
    const ui = await boot(t);

    assert.equal(ui.tabs.length, 5);
    assert.equal(ui.columns.length, 5);
    assert.deepEqual(ui.tabs.map((tab) => tab.querySelector('b').textContent), ['1', '2', '0', '0', '0']);
    assert.equal(ui.tabs[0].getAttribute('aria-selected'), 'true');
    assert.equal(ui.columns[0].classList.contains('is-mobile-selected'), true);
});

test('clicking a status changes presentation only and leaves every column in the DOM', async (t) => {
    const ui = await boot(t);
    const progressTab = ui.tabs.find((tab) => tab.dataset.kanbanStatusTab === '2');

    progressTab.click();

    assert.equal(ui.panel.dataset.mobileKanbanStatus, '2');
    assert.equal(progressTab.getAttribute('aria-selected'), 'true');
    assert.equal(ui.columns.find((column) => column.dataset.kanbanColumn === '2').classList.contains('is-mobile-selected'), true);
    assert.equal(ui.panel.querySelectorAll('[data-kanban-column]').length, 5);
    assert.equal(ui.panel.querySelectorAll('[data-kanban-card]').length, 3);
});

test('status selector supports arrow, home, and end keyboard navigation', async (t) => {
    const ui = await boot(t);
    const first = ui.tabs[0];

    first.dispatchEvent(new ui.window.KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true}));
    assert.equal(ui.panel.dataset.mobileKanbanStatus, '2');
    assert.equal(ui.document.activeElement, ui.tabs[1]);

    ui.tabs[1].dispatchEvent(new ui.window.KeyboardEvent('keydown', {key: 'End', bubbles: true}));
    assert.equal(ui.panel.dataset.mobileKanbanStatus, '4');

    ui.tabs[4].dispatchEvent(new ui.window.KeyboardEvent('keydown', {key: 'Home', bubbles: true}));
    assert.equal(ui.panel.dataset.mobileKanbanStatus, '5');
});

test('count refresh follows existing cards without issuing a separate task query', async (t) => {
    const ui = await boot(t);
    const pausedColumn = ui.columns.find((column) => column.dataset.kanbanColumn === '5');
    const progressColumn = ui.columns.find((column) => column.dataset.kanbanColumn === '2');

    progressColumn.querySelector('.mytasks-kanban__cards').append(
        pausedColumn.querySelector('[data-kanban-card]')
    );
    ui.refreshMobileKanbanStatusTabs(ui.panel);

    assert.deepEqual(ui.tabs.map((tab) => tab.querySelector('b').textContent), ['0', '3', '0', '0', '0']);
});
