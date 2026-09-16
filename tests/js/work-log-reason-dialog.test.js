import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {askReason, reasonMarkup} from '../../resources/js/pages/daily-logs/reason-dialog.js';

/*
 * กล่องถามเหตุผล — ทดสอบสองชั้น
 *
 * 1. Swal จำลอง: วาด html, เรียก didOpen, ให้ผู้ใช้กรอก แล้วเรียก preConfirm ตอนกดยืนยัน
 * 2. SweetAlert2 ตัวจริงใน jsdom: พิสูจน์เส้นทางจริงตั้งแต่คลิกดร็อปดาวน์จนได้ค่ากลับ
 *    ชั้นนี้จำเป็นเพราะบั๊กเดิมอยู่ที่สัญญาระหว่างเรากับตัวไลบรารีเอง ไม่ใช่ตรรกะของเรา
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

test('เลือกเหตุผลสำเร็จรูปแล้วกดยืนยัน ได้ข้อความเหตุผลกลับมา', async () => {
    const dom = mountDom();
    try {
        const swal = fakeSwal(dom, (popup) => {
            popup.querySelector('.sg-select__trigger').click();
            popup.querySelector('.sg-select__option[data-value="รถติด"]').click();
            assert.equal(popup.querySelector('[data-reason-other-field]').hidden, true);
        });

        assert.equal(await askReason({swal, title: 'เหตุผลที่เริ่มงานช้า', reasons: ['รถติด', 'ประชุมยาว']}), 'รถติด');
        assert.equal(swal.options.titleText, 'เหตุผลที่เริ่มงานช้า');
    } finally { dom.cleanup(); }
});

test('เลือก "อื่น ๆ" ช่องพิมพ์เองโผล่ในกล่องเดิม และส่งข้อความที่พิมพ์', async () => {
    const dom = mountDom();
    try {
        const swal = fakeSwal(dom, (popup) => {
            popup.querySelector('.sg-select__trigger').click();
            popup.querySelector('.sg-select__option[data-value="อื่น ๆ"]').click();
            assert.equal(popup.querySelector('[data-reason-other-field]').hidden, false);
            popup.querySelector('[data-reason-other]').value = '  รอเจ้าของห้องเปิดประตู  ';
        });

        assert.equal(await askReason({swal, reasons: ['รถติด']}), 'รอเจ้าของห้องเปิดประตู');
    } finally { dom.cleanup(); }
});

test('เลือก "อื่น ๆ" แต่ไม่พิมพ์อะไร ยืนยันไม่ผ่าน', async () => {
    const dom = mountDom();
    try {
        const swal = fakeSwal(dom, (popup) => {
            popup.querySelector('.sg-select__trigger').click();
            popup.querySelector('.sg-select__option[data-value="อื่น ๆ"]').click();
        });

        assert.equal(await askReason({swal, reasons: ['รถติด']}), null);
        assert.equal(swal.message, 'กรุณาระบุเหตุผล');
    } finally { dom.cleanup(); }
});

test('ไม่เลือกอะไรเลย ยืนยันไม่ผ่านและบอกให้เลือกเหตุผล', async () => {
    const dom = mountDom();
    try {
        const swal = fakeSwal(dom, () => {});

        assert.equal(await askReason({swal, reasons: ['รถติด']}), null);
        assert.equal(swal.message, 'กรุณาเลือกเหตุผล');
    } finally { dom.cleanup(); }
});

test('เหตุผลสำเร็จรูปถูก escape ก่อนใส่ลง html', () => {
    const html = reasonMarkup({reasons: ['<img src=x onerror=alert(1)>']});

    assert.ok(! html.includes('<img'));
    assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));
});

/*
 * เส้นทางจริงกับ SweetAlert2 ตัวจริง
 *
 * บั๊กเดิม: กล่องนี้เคยใช้ input:'select' ของ Swal แล้วเอาดร็อปดาวน์ของโปรเจกต์ไปครอบ
 * Swal หา <select> ด้วย `.swal2-popup > .swal2-select` (ลูกโดยตรง) แต่ enhanceSelect()
 * ย้าย <select> เข้าไปใน .sg-select ทำให้ Swal.getInput() คืน null และขึ้น
 * "กรุณาเลือกเหตุผล" ค้างแม้เลือกไปแล้ว เทสต์นี้จึงขับตั้งแต่คลิกจนได้ค่ากลับ
 */
const browserGlobals = [
    'DOMParser', 'MouseEvent', 'KeyboardEvent', 'FocusEvent', 'InputEvent', 'Node', 'NodeFilter',
    'DocumentFragment', 'MutationObserver', 'HTMLVideoElement', 'HTMLAudioElement', 'HTMLInputElement',
    'HTMLSelectElement', 'HTMLTextAreaElement', 'HTMLButtonElement', 'Image',
];

test('กดยืนยันบน SweetAlert2 ตัวจริงหลังเลือกเหตุผล ส่งค่าออกได้จริง', async () => {
    const dom = mountDom();
    const restore = new Map(browserGlobals.map((key) => [key, globalThis[key]]));
    const restoreComputedStyle = globalThis.getComputedStyle;

    try {
        browserGlobals.forEach((key) => { globalThis[key] = dom.window[key]; });
        globalThis.getComputedStyle = dom.window.getComputedStyle.bind(dom.window);

        const swal = (await import('sweetalert2')).default.mixin({
            showClass: {popup: '', backdrop: '', icon: ''},
            hideClass: {popup: '', backdrop: '', icon: ''},
        });

        const answer = askReason({swal, title: 'เหตุผลที่เริ่มงานช้า', reasons: ['รถติด', 'ประชุมยาว']});

        // didOpen ของ Swal ทำงานหลัง render จึงต้องปล่อยให้ event loop เดินก่อนกดอะไร
        await new Promise((resolve) => setTimeout(resolve, 50));

        const popup = dom.document.querySelector('.swal2-popup');
        assert.ok(popup, 'Swal ต้องเปิด popup จริง');

        const trigger = popup.querySelector('.sg-select__trigger');
        assert.ok(trigger, 'ช่องเหตุผลต้องถูกผูกดร็อปดาวน์ของโปรเจกต์');
        trigger.click();
        popup.querySelector('.sg-select__option[data-value="ประชุมยาว"]').click();

        popup.querySelector('.swal2-confirm').click();
        assert.equal(await answer, 'ประชุมยาว');
        assert.equal(dom.document.querySelector('.swal2-validation-message')?.offsetParent ?? null, null);

        // Swal ปิดกล่องแบบ async หลัง promise คืนค่า ต้องรอให้จบก่อนคืน global เดิม
        // ไม่งั้นโค้ดที่ยังทำงานค้างจะไปอ่าน global ที่ถูกถอดแล้วแล้วโยน error นอก test
        await new Promise((resolve) => setTimeout(resolve, 100));
    } finally {
        restore.forEach((value, key) => { globalThis[key] = value; });
        globalThis.getComputedStyle = restoreComputedStyle;
        dom.cleanup();
    }
});
