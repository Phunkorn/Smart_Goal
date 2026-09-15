import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {askCompletion, completionMarkup} from '../../resources/js/pages/daily-logs/completion-dialog.js';

/*
 * กล่องปิดงาน — ทดสอบเส้นทางจริงผ่าน Swal จำลองที่ทำงานแบบเดียวกับของจริง:
 * วาด html, เรียก didOpen, ให้ผู้ใช้กรอก, แล้วเรียก preConfirm ตอนกดบันทึก
 */
const fakeSwal = (dom, act) => ({
    popup: null,
    message: '',
    options: null,
    async fire(options) {
        this.options = options;
        this.popup = dom.document.createElement('div');
        this.popup.innerHTML = options.html;
        dom.document.body.appendChild(this.popup);
        options.didOpen(this.popup);
        act(this.popup, dom);
        const value = options.preConfirm();

        return value === false ? {isConfirmed: false} : {isConfirmed: true, value};
    },
    getPopup() { return this.popup; },
    showValidationMessage(message) { this.message = message; },
});

const choose = (dom, input) => {
    input.checked = true;
    input.dispatchEvent(new dom.window.Event('change', {bubbles: true}));
};

test('ปิดงานตรงเวลาโดยไม่เลือกอะไรเพิ่ม ส่ง outcome=done อย่างเดียว', async () => {
    const dom = mountDom();
    try {
        const swal = fakeSwal(dom, () => {});
        const fields = await askCompletion({swal, title: 'ตรวจเช็กคอม'});

        assert.deepEqual(fields, {outcome: 'done'});
        assert.equal(swal.popup.querySelector('[data-completion-late-reason]'), null, 'ไม่ช้าต้องไม่มีช่องเหตุผล');
        assert.equal(swal.options.titleText, 'ปิดงาน: ตรวจเช็กคอม');
    } finally { dom.cleanup(); }
});

test('เลือกพบปัญหาแล้วช่องรายละเอียดโผล่ และบันทึกไม่ได้จนกว่าจะเขียนรายละเอียด', async () => {
    const dom = mountDom();
    try {
        const swal = fakeSwal(dom, (popup) => {
            choose(dom, popup.querySelector('input[value="issue"]'));
            assert.equal(popup.querySelector('[data-completion-issue]').hidden, false);
        });

        assert.equal(await askCompletion({swal}), null);
        assert.equal(swal.message, 'กรุณาระบุรายละเอียดปัญหาที่พบ');
    } finally { dom.cleanup(); }
});

test('เกินเวลา + พบปัญหา ถามในกล่องเดียว และรองรับเหตุผล "อื่น ๆ" ที่พิมพ์เอง', async () => {
    const dom = mountDom();
    try {
        const swal = fakeSwal(dom, (popup) => {
            choose(dom, popup.querySelector('input[value="issue"]'));
            popup.querySelector('[data-completion-issue-details]').value = '  เครื่อง 3 เปิดไม่ติด  ';
            const select = popup.querySelector('[data-completion-late-reason]');
            const trigger = popup.querySelector('.sg-select__trigger');
            assert.ok(trigger, 'Swal ต้องเปิดใช้ dropdown แบบสไลด์กับช่องเหตุผล');
            trigger.click();
            popup.querySelector('.sg-select__option[data-value="อื่น ๆ"]').click();
            assert.equal(select.value, 'อื่น ๆ');
            assert.equal(popup.querySelector('[data-completion-late-other-field]').hidden, false);
            popup.querySelector('[data-completion-late-other]').value = 'รออะไหล่';
        });

        const fields = await askCompletion({swal, late: true, reasons: ['ระบบขัดข้อง']});

        assert.deepEqual(fields, {outcome: 'issue', issue_details: 'เครื่อง 3 เปิดไม่ติด', late_completion_reason: 'รออะไหล่'});
    } finally { dom.cleanup(); }
});

test('เกินเวลาแต่ไม่เลือกเหตุผล บันทึกไม่ได้', async () => {
    const dom = mountDom();
    try {
        const swal = fakeSwal(dom, () => {});

        assert.equal(await askCompletion({swal, late: true, reasons: ['ระบบขัดข้อง']}), null);
        assert.equal(swal.message, 'กรุณาระบุเหตุผลที่เสร็จเกินเวลา');
    } finally { dom.cleanup(); }
});

test('เหตุผลสำเร็จรูปถูก escape ก่อนใส่ลง html', () => {
    const html = completionMarkup({late: true, reasons: ['<img src=x onerror=alert(1)>']});

    assert.ok(! html.includes('<img'));
    assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));
});
