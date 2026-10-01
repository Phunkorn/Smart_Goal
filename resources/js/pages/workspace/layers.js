/*
 * ชั้นวาดของผืนผ้าใบ — ตัวที่ทำให้ "ลำดับชั้น" ของกระดานเป็นจริงบนหน้าจอ
 *
 * ปัญหาที่ไฟล์นี้แก้
 * ---------------------------------------------------------------
 * กระดานวาดชิ้นงานด้วยเทคโนโลยีสองอย่าง เส้น รูปทรง และรูปภาพเป็น SVG ส่วน
 * กระดาษโน้ตกับกล่องข้อความเป็น HTML (เพราะภาษาไทยต้องพึ่งตัวตัดคำของเบราว์เซอร์
 * ดู overlay-text.js) เดิมทีแต่ละอย่างอยู่ในชั้นของตัวเองชั้นเดียวตายตัว โดยชั้น
 * HTML ทับอยู่บนชั้น SVG เสมอ ผลคือคำสั่ง "ขึ้นบนสุด" ของเส้นไม่มีทางพาเส้นขึ้น
 * ไปอยู่เหนือกระดาษโน้ตได้เลย ไม่ว่าลำดับในเอกสารจะถูกต้องแค่ไหนก็ตาม ผู้ใช้กด
 * แล้วเห็นว่า "ไม่มีอะไรเกิดขึ้น" ทั้งที่ข้อมูลเปลี่ยนไปแล้วจริง ๆ
 *
 * วิธีแก้
 * ---------------------------------------------------------------
 * แทนที่จะมีชั้นละหนึ่งใบตายตัว ให้ตัดฉากเป็น "ช่วงติดกัน" (run) ตามชนิดของ
 * เทคโนโลยีที่ใช้วาด แล้วสร้างชั้นหนึ่งใบต่อหนึ่งช่วง เรียงตามลำดับในเอกสาร
 * ลำดับใน DOM จึงเท่ากับลำดับชั้นที่ผู้ใช้เห็นเสมอ โดยไม่ต้องใช้ z-index เลย
 * (ชั้นทั้งหมดเป็น position: absolute พี่น้องกัน จึงทับกันตามลำดับใน DOM)
 *
 *   เอกสาร: [เส้น, โน้ต, โน้ต, เส้น]
 *   ชั้น:   [<svg> เส้น] [<div> โน้ต โน้ต] [<svg> เส้น]
 *
 * จำนวนชั้นเท่ากับจำนวนครั้งที่ชนิดสลับกัน ซึ่งในการใช้งานจริงมีไม่กี่ชั้น
 * (คนวาดเส้นเป็นกลุ่ม แปะโน้ตเป็นกลุ่ม) ไม่ใช่ชั้นละชิ้น
 *
 * ชั้น SVG ทุกใบถูกตั้ง pointer-events: none ใน CSS การตรวจว่าคลิกโดนอะไรใช้
 * ตัวแบบข้อมูลที่ index.js อยู่แล้ว (hitAt) ไม่ได้ใช้ event.target ชั้นที่ลอย
 * อยู่ข้างบนจึงไม่บังการคลิกของชั้นที่อยู่ข้างใต้
 */

import {isOverlayType} from './overlay-text.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

/** ชั้นที่วาดด้วย HTML (กระดาษโน้ตและกล่องข้อความ) หรือด้วย SVG (ที่เหลือ) */
export const layerKindOf = (element) => (isOverlayType(element.type) ? 'overlay' : 'vector');

/**
 * ตัดรายการชิ้นงานเป็นช่วงติดกันตามชนิดของชั้น — ตรรกะบริสุทธิ์ ทดสอบแยกได้
 *
 * รักษาลำดับเดิมไว้ทั้งหมด ไม่จัดกลุ่มใหม่ เพราะลำดับคือความหมาย ชิ้นที่อยู่
 * ท้ายรายการคือชิ้นที่อยู่บนสุด
 *
 * @returns {Array<{kind: string, elements: Array}>}
 */
export const layerRuns = (elements) => elements.reduce((runs, element) => {
    const kind = layerKindOf(element);
    const last = runs[runs.length - 1];

    if (last && last.kind === kind) {
        last.elements.push(element);
    } else {
        runs.push({kind, elements: [element]});
    }

    return runs;
}, []);

/**
 * ทำให้ชั้นใน DOM ตรงกับช่วงที่คำนวณไว้ แล้วคืนเป้าหมายที่ตัวเรนเดอร์ต้องวาดลง
 *
 * ใช้ชั้นเดิมซ้ำเมื่อชนิดตรงกัน ไม่ล้างแล้วสร้างใหม่ทุกรอบ ด้วยเหตุผลเดียวกับ
 * ตัวเรนเดอร์ (renderer.js): การสร้างโหนดใหม่ทุกเฟรมทำให้ลากแล้วกระตุก และจะ
 * ทำลายกล่องข้อความที่ผู้ใช้กำลังพิมพ์อยู่
 *
 * @returns {Array<{kind: string, node: Element, elements: Array}>}
 *   node ของช่วง vector คือ <g> ข้างใน <svg> (ตัวที่รับ transform ของกล้อง)
 *   ส่วนของช่วง overlay คือ <div> ของชั้นนั้นเอง
 */
export const syncLayers = (container, runs, doc = container.ownerDocument) => {
    const layers = runs.map((run, index) => {
        const existing = container.children[index];
        let node = existing;

        if (! node || node.getAttribute('data-layer-kind') !== run.kind) {
            node = createLayer(doc, run.kind);

            if (existing) {
                container.replaceChild(node, existing);
            } else {
                container.appendChild(node);
            }
        }

        return {
            kind: run.kind,
            node: run.kind === 'overlay' ? node : node.firstElementChild,
            elements: run.elements,
        };
    });

    // ชั้นที่เกินมาจากรอบก่อน (ชิ้นงานถูกลบหรือถูกจัดลำดับใหม่จนช่วงหายไป)
    while (container.children.length > runs.length) {
        container.lastElementChild.remove();
    }

    return layers;
};

const createLayer = (doc, kind) => {
    if (kind === 'overlay') {
        const node = doc.createElement('div');

        node.className = 'wsb-overlay';
        node.setAttribute('data-layer-kind', 'overlay');

        return node;
    }

    const svg = doc.createElementNS(SVG_NS, 'svg');

    svg.setAttribute('class', 'wsb-canvas');
    svg.setAttribute('data-layer-kind', 'vector');
    // ชั้นนี้เป็นภาพล้วน ผู้ใช้โปรแกรมอ่านจออ่านเนื้อหาจากกล่องข้อความในชั้น
    // HTML ซึ่งมี role="textbox" ของตัวเองอยู่แล้ว
    svg.setAttribute('aria-hidden', 'true');
    svg.appendChild(doc.createElementNS(SVG_NS, 'g'));

    return svg;
};
