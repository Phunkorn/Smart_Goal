import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click, pressKey} from './helpers/dom.js';
import {initToolbar} from '../../resources/js/pages/workspace/toolbar.js';
import {initToolbarPopovers} from '../../resources/js/pages/workspace/toolbar-popover.js';

/*
 * แผงสีทั้งหมดบนแถบเครื่องมือ - popover ไม่ใช่ modal
 *
 * markup ตรงกับ resources/views/workspace/components/board-toolbar.blade.php
 * (สีลัดบนแถบ + ปุ่มเปิดแผง + แผงที่มีสีทั้งหมดและช่องเลือกสีเอง)
 * การวางตำแหน่งจริงบนจอพิสูจน์ใน jsdom ไม่ได้ ต้องดูในเบราว์เซอร์จริง
 */

const swatch = (color) =>
    `<button type="button" class="wsb-swatch" data-color="${color}" data-requires-edit aria-pressed="false"></button>`;

const markup = () => `<!doctype html><html><body>
<button type="button" id="outside">ข้างนอก</button>
<div class="wsb-toolbar" data-workspace-toolbar>
    <div class="wsb-toolbar__group">
        ${swatch('#1f2937')}${swatch('#dc2626')}
        <div class="wsb-picker" data-picker>
            <button type="button" data-picker-toggle data-requires-edit aria-expanded="false" aria-controls="p1">
                <span data-picker-current="stroke"></span>
            </button>
            <div class="wsb-popover" id="p1" data-picker-panel hidden>
                ${swatch('#1f2937')}${swatch('#dc2626')}${swatch('#7c3aed')}
                <input type="color" data-custom-color data-requires-edit value="#1f2937">
            </div>
        </div>
    </div>
    <div class="wsb-toolbar__group">
        <button type="button" data-sticky-color="#fde68a" data-requires-edit></button>
        <div class="wsb-picker" data-picker>
            <button type="button" data-picker-toggle data-requires-edit aria-expanded="false" aria-controls="p2">
                <span data-picker-current="sticky"></span>
            </button>
            <div class="wsb-popover" id="p2" data-picker-panel hidden>
                <button type="button" data-sticky-color="#fde68a" data-requires-edit></button>
                <button type="button" data-sticky-color="#e9d5ff" data-requires-edit></button>
            </div>
        </div>
    </div>
</div>
</body></html>`;

const mount = ({canEdit = true} = {}) => {
    const dom = mountDom(markup());
    const chosen = {stroke: [], sticky: []};
    const toolbarRoot = dom.document.querySelector('[data-workspace-toolbar]');

    const toolbar = initToolbar(toolbarRoot, {
        capabilities: {canEdit},
        onSelectColor: (color) => chosen.stroke.push(color),
        onSelectStickyColor: (color) => chosen.sticky.push(color),
    });

    const [strokeToggle, stickyToggle] = dom.document.querySelectorAll('[data-picker-toggle]');

    return {
        dom,
        chosen,
        toolbar,
        toolbarRoot,
        strokeToggle,
        stickyToggle,
        strokePanel: dom.document.getElementById('p1'),
        stickyPanel: dom.document.getElementById('p2'),
    };
};

const pointerDownOn = (target) =>
    target.dispatchEvent(new target.ownerDocument.defaultView.MouseEvent('pointerdown', {bubbles: true}));

test('บนแถบเห็นแค่สีลัดไม่กี่สี แผงสีทั้งหมดปิดอยู่จนกว่าจะกด', () => {
    const env = mount();

    try {
        assert.equal(env.strokePanel.hidden, true);
        assert.equal(env.strokeToggle.getAttribute('aria-expanded'), 'false');

        click(env.strokeToggle);

        assert.equal(env.strokePanel.hidden, false);
        assert.equal(env.strokeToggle.getAttribute('aria-expanded'), 'true');
    } finally {
        env.dom.cleanup();
    }
});

test('กดปุ่มเดิมซ้ำเป็นการปิดแผง', () => {
    const env = mount();

    try {
        click(env.strokeToggle);
        click(env.strokeToggle);

        assert.equal(env.strokePanel.hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

test('เลือกสีในแผงแล้วสีถูกใช้ และแผงปิดเองให้วาดต่อได้ทันที', () => {
    const env = mount();

    try {
        click(env.strokeToggle);
        click(env.strokePanel.querySelector('[data-color="#7c3aed"]'));

        assert.deepEqual(env.chosen.stroke, ['#7c3aed']);
        assert.equal(env.strokePanel.hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

test('สีลัดบนแถบใช้ได้ทันทีโดยไม่ต้องเปิดแผง', () => {
    const env = mount();

    try {
        click(env.toolbarRoot.querySelector(':scope > .wsb-toolbar__group > [data-color="#dc2626"]'));

        assert.deepEqual(env.chosen.stroke, ['#dc2626']);
        assert.equal(env.strokePanel.hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

test('เปิดแผงสีโน้ตแล้วแผงสีเส้นปิดเอง เปิดได้ทีละแผง', () => {
    const env = mount();

    try {
        click(env.strokeToggle);
        click(env.stickyToggle);

        assert.equal(env.strokePanel.hidden, true);
        assert.equal(env.stickyPanel.hidden, false);

        click(env.stickyPanel.querySelector('[data-sticky-color="#e9d5ff"]'));
        assert.deepEqual(env.chosen.sticky, ['#e9d5ff']);
        assert.equal(env.stickyPanel.hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

test('กดข้างนอกแผงแล้วแผงปิด แต่กดในแผง (เช่นช่องเลือกสีเอง) ไม่ปิด', () => {
    const env = mount();

    try {
        click(env.strokeToggle);
        pointerDownOn(env.strokePanel.querySelector('[data-custom-color]'));
        assert.equal(env.strokePanel.hidden, false);

        pointerDownOn(env.dom.document.getElementById('outside'));
        assert.equal(env.strokePanel.hidden, true);
        assert.equal(env.strokeToggle.getAttribute('aria-expanded'), 'false');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * Escape หนึ่งครั้งต้องปิดแค่สิ่งที่อยู่บนสุด ถ้าไม่ยกเลิกเหตุการณ์ keyboard.js
 * จะล้างการเลือกชิ้นงานบนกระดานไปพร้อมกัน
 */
test('Escape ปิดแผง คืนโฟกัสให้ปุ่ม และยกเลิกเหตุการณ์ไม่ให้ไปล้างการเลือกบนกระดาน', () => {
    const env = mount();

    try {
        click(env.strokeToggle);

        const event = new env.dom.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true});
        env.dom.document.dispatchEvent(event);

        assert.equal(env.strokePanel.hidden, true);
        assert.equal(env.dom.document.activeElement, env.strokeToggle);
        assert.equal(event.defaultPrevented, true);
    } finally {
        env.dom.cleanup();
    }
});

test('Escape ตอนแผงปิดอยู่ไม่ถูกยกเลิก ปุ่มลัดของกระดานยังทำงานตามเดิม', () => {
    const env = mount();

    try {
        const event = new env.dom.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true});
        env.dom.document.dispatchEvent(event);

        assert.equal(event.defaultPrevented, false);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * พบในเบราว์เซอร์จริงที่ 375px: แถบเครื่องมือบนจอแคบเลื่อนแนวนอนได้ เหตุการณ์ scroll
 * ของการปัดแถบมาหาปุ่มตามมาหลังการแตะ แผงเคยปิดทุกครั้งที่เลื่อน จึงเปิดแล้วปิด
 * ทันทีจนดูเหมือนปุ่มกดไม่ติด
 */
test('แถบเลื่อนหลังแตะเปิดแผง แผงยังเปิดอยู่และตามปุ่มไป', () => {
    const env = mount();

    try {
        click(env.strokeToggle);

        let left = 300;
        env.strokeToggle.getBoundingClientRect = () => ({left, right: left + 40, top: 700, bottom: 740, width: 40, height: 40});
        env.toolbarRoot.dispatchEvent(new env.dom.window.Event('scroll'));

        assert.equal(env.strokePanel.hidden, false);
        assert.equal(env.strokePanel.style.left, '300px', 'แผงต้องย้ายตามปุ่ม');

        left = 120;
        env.toolbarRoot.dispatchEvent(new env.dom.window.Event('scroll'));
        assert.equal(env.strokePanel.style.left, '120px');
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มถูกเลื่อนหลุดจอไปแล้ว แผงปิดตาม', () => {
    const env = mount();

    try {
        click(env.strokeToggle);

        env.strokeToggle.getBoundingClientRect = () => ({left: -200, right: -160, top: 700, bottom: 740, width: 40, height: 40});
        env.toolbarRoot.dispatchEvent(new env.dom.window.Event('scroll'));

        assert.equal(env.strokePanel.hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มเปิดแผงแสดงสีที่ใช้อยู่ แม้เป็นสีที่ไม่อยู่ในแถวลัด', () => {
    const env = mount();

    try {
        env.toolbar.sync({
            tool: 'pen',
            style: {stroke: '#8b5cf6', stickyColor: '#e9d5ff', strokeWidth: 4, fontSize: 20},
            canUndo: false,
            canRedo: false,
            hasSelection: false,
            scale: 1,
            isFullscreen: false,
        });

        const current = (name) => env.dom.document
            .querySelector(`[data-picker-current="${name}"]`).style.getPropertyValue('--wsb-swatch');

        assert.equal(current('stroke'), '#8b5cf6');
        assert.equal(current('sticky'), '#e9d5ff');
    } finally {
        env.dom.cleanup();
    }
});

test('ผู้ที่ดูอย่างเดียวเปิดแผงสีไม่ได้', () => {
    const env = mount({canEdit: false});

    try {
        assert.equal(env.strokeToggle.disabled, true);

        click(env.strokeToggle);
        assert.equal(env.strokePanel.hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

test('ผูกซ้ำบนแถบเดิมไม่ได้ตัวจัดการซ้อน การกดครั้งเดียวยังเปิดแผง', () => {
    const env = mount();

    try {
        assert.equal(initToolbarPopovers(env.toolbarRoot), null);

        click(env.strokeToggle);
        assert.equal(env.strokePanel.hidden, false, 'ถ้าผูกซ้อน การกดครั้งเดียวจะเปิดแล้วปิดทันที');

        pressKey(env.dom.document, 'Escape');
        assert.equal(env.strokePanel.hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

/* ── ความหนาเส้นในแผง ─────────────────────────────────────────── */

test('เลือกความหนาจากแผง ความหนาถูกใช้ แผงปิด และปุ่มแสดงความหนาปัจจุบัน', () => {
    const dom = mountDom(`<!doctype html><html><body>
    <div data-workspace-toolbar>
        <div data-picker>
            <button type="button" data-picker-toggle data-requires-edit aria-expanded="false">
                <span data-picker-current="width"></span>
            </button>
            <div data-picker-panel hidden>
                <button type="button" data-stroke-width="2" data-requires-edit></button>
                <button type="button" data-stroke-width="16" data-requires-edit></button>
            </div>
        </div>
    </div></body></html>`);

    try {
        const widths = [];
        const toolbar = initToolbar(dom.document.querySelector('[data-workspace-toolbar]'), {
            capabilities: {canEdit: true},
            onSelectWidth: (width) => widths.push(width),
        });
        const panel = dom.document.querySelector('[data-picker-panel]');

        click(dom.document.querySelector('[data-picker-toggle]'));
        assert.equal(panel.hidden, false);

        click(panel.querySelector('[data-stroke-width="16"]'));
        assert.deepEqual(widths, [16]);
        assert.equal(panel.hidden, true);

        toolbar.sync({
            tool: 'pen',
            style: {stroke: '#1f2937', stickyColor: '#fde68a', strokeWidth: 16, fontSize: 20},
            canUndo: false, canRedo: false, hasSelection: false, scale: 1, isFullscreen: false,
        });

        assert.equal(
            dom.document.querySelector('[data-picker-current="width"]').style.getPropertyValue('--wsb-width'),
            '12px',
            'เส้นหนามากถูกหนีบไว้ที่ 12px ให้เห็นเป็นเส้น ไม่ใช่ก้อนเต็มปุ่ม'
        );
        assert.equal(panel.querySelector('[data-stroke-width="16"]').getAttribute('aria-pressed'), 'true');
    } finally {
        dom.cleanup();
    }
});
