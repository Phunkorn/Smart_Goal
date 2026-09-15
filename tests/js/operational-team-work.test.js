import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createModalStack} from '../../resources/js/components/modal-stack.js';
import {initTeamWorkModal} from '../../resources/js/pages/reports/operational-team-work.js';
import {mountDom} from './helpers/dom.js';

/*
 * กล่อง "ดูงาน" ของภาพรวมทีม — ทดสอบจากการคลิกปุ่มในแถวจนเห็นรายการงานในกล่อง
 */
const mountPage = (t) => {
    const env = mountDom();
    t.after(env.cleanup);

    env.document.body.innerHTML = `
        <div class="report-operational">
            <table><tbody><tr><td>
                <button type="button" data-team-work-open="7" data-team-work-name="ธนากร" data-team-work-department="IT" data-team-work-count="2">ดูงาน</button>
                <template data-team-work-detail="7">
                    <li><strong>ตรวจ Server เช้า</strong><span>งานประจำ · ตรวจเช็ค</span></li>
                    <li><strong>ตรวจ UPS เช้า</strong><span>งานประจำ · อุปกรณ์</span></li>
                </template>
            </td></tr></tbody></table>
            <div data-team-work-modal hidden role="dialog" aria-modal="true">
                <section>
                    <p data-team-work-modal-department></p>
                    <h2 data-team-work-modal-name></h2>
                    <button type="button" data-team-work-close>ปิด</button>
                    <p data-team-work-modal-count></p>
                    <ul data-team-work-modal-list></ul>
                </section>
            </div>
        </div>`;

    const root = env.document.querySelector('.report-operational');
    const stack = createModalStack(env.document);

    return {
        ...env,
        root,
        stack,
        opener: root.querySelector('[data-team-work-open]'),
        modal: root.querySelector('[data-team-work-modal]'),
        click: (element) => element.dispatchEvent(new env.window.MouseEvent('click', {bubbles: true})),
    };
};

test('clicking ดูงาน opens the shared modal with that person’s work, type and category', (t) => {
    const ui = mountPage(t);
    initTeamWorkModal(ui.root, ui.stack);

    ui.click(ui.opener);

    assert.equal(ui.modal.hidden, false);
    assert.equal(ui.modal.querySelector('[data-team-work-modal-name]').textContent, 'ธนากร');
    assert.equal(ui.modal.querySelector('[data-team-work-modal-department]').textContent, 'IT');
    assert.equal(ui.modal.querySelector('[data-team-work-modal-count]').textContent, 'งานของวันนี้ 2 รายการ');

    const items = [...ui.modal.querySelectorAll('[data-team-work-modal-list] li')];
    assert.deepEqual(items.map((item) => item.querySelector('strong').textContent), ['ตรวจ Server เช้า', 'ตรวจ UPS เช้า']);
    assert.match(items[0].textContent, /งานประจำ · ตรวจเช็ค/);
    assert.equal(ui.document.body.classList.contains('modal-open'), true, 'modal-stack เป็นผู้ล็อกการเลื่อน');
});

test('the close button and the backdrop close the modal and return focus to the opener', (t) => {
    const ui = mountPage(t);
    initTeamWorkModal(ui.root, ui.stack);

    ui.opener.focus();
    ui.click(ui.opener);
    ui.click(ui.modal.querySelector('[data-team-work-close]'));
    assert.equal(ui.modal.hidden, true);
    assert.equal(ui.document.activeElement, ui.opener);
    assert.equal(ui.document.body.classList.contains('modal-open'), false);

    ui.click(ui.opener);
    ui.click(ui.modal);
    assert.equal(ui.modal.hidden, true, 'คลิกฉากหลังต้องปิดกล่อง');
});

test('Escape closes the modal through modal-stack', (t) => {
    const ui = mountPage(t);
    initTeamWorkModal(ui.root, ui.stack);

    ui.click(ui.opener);
    ui.document.dispatchEvent(new ui.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));

    assert.equal(ui.modal.hidden, true);
});

test('opening twice does not duplicate the list and init twice does not stack listeners', (t) => {
    const ui = mountPage(t);
    initTeamWorkModal(ui.root, ui.stack);
    assert.equal(initTeamWorkModal(ui.root, ui.stack), null);

    ui.click(ui.opener);
    ui.click(ui.modal.querySelector('[data-team-work-close]'));
    ui.click(ui.opener);

    assert.equal(ui.modal.querySelectorAll('[data-team-work-modal-list] li').length, 2);
});

test('the team page puts the charts above the people card', async () => {
    const team = await readFile(new URL('../../resources/views/reports/operational/team.blade.php', import.meta.url), 'utf8');

    const charts = team.indexOf("@include('reports.components.operational.charts'");
    const people = team.indexOf('data-operational-team-table');

    assert.ok(charts > 0 && people > 0 && charts < people, 'แถวกราฟต้องอยู่เหนือการ์ดตารางรายคน');
});
