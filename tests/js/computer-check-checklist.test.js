import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {mountDom, click, pressKey} from './helpers/dom.js';
import {initChecklistPanel, requiresNote, rollUp} from '../../resources/js/pages/computer-check/checklist.js';

const DEVICES = ['keyboard_mouse', 'speaker', 'microphone', 'internet', 'computer'];

const design = {
    devices: Object.fromEntries(DEVICES.map((key) => [key, {label: key, letter: key[0].toUpperCase()}])),
    deviceStatuses: {
        pending: {label: 'ยังไม่ได้ตรวจ'},
        normal: {label: 'ปกติ'},
        issue: {label: 'มีปัญหา'},
    },
};

/* ---------- กติกา (ฟังก์ชันบริสุทธิ์) ---------- */

test('"ยังไม่ได้ตรวจ" ต้องไม่ถูกนับเป็น "ตรวจแล้ว" และไม่ถูกนับเป็น "มีปัญหา"', () => {
    assert.equal(rollUp({}, DEVICES), 'pending');
    assert.equal(rollUp({keyboard_mouse: 'normal'}, DEVICES), 'partial');
    assert.equal(
        rollUp(Object.fromEntries(DEVICES.map((key) => [key, 'normal'])), DEVICES),
        'normal'
    );
    // อุปกรณ์เดียวมีปัญหา ทั้งเครื่องถือว่ามีปัญหา แม้ที่เหลือยังไม่ได้ตรวจ
    assert.equal(rollUp({microphone: 'issue'}, DEVICES), 'issue');
});

test('ต้องกรอกรายละเอียดเมื่อมีอุปกรณ์ใดเป็น "มีปัญหา"', () => {
    assert.equal(requiresNote({microphone: 'normal'}), false);
    assert.equal(requiresNote({microphone: 'issue'}), true);
    assert.equal(requiresNote({}), false);
});

/* ---------- พฤติกรรมจริงบน DOM ---------- */

const tristate = (device) => `
<fieldset data-cc-tristate="${device}">
  ${['pending', 'normal', 'issue'].map((status) => `
    <label><input type="radio" name="${device}" value="${status}"${status === 'pending' ? ' checked' : ''}><span>${status}</span></label>
  `).join('')}
</fieldset>`;

const panelMarkup = `
<div data-computer-check>
  <aside class="cc-panel" data-cc-panel hidden>
    <div class="cc-panel__scrim" data-cc-panel-scrim></div>
    <div class="cc-panel__surface">
      <h2 data-cc-panel-title></h2>
      <p data-cc-panel-subtitle></p>
      <button type="button" data-cc-panel-close>ปิด</button>
      <form data-cc-result-form data-cc-panel-mode="single" hidden>
        ${DEVICES.map(tristate).join('')}
        <button type="button" data-cc-mark-all-normal>ปกติทั้งหมด</button>
        <div data-cc-note-field hidden><textarea data-cc-note></textarea></div>
        <p data-cc-panel-meta hidden></p>
        <p data-cc-panel-error hidden></p>
        <button type="submit" data-cc-panel-save>บันทึก</button>
      </form>
      <div data-cc-panel-mode="multi" hidden>
        <p data-cc-bulk-count></p>
        <ul data-cc-bulk-list></ul>
        <button type="button" data-cc-bulk-normal>ตั้งเป็นปกติ</button>
        <p data-cc-bulk-error hidden></p>
      </div>
      <div data-cc-panel-mode="empty"></div>
    </div>
  </aside>
</div>`;

const mountPanel = (handlers = {}) => {
    const dom = mountDom(`<!doctype html><html><body><button id="trigger"></button>${panelMarkup}</body></html>`);
    const panel = initChecklistPanel({
        root: dom.document.querySelector('[data-computer-check]'),
        design,
        ...handlers,
    });

    return {dom, panel};
};

const station = (overrides = {}) => ({
    id: 7,
    code: 'A-04',
    zone: 'A',
    devices: Object.fromEntries(DEVICES.map((key) => [key, 'pending'])),
    note: null,
    ...overrides,
});

test('เปิดแผงแล้วติ๊กค่าเดิมของเครื่องนั้นกลับมาให้ครบ', () => {
    const {dom, panel} = mountPanel();

    try {
        panel.openForStation(station({
            devices: {...station().devices, microphone: 'issue', speaker: 'normal'},
            note: 'ไม่มีเสียง',
            checked_by: 'สมชาย',
            checked_at: '09:15',
        }));

        const panelNode = dom.document.querySelector('[data-cc-panel]');

        assert.equal(panelNode.hidden, false);
        assert.equal(dom.document.querySelector('[data-cc-panel-title]').textContent, 'เครื่องที่ A-04');
        assert.equal(dom.document.querySelector('input[name="microphone"][value="issue"]').checked, true);
        assert.equal(dom.document.querySelector('input[name="speaker"][value="normal"]').checked, true);
        assert.equal(dom.document.querySelector('[data-cc-note]').value, 'ไม่มีเสียง');
        // ช่องรายละเอียดต้องโผล่ทันทีเพราะมีอุปกรณ์ที่มีปัญหาอยู่แล้ว
        assert.equal(dom.document.querySelector('[data-cc-note-field]').hidden, false);
        assert.match(dom.document.querySelector('[data-cc-panel-meta]').textContent, /สมชาย/);
    } finally {
        dom.cleanup();
    }
});

test('เลือก "มีปัญหา" แล้วช่องรายละเอียดโผล่ขึ้นมา และหายไปเมื่อกลับเป็นปกติ', () => {
    const {dom, panel} = mountPanel();

    try {
        panel.openForStation(station());

        const noteField = dom.document.querySelector('[data-cc-note-field]');
        assert.equal(noteField.hidden, true);

        const issue = dom.document.querySelector('input[name="internet"][value="issue"]');
        issue.checked = true;
        issue.dispatchEvent(new dom.window.Event('change', {bubbles: true}));

        assert.equal(noteField.hidden, false);
        assert.equal(dom.document.querySelector('[data-cc-note]').required, true);

        const normal = dom.document.querySelector('input[name="internet"][value="normal"]');
        normal.checked = true;
        normal.dispatchEvent(new dom.window.Event('change', {bubbles: true}));

        assert.equal(noteField.hidden, true);
    } finally {
        dom.cleanup();
    }
});

test('ปุ่ม "ปกติทั้งหมด" ตั้งครบทั้งห้าอุปกรณ์ในคลิกเดียว', () => {
    const {dom, panel} = mountPanel();

    try {
        panel.openForStation(station({devices: {...station().devices, microphone: 'issue'}, note: 'เสีย'}));

        click(dom.document.querySelector('[data-cc-mark-all-normal]'));

        DEVICES.forEach((device) => {
            assert.equal(
                dom.document.querySelector(`input[name="${device}"][value="normal"]`).checked,
                true,
                `${device} ต้องถูกตั้งเป็นปกติ`
            );
        });

        // ไม่มีอุปกรณ์ที่มีปัญหาแล้ว ช่องรายละเอียดจึงต้องถูกซ่อน
        assert.equal(dom.document.querySelector('[data-cc-note-field]').hidden, true);
    } finally {
        dom.cleanup();
    }
});

test('กดบันทึกแล้วส่งสถานะของทั้งห้าอุปกรณ์พร้อมรายละเอียดปัญหา', async () => {
    const sent = [];
    const {dom, panel} = mountPanel({onSave: (payload) => { sent.push(payload); }});

    try {
        panel.openForStation(station());

        const issue = dom.document.querySelector('input[name="microphone"][value="issue"]');
        issue.checked = true;
        issue.dispatchEvent(new dom.window.Event('change', {bubbles: true}));
        dom.document.querySelector('[data-cc-note]').value = 'ไม่มีเสียง';

        dom.document.querySelector('[data-cc-result-form]')
            .dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(sent.length, 1);
        assert.equal(sent[0].stationId, 7);
        assert.equal(sent[0].note, 'ไม่มีเสียง');
        assert.equal(sent[0].statuses.microphone, 'issue');
        assert.equal(sent[0].statuses.speaker, 'pending');
        // บันทึกสำเร็จแล้วแผงต้องปิดเอง
        assert.equal(dom.document.querySelector('[data-cc-panel]').hidden, true);
    } finally {
        dom.cleanup();
    }
});

test('บันทึกไม่สำเร็จแล้วแผงยังเปิดอยู่พร้อมข้อความจากเซิร์ฟเวอร์', async () => {
    const {dom, panel} = mountPanel({
        onSave: () => { throw new Error('กรุณาระบุรายละเอียดปัญหาที่พบ'); },
    });

    try {
        panel.openForStation(station());

        dom.document.querySelector('[data-cc-result-form]')
            .dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));

        await new Promise((resolve) => setTimeout(resolve, 0));

        const error = dom.document.querySelector('[data-cc-panel-error]');

        assert.equal(error.hidden, false);
        assert.equal(error.textContent, 'กรุณาระบุรายละเอียดปัญหาที่พบ');
        assert.equal(dom.document.querySelector('[data-cc-panel]').hidden, false);
    } finally {
        dom.cleanup();
    }
});

test('โหมดหลายเครื่องแสดงรายการที่เลือกและมีเฉพาะทางเลือก "ปกติ"', () => {
    const {dom, panel} = mountPanel();

    try {
        panel.openForSelection(['A-01', 'A-02', 'B-03']);

        assert.equal(dom.document.querySelector('[data-cc-panel-mode="multi"]').hidden, false);
        assert.equal(dom.document.querySelector('[data-cc-result-form]').hidden, true);
        assert.match(dom.document.querySelector('[data-cc-bulk-count]').textContent, /3 เครื่อง/);
        assert.equal(dom.document.querySelectorAll('[data-cc-bulk-list] li').length, 3);

        // ต้องไม่มีทางบันทึก "มีปัญหา" แบบหลายเครื่องพร้อมกัน
        assert.equal(dom.document.querySelector('[data-cc-bulk-issue]'), null);
    } finally {
        dom.cleanup();
    }
});

test('ปิดแผงด้วย Escape ปุ่มปิด และการแตะนอกแผง แล้วคืนโฟกัสให้ตัวที่เปิดมา', () => {
    const {dom, panel} = mountPanel();

    try {
        const trigger = dom.document.getElementById('trigger');
        const panelNode = dom.document.querySelector('[data-cc-panel]');

        panel.openForStation(station(), trigger);
        pressKey(panelNode, 'Escape');
        assert.equal(panelNode.hidden, true);
        assert.equal(dom.document.activeElement, trigger);

        panel.openForStation(station(), trigger);
        click(dom.document.querySelector('[data-cc-panel-close]'));
        assert.equal(panelNode.hidden, true);

        panel.openForStation(station(), trigger);
        click(dom.document.querySelector('[data-cc-panel-scrim]'));
        assert.equal(panelNode.hidden, true);
    } finally {
        dom.cleanup();
    }
});

test('เปิดแผงซ้ำหลายรอบไม่ทำให้ listener งอกเพิ่ม', async () => {
    let saves = 0;
    const {dom, panel} = mountPanel({onSave: () => { saves += 1; }});

    try {
        for (let round = 0; round < 3; round += 1) {
            panel.openForStation(station());
            panel.close();
        }

        panel.openForStation(station());
        dom.document.querySelector('[data-cc-result-form]')
            .dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(saves, 1);
    } finally {
        dom.cleanup();
    }
});

/* ---------- แผงเดียวสองพฤติกรรม ---------- */

test('เดสก์ท็อปและมือถือใช้แผงชุดเดียวกัน ต่างกันแค่ CSS', () => {
    const blade = fs.readFileSync(
        'resources/views/daily-logs/computer-check/partials/checklist-panel.blade.php',
        'utf8'
    );
    const responsive = fs.readFileSync('resources/css/pages/computer-check/responsive.css', 'utf8');

    // มีแผงเดียวในมาร์กอัป ไม่ใช่แผงข้างหนึ่งใบกับ bottom sheet อีกใบ
    // (?![-\w]) กันไม่ให้ไปนับ data-cc-panel-title / -close / -mode ซึ่งเป็นคนละ hook
    assert.equal(blade.match(/data-cc-panel(?![-\w])/g).length, 1);
    assert.equal((blade.match(/data-cc-result-form/g) || []).length, 1);

    // bottom sheet เกิดจาก CSS ของจอแคบ ไม่ใช่ markup ชุดที่สอง
    assert.match(responsive, /@media \(max-width: 1023px\)/);
    assert.match(responsive, /\.cc-panel__surface \{[^}]*bottom:0/);
    // ไม่ใช่กล่องเต็มจอ — ต้องยังเห็นผังห้องด้านหลัง
    assert.match(responsive, /max-height:82vh/);
    // เดสก์ท็อปวางแผงไว้คอลัมน์ขวาของผัง
    assert.match(responsive, /@media \(min-width: 1024px\)/);
});

test('แผงตรวจไม่ใช่ modal จึงไม่ไปยุ่งกับ modal-stack หรือล็อกการเลื่อนของหน้า', () => {
    const source = fs.readFileSync('resources/js/pages/computer-check/checklist.js', 'utf8');

    assert.ok(! source.includes('modal-stack'), 'แผงต้องไม่ลงทะเบียนกับ modal-stack');
    assert.ok(! source.includes('modal-open'), 'แผงต้องไม่ล็อกการเลื่อนของทั้งหน้า');

    // ส่วนที่เป็น modal จริงคือกล่องพรีวิวแบบตรวจ ซึ่งต้องใช้ modal-stack
    const reportModal = fs.readFileSync('resources/js/components/inspection-report-modal.js', 'utf8');
    assert.match(reportModal, /modal-stack/);
});
