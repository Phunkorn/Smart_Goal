import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click, pressKey} from './helpers/dom.js';
import {createModalStack} from '../../resources/js/components/modal-stack.js';
import {initPlanCalendar} from '../../resources/js/pages/daily-logs/plan-calendar.js';

test('เลือกวันแล้วเห็นทุกความรับผิดชอบ แม้วัน/หมวดเดียวกัน และแสดงข้อความอย่างปลอดภัย', () => {
    const entries = {
        '2026-09-15': [
            {title: '<img src=x onerror=alert(1)>', kind: 'routine', category: 'ตรวจเช็ก', owner: 'คนแรก', owner_initial: 'ค', avatar_url: '/media/profile/1', time: '08:00', status: 'planned', status_label: 'วางแผนไว้', status_tone: 'gray'},
            {title: 'ติดตั้งอุปกรณ์', kind: 'field', category: 'ตรวจเช็ก', owner: 'คนที่สอง', owner_initial: 'ส', avatar_url: null, time: '10:00', status: 'done', status_label: 'พบปัญหา', status_tone: 'red"><img'},
        ],
    };
    const dom = mountDom(`<!doctype html><html><body><div data-daily-log><section data-plan-calendar>
      <h3 data-plan-selected-title></h3><button type="button" data-plan-day="2026-09-15" aria-pressed="false">15</button>
      <div data-plan-selected-items></div><script type="application/json" data-plan-entries>${JSON.stringify(entries)}</script>
    </section></div></body></html>`);
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        initPlanCalendar({root});
        click(root.querySelector('[data-plan-day]'));
        assert.equal(root.querySelectorAll('.daily-plan__item').length, 2);
        assert.equal(root.querySelector('[data-plan-day]').getAttribute('aria-pressed'), 'true');
        assert.equal(root.querySelectorAll('.daily-plan__item img').length, 1);
        assert.equal(root.querySelector('.daily-plan__item img').getAttribute('src'), '/media/profile/1');
        assert.equal(root.querySelector('.daily-plan__item strong').textContent.trim(), '<img src=x onerror=alert(1)>');
        assert.equal(root.querySelectorAll('.daily-plan__item-owner')[1].textContent, 'สคนที่สอง');
        // สถานะย้ายจากท้ายข้อความรายละเอียดมาเป็นป้ายของตัวเอง ให้คนในแผนกเห็นว่างานเริ่ม/เสร็จ/พบปัญหาหรือยัง
        const statuses = [...root.querySelectorAll('.daily-plan__item-status')];
        assert.deepEqual(statuses.map((node) => node.textContent), ['วางแผนไว้', 'พบปัญหา']);
        assert.ok(statuses[0].classList.contains('daily-plan__item-status--gray'));
        // โทนที่ไม่ใช่คำล้วนต้องไม่ถูกต่อเข้า class
        assert.equal(statuses[1].className, 'daily-plan__item-status daily-plan__item-status--gray');
        assert.ok(root.querySelector('[data-plan-selected-title]').textContent.includes('2 รายการ'));
    } finally { dom.cleanup(); }
});

/*
 * กดวันแล้วเปิดกล่องรายละเอียดของวันนั้น — เห็นว่าใครทำอะไร สถานะถึงไหน
 * รายการใต้ปฏิทินยังอยู่ด้วย (ผู้ใช้เลือกให้เก็บไว้ทั้งสองที่)
 */
const calendarWithModal = (entries) => mountDom(`<!doctype html><html><body><div data-daily-log><section data-plan-calendar>
  <h3 data-plan-selected-title></h3>
  <button type="button" data-plan-day="2026-09-16" aria-pressed="false">16</button>
  <button type="button" data-plan-day="2026-09-17" aria-pressed="false">17</button>
  <button type="button" data-plan-day="2026-08-31" aria-pressed="false" disabled>31</button>
  <div data-plan-selected-items></div>
  <script type="application/json" data-plan-entries>${JSON.stringify(entries)}</script>
  <div class="log-modal" role="dialog" aria-modal="true" data-plan-day-modal hidden>
    <div class="log-modal__panel">
      <h2 data-plan-day-modal-title></h2><p data-plan-day-modal-summary></p>
      <button type="button" data-plan-day-modal-close>ปิด</button>
      <div data-plan-day-modal-items></div>
    </div>
  </div>
</section></div></body></html>`);

const dayEntries = {
    '2026-09-16': [
        {title: 'ตรวจเช็กคอมพิวเตอร์', kind: 'routine', category: 'IT Support', owner_id: 1, owner: 'สมชาย', owner_initial: 'ส', time: '08:30', status_label: 'พบปัญหา', status_tone: 'red'},
        {title: 'สำรองข้อมูล', kind: 'routine', owner_id: 1, owner: 'สมชาย', owner_initial: 'ส', time: '16:00', status_label: 'เสร็จแล้ว', status_tone: 'teal'},
        {title: 'ส่งเครื่องสาขาบางนา', kind: 'field', owner_id: 2, owner: 'สมหญิง', owner_initial: 'ส', time: '10:00', status_label: 'เสร็จแล้ว', status_tone: 'teal'},
    ],
};

test('กดวันในปฏิทินแล้วเปิดกล่องรายละเอียด เห็นใครทำอะไรและสถานะ พร้อมรายการใต้ปฏิทิน', () => {
    const dom = calendarWithModal(dayEntries);
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        const stack = createModalStack(dom.document);
        initPlanCalendar({root, stack});
        const day = root.querySelector('[data-plan-day="2026-09-16"]');
        const modal = root.querySelector('[data-plan-day-modal]');

        click(day);

        assert.equal(modal.hidden, false, 'กล่องต้องเปิด');
        assert.equal(dom.document.body.classList.contains('modal-open'), true);
        assert.ok(root.querySelector('[data-plan-day-modal-title]').textContent.includes('งานวันที่'));
        assert.equal(root.querySelector('[data-plan-day-modal-summary]').textContent, '3 รายการ · 2 คน · พบปัญหา 1 · เสร็จแล้ว 2');

        const rows = [...modal.querySelectorAll('.daily-plan__item')];
        assert.deepEqual(rows.map((row) => row.querySelector('strong').textContent), ['ตรวจเช็กคอมพิวเตอร์', 'สำรองข้อมูล', 'ส่งเครื่องสาขาบางนา']);
        assert.deepEqual(rows.map((row) => row.querySelector('.daily-plan__item-status').textContent), ['พบปัญหา', 'เสร็จแล้ว', 'เสร็จแล้ว']);
        assert.equal(rows[2].querySelector('.daily-plan__item-owner').textContent, 'สสมหญิง');

        // รายการใต้ปฏิทินยังแสดงวันเดียวกัน
        assert.equal(root.querySelectorAll('[data-plan-selected-items] .daily-plan__item').length, 3);
        assert.equal(day.getAttribute('aria-pressed'), 'true');
    } finally { dom.cleanup(); }
});

test('กล่องรายละเอียดปิดด้วยปุ่มปิด ฉากหลัง และ Escape แล้วคืนโฟกัสให้วันที่กด โดยไม่ผูก listener ซ้ำ', () => {
    const dom = calendarWithModal(dayEntries);
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        initPlanCalendar({root, stack: createModalStack(dom.document)});
        assert.equal(initPlanCalendar({root}), null, 'เรียกซ้ำต้องไม่ผูกซ้ำ');
        const day = root.querySelector('[data-plan-day="2026-09-16"]');
        const modal = root.querySelector('[data-plan-day-modal]');

        click(day);
        click(modal.querySelector('[data-plan-day-modal-close]'));
        assert.equal(modal.hidden, true);
        assert.equal(dom.document.activeElement, day, 'โฟกัสต้องกลับไปที่วันที่กด');
        assert.equal(dom.document.body.classList.contains('modal-open'), false);

        click(day);
        click(modal);
        assert.equal(modal.hidden, true, 'คลิกฉากหลังต้องปิด');

        for (let round = 0; round < 3; round += 1) {
            click(day);
            pressKey(dom.document, 'Escape');
            assert.equal(modal.hidden, true, 'Escape ต้องปิด');
        }

        click(day);
        assert.equal(modal.querySelectorAll('.daily-plan__item').length, 3, 'เปิดซ้ำต้องไม่มีแถวซ้อน');
    } finally { dom.cleanup(); }
});

test('วันที่ไม่มีงานเปิดกล่องพร้อมข้อความว่าง และวันนอกเดือน (disabled) ไม่เปิดกล่อง', () => {
    const dom = calendarWithModal(dayEntries);
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        initPlanCalendar({root, stack: createModalStack(dom.document)});
        const modal = root.querySelector('[data-plan-day-modal]');

        click(root.querySelector('[data-plan-day="2026-08-31"]'));
        assert.equal(modal.hidden, true);

        click(root.querySelector('[data-plan-day="2026-09-17"]'));
        assert.equal(modal.hidden, false);
        assert.equal(modal.querySelector('.daily-plan__empty').textContent, 'ไม่มีงานที่ลงไว้ในวันนี้');
        assert.equal(root.querySelector('[data-plan-day-modal-summary]').textContent, '0 รายการ · 0 คน');
    } finally { dom.cleanup(); }
});
