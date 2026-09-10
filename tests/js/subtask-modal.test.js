import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom, click, pressKey} from './helpers/dom.js';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/**
 * markup ที่ตรงกับ reports/components/subtask-cell.blade.php และ subtask-modal.blade.php
 * ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย test จึงจะยังสะท้อนของจริง
 */
function reportMarkup(rows) {
    const cells = rows.map((row, index) => `
        <tr>
            <td>${index + 1}</td>
            <td>${row.project}</td>
            <td>${row.topic}</td>
            <td class="report-subtask-cell">${row.subtasks.length ? `
                <button type="button" class="report-subtask-btn" data-subtask-open
                    data-subtask-task="${row.topic}"
                    data-subtask-project="${row.project}"
                    data-subtask-names='${JSON.stringify(row.subtasks)}'>
                    <span>${row.subtasks.length} รายการ</span>
                </button>` : '<span class="report-subtask-none">—</span>'}
            </td>
        </tr>`).join('');

    return `
        <table><tbody>${cells}</tbody></table>
        <div class="subtask-modal" role="dialog" aria-modal="true" data-subtask-modal hidden>
            <div class="subtask-modal__panel">
                <header class="subtask-modal__header">
                    <div>
                        <p data-subtask-modal-project></p>
                        <h2 data-subtask-modal-task></h2>
                    </div>
                    <button type="button" data-subtask-modal-close aria-label="ปิด"></button>
                </header>
                <div class="subtask-modal__body">
                    <p data-subtask-modal-count></p>
                    <ol data-subtask-modal-list></ol>
                </div>
            </div>
        </div>`;
}

const DEFAULT_ROWS = [
    {project: 'Terigram', topic: 'จัดการรับ PMS', subtasks: Array.from({length: 7}, (_, i) => `จัดการทุกอย่างให้เสร็จให้ทันเวลาครับ ${i + 1}`)},
    {project: 'wwww', topic: 'www', subtasks: []},
];

async function mountReport(t, rows = DEFAULT_ROWS) {
    const env = mountDom();
    t.after(env.cleanup);

    // โหลดโมดูลก่อนวาง markup เพราะไฟล์ผูกตัวเองกับ document ตอน evaluate
    const [{initSubtaskModal}, {modalStack}] = await Promise.all([
        import('../../resources/js/components/subtask-modal.js'),
        import('../../resources/js/components/modal-stack.js'),
    ]);

    env.document.body.innerHTML = reportMarkup(rows);
    const stack = modalStack(env.document);
    initSubtaskModal(env.document, stack);

    return {
        env,
        stack,
        document: env.document,
        modal: env.document.querySelector('[data-subtask-modal]'),
        openers: [...env.document.querySelectorAll('[data-subtask-open]')],
    };
}

test('the cell shows a count button instead of every subtask name', async (t) => {
    const {document, openers} = await mountReport(t);

    assert.equal(openers.length, 1);
    assert.match(openers[0].textContent, /7 รายการ/);
    // ชื่องานย่อยต้องไม่ถูกพิมพ์ลงในตาราง มิฉะนั้นแถวจะสูงเหมือนเดิม
    assert.doesNotMatch(document.querySelector('.report-subtask-cell').textContent, /จัดการทุกอย่าง/);
    assert.equal(document.querySelectorAll('.report-subtask-none').length, 1);
});

test('clicking the count opens the modal filled with the task and its subtasks', async (t) => {
    const {modal, openers} = await mountReport(t);

    click(openers[0]);

    assert.equal(modal.hidden, false);
    assert.equal(modal.querySelector('[data-subtask-modal-project]').textContent, 'Terigram');
    assert.equal(modal.querySelector('[data-subtask-modal-task]').textContent, 'จัดการรับ PMS');
    assert.match(modal.querySelector('[data-subtask-modal-count]').textContent, /7 รายการ/);
    assert.deepEqual(
        [...modal.querySelectorAll('[data-subtask-modal-list] li')].map((item) => item.textContent),
        Array.from({length: 7}, (_, i) => `จัดการทุกอย่างให้เสร็จให้ทันเวลาครับ ${i + 1}`)
    );
});

test('opening another row replaces the contents instead of appending', async (t) => {
    const {modal, openers, stack} = await mountReport(t, [
        {project: 'A', topic: 'งาน A', subtasks: ['a1', 'a2']},
        {project: 'B', topic: 'งาน B', subtasks: ['b1']},
    ]);

    click(openers[0]);
    stack.close(modal);
    click(openers[1]);

    assert.equal(modal.querySelector('[data-subtask-modal-task]').textContent, 'งาน B');
    assert.deepEqual([...modal.querySelectorAll('[data-subtask-modal-list] li')].map((i) => i.textContent), ['b1']);
});

test('modal-stack owns the backdrop, the body lock and focus restoration', async (t) => {
    const {document, modal, openers} = await mountReport(t);

    openers[0].focus();
    click(openers[0]);

    assert.equal(modal.dataset.modalBackdrop, 'on');
    assert.equal(document.body.classList.contains('modal-open'), true);

    click(modal.querySelector('[data-subtask-modal-close]'));

    assert.equal(modal.hidden, true);
    assert.equal(document.body.classList.contains('modal-open'), false);
    assert.equal(document.activeElement, openers[0]);
});

test('escape and a backdrop click both close the modal', async (t) => {
    const {document, modal, openers} = await mountReport(t);

    click(openers[0]);
    pressKey(document, 'Escape');
    assert.equal(modal.hidden, true);

    click(openers[0]);
    assert.equal(modal.hidden, false);
    // คลิกบนตัวกล่อง (ฉากหลัง) ไม่ใช่แผงข้างใน
    click(modal);
    assert.equal(modal.hidden, true);
});

test('clicking inside the panel keeps the modal open', async (t) => {
    const {modal, openers} = await mountReport(t);

    click(openers[0]);
    click(modal.querySelector('[data-subtask-modal-list]'));

    assert.equal(modal.hidden, false);
});

test('initialising twice does not open the modal twice or stack listeners', async (t) => {
    const {env, stack, modal, openers} = await mountReport(t);
    const {initSubtaskModal} = await import('../../resources/js/components/subtask-modal.js');

    assert.equal(initSubtaskModal(env.document, stack), null);

    click(openers[0]);

    assert.equal(stack.stack.length, 1);
    assert.equal(modal.querySelectorAll('[data-subtask-modal-list] li').length, 7);
});

test('broken subtask data renders an empty list instead of throwing', async () => {
    const {readSubtasks} = await import('../../resources/js/components/subtask-modal.js');

    assert.deepEqual(readSubtasks({dataset: {subtaskNames: '["a","b"]'}}), ['a', 'b']);
    assert.deepEqual(readSubtasks({dataset: {subtaskNames: 'not json'}}), []);
    assert.deepEqual(readSubtasks({dataset: {}}), []);
    assert.deepEqual(readSubtasks({dataset: {subtaskNames: '[1,"ok",null]'}}), ['ok']);
});

test('both report pages share one cell partial, one modal and one initialiser', async () => {
    const [my, employee, myJs, employeeJs, contributions, attention] = await Promise.all([
        read('resources/views/reports/my.blade.php'),
        read('resources/views/reports/employee.blade.php'),
        read('resources/js/pages/reports/my.js'),
        read('resources/js/pages/reports/employee.js'),
        read('resources/views/reports/components/personal-team-table.blade.php'),
        read('resources/views/reports/components/personal-attention-table.blade.php'),
    ]);

    for (const view of [my, employee]) {
        assert.match(view, /@include\('reports\.components\.subtask-modal'\)/);
    }

    for (const table of [contributions, attention, employee]) {
        assert.match(table, /@include\('reports\.components\.subtask-cell'\)/);
    }

    for (const source of [myJs, employeeJs]) {
        assert.match(source, /initSubtaskModal\(document\)/);
    }
});

/* modal จริงต้องมี backdrop และ aria-modal ส่วนลำดับชั้นต้องไม่ถูกกำหนดซ้ำในไฟล์นี้ */
test('the modal styling stays out of the stacking decisions', async () => {
    const [css, view, js] = await Promise.all([
        read('resources/css/components/subtask-modal.css'),
        read('resources/views/reports/components/subtask-modal.blade.php'),
        read('resources/js/components/subtask-modal.js'),
    ]);

    // ตัดคอมเมนต์ออกก่อน มิฉะนั้นคำอธิบายที่พูดถึง z-index จะทำให้ตรวจเจอทั้งที่ไม่ได้ประกาศจริง
    const declarations = css.replace(/\/\*[\s\S]*?\*\//g, '');
    const logic = js.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');

    assert.match(view, /aria-modal="true"/);
    assert.match(css, /\[data-modal-backdrop="on"\]::before/);
    assert.doesNotMatch(declarations, /z-index/);
    assert.doesNotMatch(logic, /z-index|modal-open|addEventListener\('keydown'/);
    assert.match(js, /modalstack:dismiss/);
});
