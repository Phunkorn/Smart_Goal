import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {initPeopleModal, fillPeopleModal} from '../../resources/js/pages/reports/people-modal.js';

/*
 * การ์ดพนักงานบนกระดานรายงานปฏิบัติงานกดเปิดรายละเอียดเป็น modal
 *
 * ทดสอบจากการคลิกจริงจนกล่องปรากฏ ไม่ใช่เรียกเฉพาะฟังก์ชันภายใน เพราะจุดที่พังได้
 * คือ delegation, การยกเว้นลิงก์ในการ์ด และการย้ายเนื้อในจาก template
 */

function boot(t, {items = 2} = {}) {
    const env = mountDom();
    t.after(env.cleanup);

    const rows = Array.from({length: items}, (_, index) => `<li>งานที่ ${index + 1}</li>`).join('');

    env.document.body.innerHTML = `
        <div class="report-operational">
            <div class="report-people-cards">
                <article data-people-card data-people-name="ธนากร" data-people-department="IT"
                    data-people-url="/reports/operational?owner=7">
                    <a href="/reports/operational?owner=7" data-person-link>ธนากร</a>
                    <button type="button" data-people-open>ดูรายละเอียด</button>
                    <template data-people-detail><ul data-detail-list>${rows}</ul></template>
                </article>
                <article data-people-card data-people-name="พันธกร" data-people-department="IT"
                    data-people-url="">
                    <button type="button" data-people-open>ดูรายละเอียด</button>
                    <template data-people-detail><p data-detail-empty>ยังไม่ได้บันทึกงานของวันนี้</p></template>
                </article>
            </div>
            <div data-people-modal hidden>
                <div class="subtask-modal__panel">
                    <p data-people-modal-department></p>
                    <h2 data-people-modal-name></h2>
                    <button type="button" data-people-modal-close></button>
                    <div data-people-modal-body></div>
                    <a href="#" data-people-modal-link>ดูรายวัน</a>
                </div>
            </div>
        </div>`;

    const page = env.document.querySelector('.report-operational');
    initPeopleModal(page);

    return {
        ...env,
        page,
        modal: env.document.querySelector('[data-people-modal]'),
        card: (index) => env.document.querySelectorAll('[data-people-card]')[index],
    };
}

test('กดปุ่มในการ์ดแล้วกล่องเปิดพร้อมข้อมูลของคนนั้น', (t) => {
    const ui = boot(t);

    ui.card(0).querySelector('[data-people-open]').click();

    assert.equal(ui.modal.hidden, false, 'กล่องต้องเปิด');
    assert.equal(ui.modal.querySelector('[data-people-modal-name]').textContent, 'ธนากร');
    assert.equal(ui.modal.querySelector('[data-people-modal-department]').textContent, 'IT');

    // เนื้อในต้องถูกย้ายมาจาก template ของการ์ด ไม่ใช่สร้างใหม่ใน JavaScript
    assert.equal(ui.modal.querySelectorAll('[data-detail-list] li').length, 2);
    assert.equal(ui.modal.querySelector('[data-people-modal-link]').getAttribute('href'), '/reports/operational?owner=7');
});

test('กดที่พื้นที่ว่างของการ์ดก็เปิดได้ แต่กดลิงก์ชื่อคนต้องไม่เปิด', (t) => {
    const ui = boot(t);

    ui.card(0).click();
    assert.equal(ui.modal.hidden, false, 'คลิกบนการ์ดต้องเปิดกล่อง');

    ui.modal.querySelector('[data-people-modal-close]').click();
    assert.equal(ui.modal.hidden, true);

    /*
     * ชื่อคนเป็นลิงก์ไปหน้ารายวัน การกดมันต้องพาไปหน้านั้น ไม่ใช่เปิดกล่อง
     * ถ้าดักคลิกทั้งการ์ดโดยไม่ยกเว้นตัวกดอื่น ลิงก์จะใช้งานไม่ได้ทั้งใบ
     */
    ui.card(0).querySelector('[data-person-link]').click();
    assert.equal(ui.modal.hidden, true, 'กดลิงก์ต้องไม่เปิดกล่อง');
});

test('เปลี่ยนการ์ดแล้วเนื้อในต้องเปลี่ยนตาม ไม่ใช่ต่อท้ายของเดิม', (t) => {
    const ui = boot(t);

    ui.card(0).querySelector('[data-people-open]').click();
    ui.card(1).querySelector('[data-people-open]').click();

    assert.equal(ui.modal.querySelector('[data-people-modal-name]').textContent, 'พันธกร');
    assert.equal(ui.modal.querySelectorAll('[data-detail-list] li').length, 0, 'ของการ์ดใบก่อนต้องถูกล้าง');
    assert.ok(ui.modal.querySelector('[data-detail-empty]'));

    // ไม่มีปลายทางก็ต้องไม่เหลือลิงก์ที่กดแล้วไปไหนไม่ได้
    assert.equal(ui.modal.querySelector('[data-people-modal-link]').hidden, true);
});

test('Escape ปิดกล่องผ่าน modal-stack ไม่ใช่ตัวดักคีย์ของตัวเอง', (t) => {
    const ui = boot(t);

    ui.card(0).querySelector('[data-people-open]').click();
    assert.equal(ui.modal.hidden, false);

    ui.document.dispatchEvent(new ui.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));

    assert.equal(ui.modal.hidden, true, 'Escape ต้องปิดกล่อง');
});

test('ผูกซ้ำไม่สร้างตัวฟังชุดที่สอง', (t) => {
    const ui = boot(t);

    assert.equal(initPeopleModal(ui.page), null, 'กล่องที่ผูกแล้วต้องไม่ถูกผูกซ้ำ');
});

test('fillPeopleModal ทำงานได้โดยไม่ต้องเปิดกล่อง', (t) => {
    const ui = boot(t);

    const body = fillPeopleModal(ui.modal, ui.card(0));

    assert.equal(ui.modal.hidden, true, 'การเติมเนื้อในต้องไม่เปิดกล่องเอง');
    assert.equal(body.querySelectorAll('li').length, 2);
});
