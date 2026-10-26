import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {initTicketFilterToggle} from '../../resources/js/pages/tickets/index.js';
import {click, mountDom} from './helpers/dom.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

/** markup ที่ตรงกับ tickets/components/queue.blade.php (เฉพาะ hook ที่ JS ใช้) */
const markup = ({open = false} = {}) => `<!doctype html><html><body>
<form data-auto-submit-form>
    <input id="q" type="search" name="q">
    <button type="button" hidden aria-expanded="${open}" aria-controls="panel" data-ticket-filter-toggle>ตัวกรอง</button>
    <div id="panel" data-ticket-filter-panel ${open ? 'data-open' : ''}>
        <select name="location"><option value="">ทุกสาขา</option></select>
    </div>
</form>
</body></html>`;

test('ปุ่มตัวกรองปรากฏเมื่อมี JavaScript และแผงเริ่มแบบพับ', () => {
    const dom = mountDom(markup());
    try {
        const toggle = dom.document.querySelector('[data-ticket-filter-toggle]');
        const panel = dom.document.querySelector('#panel');

        assert.equal(initTicketFilterToggle(dom.document), true);
        assert.equal(toggle.hidden, false);
        assert.equal(panel.hidden, true);
        assert.equal(toggle.getAttribute('aria-expanded'), 'false');
    } finally {
        dom.cleanup();
    }
});

test('คลิกปุ่มเปิดและพับแผง และค้นหายังใช้ได้ตลอดเพราะอยู่นอกแผง', () => {
    const dom = mountDom(markup());
    try {
        const toggle = dom.document.querySelector('[data-ticket-filter-toggle]');
        const panel = dom.document.querySelector('#panel');
        initTicketFilterToggle(dom.document);

        click(toggle);
        assert.equal(panel.hidden, false);
        assert.equal(toggle.getAttribute('aria-expanded'), 'true');

        click(toggle);
        assert.equal(panel.hidden, true);
        assert.equal(toggle.getAttribute('aria-expanded'), 'false');
        assert.equal(panel.contains(dom.document.querySelector('#q')), false);
    } finally {
        dom.cleanup();
    }
});

test('มีตัวกรองสาขา/ขอบเขตใช้อยู่ (data-open) แผงเปิดตั้งแต่ต้น', () => {
    const dom = mountDom(markup({open: true}));
    try {
        initTicketFilterToggle(dom.document);

        assert.equal(dom.document.querySelector('#panel').hidden, false);
        assert.equal(dom.document.querySelector('[data-ticket-filter-toggle]').getAttribute('aria-expanded'), 'true');
    } finally {
        dom.cleanup();
    }
});

test('เรียกซ้ำไม่ผูก listener ซ้ำ — คลิกหนึ่งครั้งสลับสถานะครั้งเดียว', () => {
    const dom = mountDom(markup());
    try {
        const toggle = dom.document.querySelector('[data-ticket-filter-toggle]');
        const panel = dom.document.querySelector('#panel');

        assert.equal(initTicketFilterToggle(dom.document), true);
        assert.equal(initTicketFilterToggle(dom.document), false);

        click(toggle);
        assert.equal(panel.hidden, false);
    } finally {
        dom.cleanup();
    }
});

test('Blade ของคิวมี hook ครบ: ปุ่มซ่อนไว้ก่อน แผงอยู่ในฟอร์มเดียวกับช่องค้นหา', () => {
    const queue = read('resources/views/tickets/components/queue.blade.php');

    const tag = queue.match(/<button[\s\S]*?data-ticket-filter-toggle>/)?.[0];
    assert.ok(tag, 'ต้องมีปุ่ม data-ticket-filter-toggle');
    assert.match(tag, /\shidden\s/, 'ปุ่มซ่อนไว้ก่อน จะแสดงเมื่อ JS ทำงาน');
    assert.match(queue, /data-ticket-filter-panel/);
    // ค้นหา แผง และฟอร์มต้องอยู่ด้วยกัน เพื่อให้ initAutoSubmitFilters (ค้นหา input ภายในฟอร์ม) ยังส่งอัตโนมัติ
    const form = queue.slice(queue.indexOf('<form'), queue.indexOf('</form>'));
    assert.ok(form.includes('name="q"') && form.includes('data-ticket-filter-panel') && form.includes('name="location"'));
});
