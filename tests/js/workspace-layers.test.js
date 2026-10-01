import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {layerKindOf, layerRuns, syncLayers} from '../../resources/js/pages/workspace/layers.js';

/*
 * ชั้นวาดของผืนผ้าใบ
 *
 * เรื่องที่ไฟล์นี้ล็อกไว้คือสิ่งที่ผู้ใช้รายงานว่า "สั่งให้เส้นขึ้นบนสุดแล้วมันก็
 * ไม่ขึ้นมาอยู่ด้านบน" ต้นเหตุคือกระดาษโน้ตอยู่คนละชั้นกับเส้นและชั้นนั้นทับอยู่
 * ข้างบนตายตัว ลำดับในเอกสารจึงไม่มีผลกับสิ่งที่เห็นเลยเมื่อข้ามชนิดกัน
 *
 * ข้อตกลงที่ต้องไม่พังคือ "ลำดับใน DOM ของชั้น = ลำดับในเอกสาร" ถ้าข้อนี้จริง
 * คำสั่งจัดลำดับชั้นก็เห็นผลจริงเสมอ โดยไม่ต้องพึ่ง z-index ที่ไหนเลย
 */

const line = (id) => ({id, type: 'line', x: 0, y: 0, w: 10, h: 10});
const note = (id) => ({id, type: 'sticky', x: 0, y: 0, w: 10, h: 10});

const kindsOf = (runs) => runs.map((run) => run.kind);
const idsOf = (runs) => runs.map((run) => run.elements.map((element) => element.id));

test('ชิ้นงานถูกแบ่งชั้นตามเทคโนโลยีที่ใช้วาด', () => {
    assert.equal(layerKindOf(note('a')), 'overlay');
    assert.equal(layerKindOf({id: 'b', type: 'text'}), 'overlay');
    assert.equal(layerKindOf(line('c')), 'vector');
    assert.equal(layerKindOf({id: 'd', type: 'image'}), 'vector');
});

test('ชิ้นงานชนิดเดียวกันที่อยู่ติดกันรวมเป็นชั้นเดียว', () => {
    const runs = layerRuns([line('a'), line('b'), note('c'), note('d')]);

    assert.deepEqual(kindsOf(runs), ['vector', 'overlay']);
    assert.deepEqual(idsOf(runs), [['a', 'b'], ['c', 'd']]);
});

/*
 * หัวใจของเรื่องทั้งหมด: เส้นที่อยู่หลังกระดาษโน้ตในเอกสารต้องได้ชั้นของตัวเอง
 * ที่อยู่หลังชั้นของโน้ต ไม่ใช่ถูกยัดกลับไปรวมกับเส้นที่อยู่ก่อนหน้า
 */
test('ชนิดที่สลับไปมาได้ชั้นของตัวเองตามลำดับ ไม่ถูกจับกลับมารวมกลุ่มเดิม', () => {
    const runs = layerRuns([line('a'), note('b'), line('c'), note('d'), line('e')]);

    assert.deepEqual(kindsOf(runs), ['vector', 'overlay', 'vector', 'overlay', 'vector']);
    assert.deepEqual(idsOf(runs), [['a'], ['b'], ['c'], ['d'], ['e']]);
});

test('ฉากว่างไม่มีชั้นเลย', () => {
    assert.deepEqual(layerRuns([]), []);
});

/* ── การทำให้ DOM ตรงกับชั้นที่คำนวณไว้ ─────────────────────── */

const mountContainer = () => {
    const dom = mountDom('<!doctype html><html><body><div class="wsb-layers"></div></body></html>');

    return {dom, container: dom.document.querySelector('.wsb-layers')};
};

const domKinds = (container) =>
    Array.from(container.children).map((node) => node.getAttribute('data-layer-kind'));

test('ลำดับของชั้นใน DOM ตรงกับลำดับในเอกสาร', () => {
    const {dom, container} = mountContainer();

    try {
        syncLayers(container, layerRuns([line('a'), note('b'), line('c')]), dom.document);

        assert.deepEqual(domKinds(container), ['vector', 'overlay', 'vector']);
        assert.equal(container.children[0].tagName.toLowerCase(), 'svg');
        assert.equal(container.children[1].tagName.toLowerCase(), 'div');
    } finally {
        dom.cleanup();
    }
});

test('ชั้น vector ส่งกลับ <g> ข้างใน ส่วนชั้น overlay ส่งกลับตัวชั้นเอง', () => {
    const {dom, container} = mountContainer();

    try {
        const layers = syncLayers(container, layerRuns([line('a'), note('b')]), dom.document);

        assert.equal(layers[0].node.tagName.toLowerCase(), 'g');
        assert.equal(layers[0].node.parentNode, container.children[0]);
        assert.equal(layers[1].node, container.children[1]);
        assert.deepEqual(layers[1].elements.map((element) => element.id), ['b']);
    } finally {
        dom.cleanup();
    }
});

/*
 * ใช้โหนดเดิมซ้ำเมื่อชนิดยังตรงกัน ด้วยเหตุผลเดียวกับตัวเรนเดอร์: การสร้างโหนด
 * ใหม่ทุกรอบจะทำลายกล่องข้อความที่ผู้ใช้กำลังพิมพ์อยู่ และทำให้การลากกระตุก
 */
test('เรียกซ้ำด้วยชั้นชุดเดิม ใช้โหนดเดิมไม่สร้างใหม่', () => {
    const {dom, container} = mountContainer();

    try {
        const first = syncLayers(container, layerRuns([line('a'), note('b')]), dom.document);
        const second = syncLayers(container, layerRuns([line('a'), note('b')]), dom.document);

        assert.equal(first[0].node, second[0].node);
        assert.equal(first[1].node, second[1].node);
    } finally {
        dom.cleanup();
    }
});

test('ชั้นที่ไม่ต้องใช้แล้วถูกเก็บทิ้ง ไม่ค้างอยู่เป็นชั้นเปล่า', () => {
    const {dom, container} = mountContainer();

    try {
        syncLayers(container, layerRuns([line('a'), note('b'), line('c')]), dom.document);
        syncLayers(container, layerRuns([line('a')]), dom.document);

        assert.deepEqual(domKinds(container), ['vector']);
    } finally {
        dom.cleanup();
    }
});

test('ชั้นที่เปลี่ยนชนิดถูกแทนที่ด้วยชนิดที่ถูกต้อง', () => {
    const {dom, container} = mountContainer();

    try {
        syncLayers(container, layerRuns([line('a'), note('b')]), dom.document);
        syncLayers(container, layerRuns([note('b'), line('a')]), dom.document);

        assert.deepEqual(domKinds(container), ['overlay', 'vector']);
    } finally {
        dom.cleanup();
    }
});
