import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {mountDom} from './helpers/dom.js';
import {applyCamera, renderElements, renderPreview} from '../../resources/js/pages/workspace/renderer.js';
import {renderSelection} from '../../resources/js/pages/workspace/selection-ui.js';

/*
 * ตัวเรนเดอร์และชั้นการเลือก
 *
 * ทดสอบใน jsdom ได้เต็มที่เพราะเลือกใช้ SVG ไม่ใช่ <canvas> ซึ่ง jsdom ไม่มี
 * 2D context ให้เลย ที่นี่ตรวจแค่ว่า "ฉากเข้าไป โหนดที่ถูกต้องออกมา" ส่วนการ
 * ตัดสินใจทั้งหมดถูกทดสอบไว้ในเทสต์ของโมดูลบริสุทธิ์แล้ว
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

const mountLayers = () => {
    const dom = mountDom(`<!doctype html><html><body>
        <svg>
            <g data-workspace-vector></g>
            <g data-workspace-preview></g>
            <g data-workspace-selection></g>
        </svg>
    </body></html>`);

    return {
        dom,
        vector: dom.document.querySelector('[data-workspace-vector]'),
        preview: dom.document.querySelector('[data-workspace-preview]'),
        selection: dom.document.querySelector('[data-workspace-selection]'),
    };
};

const rect = (id, overrides = {}) => ({
    id, type: 'rect', z: 1, x: 10, y: 20, w: 100, h: 50,
    stroke: '#1f2937', strokeWidth: 2, fill: 'none', ...overrides,
});

test('ฉากถูกแปลงเป็นโหนด SVG ตามชนิดของชิ้นงาน', () => {
    const {dom, vector} = mountLayers();

    try {
        renderElements(vector, [
            {id: 'p1', type: 'pen', z: 1, stroke: '#dc2626', strokeWidth: 4, points: [[0, 0], [10, 10]]},
            rect('r1'),
            rect('e1', {type: 'ellipse'}),
            {id: 'l1', type: 'line', z: 4, x: 0, y: 0, w: 50, h: 50, stroke: '#000000', strokeWidth: 2},
        ], dom.document);

        const nodes = Array.from(vector.children);

        assert.deepEqual(nodes.map((n) => n.tagName.toLowerCase()), ['path', 'rect', 'ellipse', 'line']);
        assert.deepEqual(nodes.map((n) => n.getAttribute('data-el-id')), ['p1', 'r1', 'e1', 'l1']);
        assert.equal(nodes[0].getAttribute('d'), 'M 0 0 L 10 10');
        assert.equal(nodes[0].getAttribute('stroke'), '#dc2626');
        assert.equal(nodes[1].getAttribute('width'), '100');
    } finally {
        dom.cleanup();
    }
});

/*
 * การใช้โหนดเดิมซ้ำเป็นเรื่องของทั้งประสิทธิภาพและความถูกต้อง การสร้างใหม่ทุกเฟรม
 * ระหว่างลากทำให้กระตุก และจะทำลาย element ที่กำลังถูกโฟกัสอยู่ ซึ่งสำคัญมาก
 * เมื่อชั้นข้อความที่แก้ไขได้เข้ามาในเฟสถัดไป
 */
test('การเรนเดอร์ซ้ำใช้โหนดเดิมแทนการสร้างใหม่', () => {
    const {dom, vector} = mountLayers();

    try {
        renderElements(vector, [rect('r1')], dom.document);
        const first = vector.firstElementChild;

        renderElements(vector, [rect('r1', {w: 300})], dom.document);

        assert.equal(vector.firstElementChild, first, 'ต้องเป็นโหนดตัวเดิม');
        assert.equal(first.getAttribute('width'), '300', 'แต่ค่าต้องอัปเดตแล้ว');
    } finally {
        dom.cleanup();
    }
});

test('ชิ้นงานที่หายจากฉากถูกลบออกจาก DOM', () => {
    const {dom, vector} = mountLayers();

    try {
        renderElements(vector, [rect('a'), rect('b')], dom.document);
        renderElements(vector, [rect('b')], dom.document);

        assert.equal(vector.children.length, 1);
        assert.equal(vector.firstElementChild.getAttribute('data-el-id'), 'b');
    } finally {
        dom.cleanup();
    }
});

test('ลำดับโหนดตรงกับลำดับในฉาก เพราะลำดับคือการซ้อนทับ', () => {
    const {dom, vector} = mountLayers();

    try {
        renderElements(vector, [rect('a'), rect('b'), rect('c')], dom.document);
        renderElements(vector, [rect('c'), rect('a'), rect('b')], dom.document);

        assert.deepEqual(
            Array.from(vector.children).map((n) => n.getAttribute('data-el-id')),
            ['c', 'a', 'b']
        );
    } finally {
        dom.cleanup();
    }
});

test('ชิ้นงานที่เปลี่ยนชนิดได้แท็ก SVG ใหม่ ไม่ใช่แท็กเดิมที่ผิดชนิด', () => {
    const {dom, vector} = mountLayers();

    try {
        renderElements(vector, [rect('a')], dom.document);
        renderElements(vector, [rect('a', {type: 'ellipse'})], dom.document);

        assert.equal(vector.children.length, 1);
        assert.equal(vector.firstElementChild.tagName.toLowerCase(), 'ellipse');
    } finally {
        dom.cleanup();
    }
});

test('รูปภาพอ้าง src ที่เซิร์ฟเวอร์เตรียมไว้ ไม่ใช่ path ของไฟล์', () => {
    const {dom, vector} = mountLayers();

    try {
        renderElements(vector, [{
            id: 'i1', type: 'image', z: 1, x: 0, y: 0, w: 100, h: 80,
            attachmentId: 5, src: '/media/workspace-board-attachments/5',
        }], dom.document);

        const node = vector.firstElementChild;

        assert.equal(node.tagName.toLowerCase(), 'image');
        assert.equal(node.getAttribute('href'), '/media/workspace-board-attachments/5');
    } finally {
        dom.cleanup();
    }
});

test('ลูกศรวาดหัวเป็นส่วนหนึ่งของเส้นทางเดียวกัน', () => {
    const {dom, vector} = mountLayers();

    try {
        renderElements(vector, [{
            id: 'a1', type: 'arrow', z: 1, x: 0, y: 0, w: 100, h: 0,
            stroke: '#1f2937', strokeWidth: 2,
        }], dom.document);

        const d = vector.firstElementChild.getAttribute('d');

        // ก้านหนึ่งช่วง บวกปีกอีกหนึ่งช่วง = คำสั่ง M สองครั้ง
        assert.equal((d.match(/M /g) || []).length, 2);
        assert.equal(d.startsWith('M 0 0 L 100 0'), true);
    } finally {
        dom.cleanup();
    }
});

test('กล้องถูกใส่เป็น transform ของชั้นเวกเตอร์', () => {
    const {dom, vector} = mountLayers();

    try {
        applyCamera(vector, {x: 10, y: -20, scale: 1.5});

        assert.equal(vector.getAttribute('transform'), 'translate(10 -20) scale(1.5)');
    } finally {
        dom.cleanup();
    }
});

test('ชั้นตัวอย่างแสดงรูปที่กำลังลาก และว่างเมื่อไม่มี', () => {
    const {dom, preview} = mountLayers();

    try {
        renderPreview(preview, rect('preview'), dom.document);
        assert.equal(preview.children.length, 1);

        renderPreview(preview, null, dom.document);
        assert.equal(preview.children.length, 0);
    } finally {
        dom.cleanup();
    }
});

test('กรอบเลือกที่ลากถูกวาดเป็นสี่เหลี่ยมประ', () => {
    const {dom, preview} = mountLayers();

    try {
        renderPreview(preview, {id: 'marquee', type: 'marquee', x: 5, y: 5, w: 40, h: 30}, dom.document);

        const node = preview.querySelector('[data-el-id="marquee"]');

        assert.equal(node.getAttribute('class'), 'wsb-marquee');
        assert.equal(node.getAttribute('width'), '40');
    } finally {
        dom.cleanup();
    }
});

/*
 * มือจับต้องมีขนาดคงที่บนหน้าจอ ชั้นนี้จึงไม่ถูก transform ของกล้อง แต่แปลง
 * พิกัดเอง ถ้าปล่อยให้อยู่ในชั้นที่ถูกซูม มือจับจะเล็กจนแตะไม่โดนเมื่อซูมออก
 */
test('มือจับถูกวางตามพิกัดหน้าจอ และมีขนาดคงที่ทุกระดับซูม', () => {
    const {dom, selection} = mountLayers();

    try {
        const bounds = {x: 0, y: 0, w: 100, h: 100};

        // ตัวเรนเดอร์ใช้โหนดเดิมซ้ำ จึงต้องอ่านค่าเก็บไว้ก่อนเรนเดอร์รอบถัดไป
        // ไม่ใช่ถือการอ้างถึงโหนดไว้แล้วอ่านทีหลัง ซึ่งจะได้ค่าใหม่ทั้งคู่
        renderSelection(selection, bounds, {x: 0, y: 0, scale: 1});
        const handle = selection.querySelector('[data-handle="se"]');
        const sizeAtOne = handle.getAttribute('width');
        const xAtOne = Number(handle.getAttribute('x'));

        renderSelection(selection, bounds, {x: 0, y: 0, scale: 2});

        assert.equal(handle.getAttribute('width'), sizeAtOne, 'ขนาดมือจับต้องไม่เปลี่ยนตามการซูม');
        assert.equal(Number(handle.getAttribute('x')) > xAtOne, true,
            'แต่ตำแหน่งต้องขยับตามการซูม');
    } finally {
        dom.cleanup();
    }
});

test('ไม่มีการเลือกแปลว่าไม่มีกรอบและไม่มีมือจับ', () => {
    const {dom, selection} = mountLayers();

    try {
        renderSelection(selection, {x: 0, y: 0, w: 10, h: 10}, {x: 0, y: 0, scale: 1});
        assert.equal(selection.children.length > 0, true);

        renderSelection(selection, null, {x: 0, y: 0, scale: 1});
        assert.equal(selection.children.length, 0);
    } finally {
        dom.cleanup();
    }
});

/*
 * ผู้ที่ดูอย่างเดียวเห็นกรอบได้ (มีประโยชน์เวลาชี้ให้เพื่อนดู) แต่ไม่มีมือจับ
 * เพราะย่อขยายไม่ได้ การแสดงมือจับที่กดแล้วไม่เกิดอะไรทำให้สับสนกว่าไม่มี
 */
test('ผู้ที่ดูอย่างเดียวเห็นกรอบแต่ไม่มีมือจับ', () => {
    const {dom, selection} = mountLayers();

    try {
        renderSelection(selection, {x: 0, y: 0, w: 10, h: 10}, {x: 0, y: 0, scale: 1}, {editable: false});

        assert.notEqual(selection.querySelector('[data-part="frame"]'), null);
        assert.equal(selection.querySelector('[data-handle]'), null);
    } finally {
        dom.cleanup();
    }
});

/*
 * ข้อความบนกระดานเป็นข้อความอิสระที่เพื่อนร่วมแผนกคนไหนก็พิมพ์เข้ามาได้
 * การเขียนลง DOM ด้วย innerHTML จะทำให้กระดานกลายเป็นช่องทาง stored XSS
 * ตรวจจากไฟล์ต้นฉบับ เพราะการเรียกอาจอยู่ในเส้นทางที่เทสต์ด้านบนไม่ได้เดินผ่าน
 */
test('ตัวเรนเดอร์ไม่ใช้ innerHTML หรือ insertAdjacentHTML', () => {
    ['renderer.js', 'selection-ui.js'].forEach((file) => {
        const source = fs.readFileSync(`resources/js/pages/workspace/${file}`, 'utf8');
        const code = source
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/(^|[^:])\/\/.*$/gm, '$1');

        assert.doesNotMatch(code, /\.innerHTML\s*=/, `${file} ต้องไม่เขียนผ่าน innerHTML`);
        assert.doesNotMatch(code, /insertAdjacentHTML/, `${file} ต้องไม่ใช้ insertAdjacentHTML`);
    });
});

test('โหนด SVG ถูกสร้างด้วย namespace ที่ถูกต้อง', () => {
    const {dom, vector} = mountLayers();

    try {
        renderElements(vector, [rect('a')], dom.document);

        assert.equal(vector.firstElementChild.namespaceURI, SVG_NS);
    } finally {
        dom.cleanup();
    }
});
