import test from 'node:test';
import assert from 'node:assert/strict';

import {mountDom, click, pressKey} from './helpers/dom.js';
import {initTimeline} from '../../resources/js/pages/daily-logs/timeline.js';

/*
 * ไทม์ไลน์ของบันทึกงานประจำวัน — ทดสอบเส้นทางจริงตั้งแต่คลิกจนเห็นผล
 *
 * สองเรื่องที่ต้องคุมเป็นพิเศษตามกติกาของโปรเจกต์:
 * 1. เมนูรายแถวเป็น popover ไม่ใช่ modal — ต้องไม่ล็อกการเลื่อนหน้า ปิดเมื่อคลิก
 *    นอกหรือกด Escape และคืนโฟกัสให้ปุ่มที่เปิดมัน
 * 2. ทุกอย่างผูกด้วย event delegation — แถวที่แทรกหลัง init ต้องใช้งานได้ทันที
 */
const cardMarkup = (id, kind, title, status = 'open') => `
    <article class="log-card" data-log-card data-log-id="${id}" data-log-kind="${kind}" data-log-status="${status}">
        <div class="log-card__body"><h3 class="log-card__title">${title}</h3></div>
        <div class="log-card__actions">
            <button type="button" class="log-card__menu-trigger" aria-expanded="false" data-log-menu-trigger>⋯</button>
        </div>
    </article>`;

/* หน้าปัจจุบันมีรายการเดียว เรียงเวลา และกรองสถานะได้ */
const mountTimeline = (cards = '', doneCards = '') => mountDom(`<!doctype html><html><body>
    <div data-daily-log data-date="2026-09-04" data-owner="1">
        <h3 data-timeline-count>(0 รายการ)</h3>
        <select data-status-filter><option value="all">ทั้งหมด</option><option value="open">รอเริ่ม</option><option value="done">เสร็จแล้ว</option></select>
        <div class="log-timeline__list" data-timeline-list>${cards}${doneCards}</div>
        <p data-timeline-empty>ยังไม่มีรายการ</p>
    </div>
</body></html>`);

test('เมนูรายแถวเปิดจากปุ่มและตั้ง aria-expanded ให้ถูก', () => {
    const dom = mountTimeline(cardMarkup(1, 'routine', 'ตรวจสอบคอมพิวเตอร์'));

    try {
        initTimeline({root: dom.document.querySelector('[data-daily-log]')});

        const trigger = dom.document.querySelector('[data-log-menu-trigger]');
        assert.equal(trigger.getAttribute('aria-expanded'), 'false');

        click(trigger);

        assert.ok(dom.document.querySelector('.log-row-menu'), 'เมนูต้องถูกสร้างขึ้น');
        assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    } finally {
        dom.cleanup();
    }
});

/*
 * popover ต้องไม่ทำตัวเป็น modal — ไม่มีการล็อกการเลื่อนของ body
 */
test('เมนูรายแถวไม่ล็อกการเลื่อนหน้าแบบ modal', () => {
    const dom = mountTimeline(cardMarkup(1, 'routine', 'งานทดสอบ'));

    try {
        initTimeline({root: dom.document.querySelector('[data-daily-log]')});
        click(dom.document.querySelector('[data-log-menu-trigger]'));

        assert.equal(dom.document.body.classList.contains('modal-open'), false);
    } finally {
        dom.cleanup();
    }
});

test('คลิกนอกเมนูปิดเมนู', () => {
    const dom = mountTimeline(cardMarkup(1, 'routine', 'งานทดสอบ'));

    try {
        initTimeline({root: dom.document.querySelector('[data-daily-log]')});
        const trigger = dom.document.querySelector('[data-log-menu-trigger]');

        click(trigger);
        assert.ok(dom.document.querySelector('.log-row-menu'));

        click(dom.document.body);

        assert.equal(dom.document.querySelector('.log-row-menu'), null);
        assert.equal(trigger.getAttribute('aria-expanded'), 'false');
    } finally {
        dom.cleanup();
    }
});

test('Escape ปิดเมนูและคืนโฟกัสให้ปุ่มที่เปิดมัน', () => {
    const dom = mountTimeline(cardMarkup(1, 'routine', 'งานทดสอบ'));

    try {
        initTimeline({root: dom.document.querySelector('[data-daily-log]')});
        const trigger = dom.document.querySelector('[data-log-menu-trigger]');

        click(trigger);
        pressKey(dom.document, 'Escape');

        assert.equal(dom.document.querySelector('.log-row-menu'), null);
        assert.equal(dom.document.activeElement, trigger, 'โฟกัสต้องกลับไปที่ปุ่มเดิม');
    } finally {
        dom.cleanup();
    }
});

test('กดปุ่มเดิมซ้ำเป็นการปิดเมนู', () => {
    const dom = mountTimeline(cardMarkup(1, 'routine', 'งานทดสอบ'));

    try {
        initTimeline({root: dom.document.querySelector('[data-daily-log]')});
        const trigger = dom.document.querySelector('[data-log-menu-trigger]');

        click(trigger);
        click(trigger);

        assert.equal(dom.document.querySelector('.log-row-menu'), null);
    } finally {
        dom.cleanup();
    }
});

test('เปิดเมนูของอีกแถวปิดเมนูเดิมเสมอ เหลือเปิดได้ทีละหนึ่ง', () => {
    const dom = mountTimeline(cardMarkup(1, 'routine', 'งานหนึ่ง') + cardMarkup(2, 'field', 'งานสอง'));

    try {
        initTimeline({root: dom.document.querySelector('[data-daily-log]')});
        const triggers = [...dom.document.querySelectorAll('[data-log-menu-trigger]')];

        click(triggers[0]);
        click(triggers[1]);

        assert.equal(dom.document.querySelectorAll('.log-row-menu').length, 1);
        assert.equal(triggers[0].getAttribute('aria-expanded'), 'false');
        assert.equal(triggers[1].getAttribute('aria-expanded'), 'true');
    } finally {
        dom.cleanup();
    }
});

test('เมนูส่ง id ของแถวไปให้ตัวจัดการแก้ไขและลบ', () => {
    const dom = mountTimeline(cardMarkup(7, 'routine', 'งานทดสอบ'));
    const edited = [];
    const deleted = [];

    try {
        initTimeline({
            root: dom.document.querySelector('[data-daily-log]'),
            onEdit: (id) => edited.push(id),
            onDelete: (id) => deleted.push(id),
        });

        click(dom.document.querySelector('[data-log-menu-trigger]'));
        click(dom.document.querySelector('[data-log-action="edit"]'));

        click(dom.document.querySelector('[data-log-menu-trigger]'));
        click(dom.document.querySelector('[data-log-action="delete"]'));

        assert.deepEqual(edited, ['7']);
        assert.deepEqual(deleted, ['7']);
    } finally {
        dom.cleanup();
    }
});

/*
 * นี่คือเหตุผลที่ต้องใช้ event delegation แทนการผูก listener รายแถว
 * แถวที่เพิ่งบันทึกเสร็จถูกแทรกเข้ามาหลัง init และต้องกดใช้งานได้ทันที
 */
test('แถวที่แทรกหลัง init ใช้เมนูได้ทันที', () => {
    const dom = mountTimeline('');
    const deleted = [];

    try {
        const timeline = initTimeline({
            root: dom.document.querySelector('[data-daily-log]'),
            onDelete: (id) => deleted.push(id),
        });

        timeline.upsertCard(cardMarkup(42, 'field', 'งานนอกสถานที่ใหม่'), 42);

        click(dom.document.querySelector('[data-log-menu-trigger]'));
        click(dom.document.querySelector('[data-log-action="delete"]'));

        assert.deepEqual(deleted, ['42']);
    } finally {
        dom.cleanup();
    }
});

test('upsertCard แทนที่แถวเดิมแทนการเพิ่มซ้ำ', () => {
    const dom = mountTimeline(cardMarkup(5, 'routine', 'ชื่อเดิม'));

    try {
        const timeline = initTimeline({root: dom.document.querySelector('[data-daily-log]')});

        timeline.upsertCard(cardMarkup(5, 'field', 'ชื่อใหม่'), 5);

        const cards = dom.document.querySelectorAll('[data-log-card]');
        assert.equal(cards.length, 1);
        assert.equal(cards[0].querySelector('.log-card__title').textContent, 'ชื่อใหม่');
        assert.equal(cards[0].dataset.logKind, 'field');
    } finally {
        dom.cleanup();
    }
});

test('upsertCard ซ่อนข้อความสถานะว่างและอัปเดตตัวนับรายการ', () => {
    const dom = mountTimeline();

    try {
        const timeline = initTimeline({root: dom.document.querySelector('[data-daily-log]')});
        timeline.upsertCard(cardMarkup(1, 'routine', 'งานแรก'), 1);

        assert.equal(dom.document.querySelector('[data-timeline-empty]').hidden, true);
        assert.equal(dom.document.querySelector('[data-timeline-count]').textContent, '(1 รายการ)');
        assert.equal(dom.document.querySelectorAll('[data-log-card]').length, 1);
    } finally {
        dom.cleanup();
    }
});

test('แถวที่ถูกยืนยันอัปเดตสถานะโดยไม่ทิ้งแถวซ้ำไว้', () => {
    const dom = mountTimeline(cardMarkup(7, 'routine', 'เช็คคอมพิวเตอร์'));

    try {
        const timeline = initTimeline({root: dom.document.querySelector('[data-daily-log]')});

        timeline.upsertCard(cardMarkup(7, 'routine', 'เช็คคอมพิวเตอร์', 'done'), 7);

        const list = dom.document.querySelector('[data-timeline-list]');
        assert.equal(list.querySelectorAll('[data-log-card]').length, 1);
        assert.equal(dom.document.querySelectorAll('[data-log-card][data-log-id="7"]').length, 1);
        assert.equal(list.querySelector('[data-log-card]').dataset.logStatus, 'done');
        assert.equal(dom.document.querySelector('[data-timeline-count]').textContent, '(1 รายการ)');
    } finally {
        dom.cleanup();
    }
});

test('ตัวกรองสถานะซ่อนเฉพาะแถวที่ไม่ตรง และกลับมาครบเมื่อเลือกทั้งหมด', () => {
    const dom = mountTimeline(
        cardMarkup(1, 'routine', 'งานประจำ', 'done')
        + cardMarkup(2, 'field', 'งานนอกสถานที่', 'open')
        + cardMarkup(3, 'routine', 'งานประจำอีกงาน')
    );

    try {
        initTimeline({root: dom.document.querySelector('[data-daily-log]')});

        const filter = dom.document.querySelector('[data-status-filter]');
        filter.value = 'done';
        filter.dispatchEvent(new dom.window.Event('change', {bubbles: true}));

        const visible = [...dom.document.querySelectorAll('[data-log-card]')].filter((card) => ! card.hidden);
        assert.equal(visible.length, 1);

        filter.value = 'all';
        filter.dispatchEvent(new dom.window.Event('change', {bubbles: true}));

        const restored = [...dom.document.querySelectorAll('[data-log-card]')].filter((card) => ! card.hidden);
        assert.equal(restored.length, 3);
    } finally {
        dom.cleanup();
    }
});

/*
 * initializer ต้อง idempotent — การเรียกซ้ำ (เช่นสคริปต์ถูกโหลดสองครั้ง)
 * ต้องไม่ทำให้เกิด listener ซ้ำจนเมนูเปิดแล้วปิดทันทีในคลิกเดียว
 */
test('เรียก initTimeline ซ้ำไม่ผูก listener ซ้ำ', () => {
    const dom = mountTimeline(cardMarkup(1, 'routine', 'งานทดสอบ'));

    try {
        const root = dom.document.querySelector('[data-daily-log]');

        initTimeline({root});
        const second = initTimeline({root});

        assert.equal(second, null, 'การเรียกซ้ำต้องไม่คืน instance ใหม่');

        click(dom.document.querySelector('[data-log-menu-trigger]'));

        assert.equal(dom.document.querySelectorAll('.log-row-menu').length, 1);
    } finally {
        dom.cleanup();
    }
});

test('removeCard เอาแถวออกจากไทม์ไลน์', () => {
    const dom = mountTimeline(cardMarkup(9, 'routine', 'งานที่จะลบ'));

    try {
        const timeline = initTimeline({root: dom.document.querySelector('[data-daily-log]')});

        timeline.removeCard(9);

        assert.equal(dom.document.querySelectorAll('[data-log-card]').length, 0);
    } finally {
        dom.cleanup();
    }
});

/*
 * รายการอยู่ใน .log-timeline__scroll ที่มี overflow — เมนูที่เป็นลูกของการ์ดถูกตัดขอบ
 * และไปซ่อนอยู่ในกรอบรายการ เมนูจึงต้องอยู่นอกการ์ดและนอกกล่องที่เลื่อนได้
 */
test('เมนูรายแถวไม่ถูกวางในการ์ดหรือกล่องที่เลื่อนได้ แต่ยังส่ง id และการ์ดให้ตัวจัดการ', () => {
    const dom = mountDom(`<!doctype html><html><body>
        <div data-daily-log>
            <div class="log-timeline__scroll" style="overflow-x:auto">
                <div data-timeline-list>${cardMarkup(3, 'routine', 'งานแถวสุดท้าย')}</div>
            </div>
        </div>
    </body></html>`);
    const edited = [];

    try {
        const root = dom.document.querySelector('[data-daily-log]');
        initTimeline({root, onEdit: (id, card) => edited.push([id, card?.dataset.logId])});

        click(dom.document.querySelector('[data-log-menu-trigger]'));
        const menu = dom.document.querySelector('.log-row-menu');

        assert.equal(menu.closest('[data-log-card]'), null, 'เมนูต้องไม่อยู่ในการ์ด');
        assert.equal(menu.closest('.log-timeline__scroll'), null, 'เมนูต้องไม่อยู่ในกล่องที่ตัดขอบ');
        assert.equal(menu.parentElement, root);

        click(menu.querySelector('[data-log-action="edit"]'));
        assert.deepEqual(edited, [['3', '3']]);
    } finally {
        dom.cleanup();
    }
});

test('เลื่อนหน้าแล้วเมนูลอยปิดเอง ไม่ค้างผิดตำแหน่ง', () => {
    const dom = mountTimeline(cardMarkup(1, 'routine', 'งานทดสอบ'));

    try {
        initTimeline({root: dom.document.querySelector('[data-daily-log]')});
        click(dom.document.querySelector('[data-log-menu-trigger]'));
        assert.ok(dom.document.querySelector('.log-row-menu'));

        dom.window.dispatchEvent(new dom.window.Event('scroll'));

        assert.equal(dom.document.querySelector('.log-row-menu'), null);
    } finally {
        dom.cleanup();
    }
});
