import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click, pressKey} from './helpers/dom.js';

/**
 * markup ที่ตรงกับ reports/projects/details.blade.php และ components/projects/evidence-modal.blade.php
 * ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย test จึงจะยังสะท้อนของจริง
 */
function detailsMarkup() {
    const row = (id, topic, files) => `
        <tr>
            <th>${topic}</th>
            <td><button type="button" class="project-report__chip" data-evidence-open="${id}">2 งานย่อย · เสร็จ 1</button></td>
        </tr>`;
    const template = (id, topic, files) => `
        <template data-evidence-template="${id}">
            <h2 id="projectEvidenceTitle">${topic}</h2>
            <ul class="project-report__files">${files.map((name) => `<li><a href="/media/task-attachments/${id}">${name}</a></li>`).join('')}</ul>
        </template>`;

    return `
        <table><tbody>${row(11, 'งาน A')}${row(12, 'งาน B')}</tbody></table>
        ${template(11, 'งาน A', ['ใบส่งมอบ.pdf', 'หน้างาน.png'])}
        ${template(12, 'งาน B', ['ผังสาย.png'])}
        <div class="subtask-modal" role="dialog" aria-modal="true" data-evidence-modal hidden>
            <div class="subtask-modal__panel">
                <button type="button" data-evidence-modal-close aria-label="ปิด"></button>
                <div data-evidence-modal-content></div>
            </div>
        </div>`;
}

async function mountDetails(t) {
    const env = mountDom();
    t.after(env.cleanup);

    const [{initEvidenceModal}, {modalStack}] = await Promise.all([
        import('../../resources/js/components/evidence-modal.js'),
        import('../../resources/js/components/modal-stack.js'),
    ]);

    env.document.body.innerHTML = detailsMarkup();
    const stack = modalStack(env.document);
    initEvidenceModal(env.document, stack);

    return {
        env,
        stack,
        initEvidenceModal,
        document: env.document,
        modal: env.document.querySelector('[data-evidence-modal]'),
        openers: [...env.document.querySelectorAll('[data-evidence-open]')],
    };
}

const fileNames = (modal) => [...modal.querySelectorAll('[data-evidence-modal-content] a')].map((link) => link.textContent);

test('rows show a chip only — file names stay inside inert templates until opened', async (t) => {
    const {document, modal} = await mountDetails(t);

    assert.doesNotMatch(document.querySelector('table').textContent, /ใบส่งมอบ\.pdf/);
    assert.equal(document.querySelectorAll('a').length, 0, 'template content ต้องไม่ถูกนับเป็นลิงก์ในหน้า');
    assert.equal(modal.hidden, true);
});

test('clicking a chip opens the modal with that task and its files', async (t) => {
    const {modal, openers} = await mountDetails(t);

    click(openers[0]);

    assert.equal(modal.hidden, false);
    assert.equal(modal.querySelector('#projectEvidenceTitle').textContent, 'งาน A');
    assert.deepEqual(fileNames(modal), ['ใบส่งมอบ.pdf', 'หน้างาน.png']);
});

test('opening another row replaces the contents instead of appending', async (t) => {
    const {modal, openers, stack} = await mountDetails(t);

    click(openers[0]);
    stack.close(modal);
    click(openers[1]);

    assert.equal(modal.querySelector('#projectEvidenceTitle').textContent, 'งาน B');
    assert.deepEqual(fileNames(modal), ['ผังสาย.png']);
    assert.equal(modal.querySelectorAll('#projectEvidenceTitle').length, 1);
});

test('close button, backdrop and escape close it and focus returns to the chip', async (t) => {
    const {document, modal, openers} = await mountDetails(t);

    openers[0].focus();
    click(openers[0]);
    assert.equal(document.body.classList.contains('modal-open'), true);
    click(modal.querySelector('[data-evidence-modal-close]'));
    assert.equal(modal.hidden, true);
    assert.equal(document.activeElement, openers[0]);

    click(openers[0]);
    click(modal);
    assert.equal(modal.hidden, true);

    click(openers[1]);
    pressKey(document, 'Escape');
    assert.equal(modal.hidden, true);
    assert.equal(document.body.classList.contains('modal-open'), false);
});

test('initialising twice does not stack listeners and a missing template opens nothing', async (t) => {
    const {document, modal, openers, stack, initEvidenceModal} = await mountDetails(t);

    assert.equal(initEvidenceModal(document, stack), null);
    click(openers[0]);
    assert.equal(stack.stack.length, 1);
    stack.close(modal);

    openers[1].dataset.evidenceOpen = '999';
    click(openers[1]);
    assert.equal(modal.hidden, true);
});
