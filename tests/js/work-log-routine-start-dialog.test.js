import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {askStartRequirements, startRequirementsMarkup} from '../../resources/js/pages/daily-logs/routine-start-dialog.js';

/*
 * กล่องก่อนเริ่มงานประจำ — ทดสอบผ่าน Swal จำลองที่ทำงานแบบของจริง:
 * วาด html, เรียก didOpen, ให้ผู้ใช้กรอก, แล้วเรียก preConfirm ตอนกดบันทึก
 */
const fakeSwal = (dom, act) => ({
    popup: null,
    message: '',
    async fire(options) {
        this.popup = dom.document.createElement('div');
        this.popup.innerHTML = options.html;
        dom.document.body.appendChild(this.popup);
        options.didOpen(this.popup);
        act(this.popup);
        const value = options.preConfirm();

        return value === false ? {isConfirmed: false} : {isConfirmed: true, value};
    },
    getPopup() { return this.popup; },
    showValidationMessage(message) { this.message = message; },
});

const reasons = {missed: ['ลางาน', 'ลืมทำ'], unfinished: ['ลืมกดเสร็จ']};
const requirements = {
    backlog: [
        {date: '2026-09-07', date_label: '7 กันยายน 2569', type: 'not_started', status_label: 'ไม่ได้เริ่ม'},
        {date: '2026-09-08', date_label: '8 กันยายน 2569', type: 'unfinished', status_label: 'เริ่มแล้วไม่กดเสร็จ'},
        {date: '2026-09-09', date_label: '9 กันยายน 2569', type: 'absent', status_label: 'ไม่มา', absent_marked_by: 'สมชาย'},
    ],
    attendance: [{id: 5, name: 'สมหญิง'}, {id: 6, name: 'สมศักดิ์'}],
};

const choose = (select, value) => {
    select.value = value;
    select.dispatchEvent(new select.ownerDocument.defaultView.Event('change', {bubbles: true}));
};

test('ทุกวันที่ค้างอยู่ในกล่องเดียว พร้อมวันที่ พ.ศ. และชุดเหตุผลแยกตามกรณี', () => {
    const dom = mountDom();
    try {
        dom.document.body.innerHTML = startRequirementsMarkup({...requirements, reasons});
        const days = [...dom.document.querySelectorAll('[data-backlog-day]')];

        assert.deepEqual(days.map((day) => day.querySelector('strong').textContent), ['7 กันยายน 2569', '8 กันยายน 2569', '9 กันยายน 2569']);
        assert.deepEqual([...days[0].querySelectorAll('option')].map((o) => o.value), ['', 'ลางาน', 'ลืมทำ', 'อื่น ๆ']);
        assert.deepEqual([...days[1].querySelectorAll('option')].map((o) => o.value), ['', 'ลืมกดเสร็จ', 'อื่น ๆ'], 'เริ่มแล้วไม่กดเสร็จต้องถามคนละชุด');
        assert.ok(days[2].textContent.includes('สมชาย ระบุว่าไม่มา'));
        assert.equal(dom.document.querySelectorAll('[data-attendance-person]').length, 2);
    } finally { dom.cleanup(); }
});

test('ตอบไม่ครบบันทึกไม่ได้ ตอบครบได้ field ที่ส่งให้ endpoint start ทันที รวม "ไม่มา" รายคน', async () => {
    const dom = mountDom();
    try {
        const incomplete = fakeSwal(dom, (popup) => {
            choose(popup.querySelectorAll('[data-backlog-reason]')[0], 'ลางาน');
        });
        assert.equal(await askStartRequirements({swal: incomplete, requirements, reasons}), null);
        assert.equal(incomplete.message, 'กรุณาระบุเหตุผลให้ครบทุกวันที่ค้าง');

        const complete = fakeSwal(dom, (popup) => {
            const selects = popup.querySelectorAll('[data-backlog-reason]');
            choose(selects[0], 'ลางาน');
            choose(selects[1], 'ลืมกดเสร็จ');
            choose(selects[2], 'อื่น ๆ');
            const other = popup.querySelectorAll('[data-backlog-other]')[2];
            assert.equal(other.hidden, false, 'เลือกอื่น ๆ แล้วช่องพิมพ์ต้องโผล่');
            other.value = '  ไปอบรม  ';
            popup.querySelector('input[name="routine-attendance-6"][value="absent"]').checked = true;
        });

        assert.deepEqual(await askStartRequirements({swal: complete, requirements, reasons}), {
            'backlog_reasons[2026-09-07]': 'ลางาน',
            'backlog_reasons[2026-09-08]': 'ลืมกดเสร็จ',
            'backlog_reasons[2026-09-09]': 'ไปอบรม',
            'attendance[5]': 'present',
            'attendance[6]': 'absent',
        });
    } finally { dom.cleanup(); }
});

test('ชื่อและป้ายจาก server ถูก escape ก่อนใส่ลง html', () => {
    const html = startRequirementsMarkup({
        backlog: [{date: '2026-09-07', date_label: '<img src=x onerror=alert(1)>', type: 'not_started', status_label: 'x'}],
        attendance: [{id: 1, name: '<script>alert(1)</script>'}],
        reasons,
    });

    assert.ok(! html.includes('<img'));
    assert.ok(! html.includes('<script>'));
});
