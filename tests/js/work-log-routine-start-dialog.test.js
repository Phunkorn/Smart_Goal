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

/*
 * ผู้ร่วมงานที่ตอบว่า "มาทำด้วย" เริ่มพร้อมกันทันที — วันค้างของเขาแสดงใต้ชื่อให้คนกดตอบแทน
 * ถ้าเลือกว่า "ไม่มา" วันค้างของเขาซ่อนและไม่ถูกส่ง เพราะเขาจะตอบเองเมื่อกลับมา
 */
const withMemberBacklog = {
    backlog: [],
    attendance: [{id: 7, name: 'Anutida', backlog: [
        {date: '2026-09-07', date_label: '7 กันยายน 2569', type: 'absent', status_label: 'ไม่มา', absent_marked_by: 'Aum'},
    ]}],
};

test('วันค้างของผู้ร่วมงานแสดงใต้ชื่อเขา และซ่อนเมื่อเลือกว่าไม่มา', async () => {
    const dom = mountDom();
    try {
        const swal = fakeSwal(dom, (popup) => {
            const person = popup.querySelector('[data-attendance-person]');
            const memberBacklog = person.querySelector('[data-member-backlog]');
            assert.ok(memberBacklog, 'วันค้างต้องอยู่ในกล่องของคนนั้น');
            assert.equal(memberBacklog.hidden, false, 'ค่าเริ่มต้นคือมาทำด้วย จึงต้องเห็นวันค้าง');
            assert.ok(memberBacklog.textContent.includes('7 กันยายน 2569'));
            assert.ok(memberBacklog.textContent.includes('Aum ระบุว่าไม่มา'));
            assert.equal(popup.querySelectorAll('[data-backlog-day]').length, 0, 'ต้องไม่ปนกับวันค้างของคนกด');

            const absent = person.querySelector('input[value="absent"]');
            absent.checked = true;
            absent.dispatchEvent(new dom.window.Event('change', {bubbles: true}));
            assert.equal(memberBacklog.hidden, true);
        });

        assert.deepEqual(await askStartRequirements({swal, requirements: withMemberBacklog, reasons}), {'attendance[7]': 'absent'});
    } finally { dom.cleanup(); }
});

test('ตอบว่ามาทำด้วยแต่ยังไม่ตอบวันค้างของเขา บันทึกไม่ได้ ตอบครบแล้วส่งแยกตามคน', async () => {
    const dom = mountDom();
    try {
        const incomplete = fakeSwal(dom, () => {});
        assert.equal(await askStartRequirements({swal: incomplete, requirements: withMemberBacklog, reasons}), null);
        assert.equal(incomplete.message, 'กรุณาระบุเหตุผลวันค้างของผู้ร่วมงานที่มาทำด้วยให้ครบ');

        const complete = fakeSwal(dom, (popup) => {
            choose(popup.querySelector('[data-member-backlog-day] [data-backlog-reason]'), 'ลางาน');
        });
        assert.deepEqual(await askStartRequirements({swal: complete, requirements: withMemberBacklog, reasons}), {
            'attendance[7]': 'present',
            'member_backlog_reasons[7][2026-09-07]': 'ลางาน',
        });
    } finally { dom.cleanup(); }
});
