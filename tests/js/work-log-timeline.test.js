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

/*
 * โครงของหน้าจริงมีสองกลุ่ม (ที่ต้องทำ / ทำแล้ว) แถวถูกวางตาม data-log-status
 * ที่เซิร์ฟเวอร์ใส่มากับ HTML ของแถว
 */
const mountTimeline = (cards = '', doneCards = '') => mountDom(`<!doctype html><html><body>
    <div data-daily-log data-date="2026-09-04" data-owner="1">
        <div class="log-timeline__filters">
            <button type="button" class="log-filter is-active" data-kind-filter="all">ทั้งหมด</button>
            <button type="button" class="log-filter" data-kind-filter="routine">งานประจำ</button>
            <button type="button" class="log-filter" data-kind-filter="field">งานนอกสถานที่</button>
        </div>
        <div class="log-group" data-log-group="open">
            <h3><span data-group-count>0</span></h3>
            <div class="log-timeline__list" data-timeline-list>${cards}</div>
            <p data-group-empty>ยังไม่มีบันทึกงานของวันนี้</p>
        </div>
        <div class="log-group" data-log-group="done">
            <h3><span data-group-count>0</span></h3>
            <div class="log-timeline__list" data-timeline-done>${doneCards}</div>
            <p data-group-empty>ยังไม่มีรายการที่ยืนยันว่าทำเสร็จแล้ว</p>
        </div>
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

test('upsertCard ซ่อนข้อความสถานะว่างและอัปเดตตัวนับของกลุ่ม', () => {
    const dom = mountTimeline();

    try {
        const timeline = initTimeline({root: dom.document.querySelector('[data-daily-log]')});
        const group = dom.document.querySelector('[data-log-group="open"]');

        timeline.upsertCard(cardMarkup(1, 'routine', 'งานแรก'), 1);

        assert.equal(group.querySelector('[data-group-empty]').hidden, true);
        assert.equal(group.querySelector('[data-group-count]').textContent, '1');
        assert.equal(dom.document.querySelectorAll('[data-log-card]').length, 1);
    } finally {
        dom.cleanup();
    }
});

/*
 * เส้นทางจริงของงานประจำ: กดยืนยันแล้วแถวต้องย้ายจาก "ที่ต้องทำ" ไป "ทำแล้ว"
 * โดยไม่โหลดหน้าใหม่ และต้องไม่เหลือแถวซ้ำค้างอยู่ในกลุ่มเดิม
 */
test('แถวที่ถูกยืนยันย้ายไปกลุ่มทำแล้วโดยไม่ทิ้งแถวซ้ำไว้', () => {
    const dom = mountTimeline(cardMarkup(7, 'routine', 'เช็คคอมพิวเตอร์'));

    try {
        const timeline = initTimeline({root: dom.document.querySelector('[data-daily-log]')});

        timeline.upsertCard(cardMarkup(7, 'routine', 'เช็คคอมพิวเตอร์', 'done'), 7);

        const openList = dom.document.querySelector('[data-timeline-list]');
        const doneList = dom.document.querySelector('[data-timeline-done]');

        assert.equal(openList.querySelectorAll('[data-log-card]').length, 0);
        assert.equal(doneList.querySelectorAll('[data-log-card]').length, 1);
        assert.equal(dom.document.querySelectorAll('[data-log-card][data-log-id="7"]').length, 1);
        assert.equal(
            dom.document.querySelector('[data-log-group="done"] [data-group-count]').textContent,
            '1'
        );
        assert.equal(
            dom.document.querySelector('[data-log-group="open"] [data-group-empty]').hidden,
            false
        );
    } finally {
        dom.cleanup();
    }
});

test('ตัวกรองประเภทซ่อนเฉพาะแถวที่ไม่ตรง และกลับมาครบเมื่อเลือกทั้งหมด', () => {
    const dom = mountTimeline(
        cardMarkup(1, 'routine', 'งานประจำ')
        + cardMarkup(2, 'field', 'งานนอกสถานที่')
        + cardMarkup(3, 'routine', 'งานประจำอีกงาน')
    );

    try {
        initTimeline({root: dom.document.querySelector('[data-daily-log]')});

        click(dom.document.querySelector('[data-kind-filter="routine"]'));

        const visible = [...dom.document.querySelectorAll('[data-log-card]')].filter((card) => ! card.hidden);
        assert.equal(visible.length, 2);
        assert.equal(dom.document.querySelector('[data-kind-filter="routine"]').classList.contains('is-active'), true);
        assert.equal(dom.document.querySelector('[data-kind-filter="all"]').classList.contains('is-active'), false);

        click(dom.document.querySelector('[data-kind-filter="all"]'));

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
