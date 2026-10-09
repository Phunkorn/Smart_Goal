import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {mountDom, click, pressKey} from './helpers/dom.js';
import {
    MAX_SCALE,
    MIN_SCALE,
    clampOffset,
    clampScale,
    initFloorPlan,
    toggleSelection,
    zoomAround,
} from '../../resources/js/pages/computer-check/floor-plan.js';

/* ---------- กติกาการซูมและการเลื่อน (ฟังก์ชันบริสุทธิ์) ---------- */

test('ค่าซูมถูกจำกัดให้อยู่ในช่วงที่ยังใช้งานได้', () => {
    assert.equal(clampScale(0.1), MIN_SCALE);
    assert.equal(clampScale(99), MAX_SCALE);
    assert.equal(clampScale(2), 2);
    // ค่าที่ไม่ใช่ตัวเลขต้องไม่ทำให้ผังหายไปทั้งใบ
    assert.equal(clampScale(Number.NaN), 1);
});

test('ผังที่เล็กกว่ากรอบถูกจัดกึ่งกลางแทนการค้างอยู่มุมใดมุมหนึ่ง', () => {
    const offset = clampOffset({x: -500, y: -500}, {
        scale: 1,
        stageWidth: 1000,
        stageHeight: 800,
        contentWidth: 600,
        contentHeight: 400,
    });

    assert.deepEqual(offset, {x: 200, y: 200});
});

test('ลากผังที่ใหญ่กว่ากรอบออกนอกขอบไม่ได้', () => {
    const bounds = {scale: 2, stageWidth: 400, stageHeight: 300, contentWidth: 400, contentHeight: 300};

    // ลากไปทางขวา/ล่างเกินขอบบน-ซ้าย
    assert.deepEqual(clampOffset({x: 80, y: 60}, bounds), {x: 0, y: 0});
    // ลากเลยขอบล่าง-ขวา — หยุดที่ขอบพอดี (stage - scaled)
    assert.deepEqual(clampOffset({x: -9999, y: -9999}, bounds), {x: -400, y: -300});
});

test('ซูมแล้วจุดที่ตรึงไว้ยังอยู่ที่เดิม', () => {
    const next = zoomAround({scale: 1, x: 0, y: 0}, 2, {x: 100, y: 50});

    // จุด (100,50) บนหน้าจอต้องยังชี้ไปที่จุดเดิมของผังหลังซูม
    assert.equal(next.scale, 2);
    assert.equal(next.x, -100);
    assert.equal(next.y, -50);
});

test('toggleSelection เพิ่มและเอาออกโดยไม่แก้ชุดเดิม', () => {
    const first = toggleSelection(new Set(), 'a');
    const second = toggleSelection(first, 'b');
    const third = toggleSelection(second, 'a');

    assert.deepEqual([...first], ['a']);
    assert.deepEqual([...second], ['a', 'b']);
    assert.deepEqual([...third], ['b']);
});

/* ---------- พฤติกรรมจริงบน DOM ---------- */

const planMarkup = `
<div data-computer-check>
  <div class="cc-plan" data-cc-plan>
    <label><input type="checkbox" data-cc-multi-toggle></label>
    <button type="button" data-cc-zoom="in"></button>
    <button type="button" data-cc-zoom="out"></button>
    <button type="button" data-cc-zoom="reset"></button>
    <div class="cc-plan__stage" data-cc-plan-stage>
      <div class="cc-plan__canvas" data-cc-plan-canvas>
        <svg viewBox="0 0 996 1000">
          <g data-cc-station data-station-id="1" data-status="pending" class="cc-station cc-station--pending" role="button" tabindex="0" aria-pressed="false">
            <title>A-01 — ยังไม่ตรวจ</title>
            <text class="cc-station__state">○ ยังไม่ตรวจ</text>
          </g>
          <g data-cc-station data-station-id="2" data-status="pending" class="cc-station cc-station--pending" role="button" tabindex="0" aria-pressed="false">
            <title>A-02 — ยังไม่ตรวจ</title>
            <text class="cc-station__state">○ ยังไม่ตรวจ</text>
          </g>
        </svg>
      </div>
    </div>
  </div>
</div>`;

const design = {
    workstationStatuses: {
        pending: {label: 'ยังไม่ตรวจ', mark: '○'},
        normal: {label: 'ปกติ', mark: '✓'},
        issue: {label: 'มีปัญหา', mark: '!'},
    },
};

test('แตะโต๊ะแล้วส่ง id ของเครื่องนั้นออกไป และกดด้วยคีย์บอร์ดได้เหมือนกัน', () => {
    const dom = mountDom(`<!doctype html><html><body>${planMarkup}</body></html>`);

    try {
        const chosen = [];
        const plan = initFloorPlan({
            root: dom.document.querySelector('[data-computer-check]'),
            design,
            onSelect: (id) => chosen.push(id),
        });

        const first = dom.document.querySelector('[data-station-id="1"]');
        click(first);

        assert.deepEqual(chosen, ['1']);
        assert.equal(first.getAttribute('aria-pressed'), 'true');

        // Enter ต้องทำงานเหมือนการแตะ — ผังต้องใช้ได้ด้วยคีย์บอร์ด
        pressKey(dom.document.querySelector('[data-station-id="2"]'), 'Enter');
        assert.deepEqual(chosen, ['1', '2']);

        // โหมดเลือกทีละเครื่อง: เลือกใหม่แล้วอันเก่าต้องถูกยกเลิก
        assert.equal(first.getAttribute('aria-pressed'), 'false');
        assert.deepEqual(plan.selectedIds(), ['2']);
    } finally {
        dom.cleanup();
    }
});

test('โหมดเลือกหลายเครื่องสะสมหลายตัวและรายงานรายการที่เลือกออกไป', () => {
    const dom = mountDom(`<!doctype html><html><body>${planMarkup}</body></html>`);

    try {
        const batches = [];
        const plan = initFloorPlan({
            root: dom.document.querySelector('[data-computer-check]'),
            design,
            onSelect: () => assert.fail('โหมดหลายเครื่องต้องไม่เปิดแผงของเครื่องเดียว'),
            onMultiChange: (ids) => batches.push(ids),
        });

        const toggle = dom.document.querySelector('[data-cc-multi-toggle]');
        toggle.checked = true;
        toggle.dispatchEvent(new dom.window.Event('change', {bubbles: true}));

        click(dom.document.querySelector('[data-station-id="1"]'));
        click(dom.document.querySelector('[data-station-id="2"]'));

        assert.deepEqual(plan.selectedIds(), ['1', '2']);
        assert.deepEqual(batches.at(-1), ['1', '2']);

        // กดซ้ำเพื่อเอาออก
        click(dom.document.querySelector('[data-station-id="1"]'));
        assert.deepEqual(plan.selectedIds(), ['2']);
    } finally {
        dom.cleanup();
    }
});

test('applyStations ทาสถานะใหม่โดยไม่สร้าง SVG ใหม่และไม่รีเซ็ตการซูม', () => {
    const dom = mountDom(`<!doctype html><html><body>${planMarkup}</body></html>`);

    try {
        const root = dom.document.querySelector('[data-computer-check]');
        const plan = initFloorPlan({root, design});
        const canvas = dom.document.querySelector('[data-cc-plan-canvas]');
        const node = dom.document.querySelector('[data-station-id="1"]');

        click(dom.document.querySelector('[data-cc-zoom="in"]'));
        const zoomed = canvas.style.transform;

        plan.applyStations([
            {id: 1, code: 'A-01', status: 'issue', status_label: 'มีปัญหา', note: 'ไมค์เสีย', devices: {}},
        ]);

        assert.equal(node.dataset.status, 'issue');
        assert.ok(node.classList.contains('cc-station--issue'));
        assert.ok(! node.classList.contains('cc-station--pending'));
        assert.equal(node.querySelector('.cc-station__state').textContent, '! มีปัญหา');
        assert.equal(node.querySelector('title').textContent, 'A-01 — มีปัญหา (ไมค์เสีย)');
        // ป้ายสำหรับ screen reader ต้องเปลี่ยนตามด้วย ไม่ใช่เหลือแค่สี
        assert.equal(node.getAttribute('aria-label'), 'A-01 — มีปัญหา');

        // โหนดเดิมถูกใช้ต่อ (ไม่ถูกสร้างใหม่) และการซูมไม่ถูกรีเซ็ต
        assert.equal(dom.document.querySelector('[data-station-id="1"]'), node);
        assert.equal(canvas.style.transform, zoomed);
    } finally {
        dom.cleanup();
    }
});

/* ---------- ผังมีแหล่งเดียว ---------- */

test('ผัง SVG ถูกวาดจาก partial เดียว ทั้งหน้าเว็บและแบบตรวจ A4', () => {
    const planPartial = 'resources/views/daily-logs/computer-check/partials/floor-plan.blade.php';
    const page = fs.readFileSync('resources/views/daily-logs/computer-check/index.blade.php', 'utf8');
    const report = fs.readFileSync('resources/views/daily-logs/computer-check/report.blade.php', 'utf8');
    const partial = fs.readFileSync(planPartial, 'utf8');

    // ทั้งสองหน้าต้อง include partial เดียวกัน ต่างกันแค่ mode
    assert.match(page, /daily-logs\.computer-check\.partials\.floor-plan/);
    assert.match(report, /daily-logs\.computer-check\.partials\.floor-plan/);
    assert.match(page, /'mode' => 'interactive'/);
    assert.match(report, /'mode' => 'print'/);

    // พิกัดต้องมาจากข้อมูล ไม่ใช่ตัวเลขที่เขียนตายไว้ใน Blade
    assert.match(partial, /\$station\['x'\]/);
    assert.match(partial, /\$station\['y'\]/);

    // ต้องไม่มี <svg> ของผังอยู่ในไฟล์อื่นอีกชุดหนึ่ง
    assert.ok(! /<svg[^>]*cc-plan__svg/.test(page), 'index.blade.php ต้องไม่วาดผังเอง');
    assert.ok(! /<svg[^>]*cc-plan__svg/.test(report), 'report.blade.php ต้องไม่วาดผังเอง');
});
