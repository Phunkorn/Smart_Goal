/*
 * ตัวเรนเดอร์ - แปลงฉากเป็นโหนด SVG
 *
 * เป็นฟังก์ชันของ (ฉาก, กล้อง) ล้วน ๆ ไม่มีสถานะของตัวเอง และไม่ตัดสินใจอะไร
 * การตัดสินใจทั้งหมดอยู่ในโมดูลบริสุทธิ์ ที่นี่แค่ทำให้ DOM ตรงกับฉาก
 *
 * ใช้การจับคู่ด้วยรหัส (keyed reconciliation) ไม่ใช่ล้างแล้วสร้างใหม่ เพราะ
 * (1) การสร้างโหนดใหม่ทุกเฟรมระหว่างลากทำให้กระตุก และ (2) การล้างทั้งชั้นจะ
 * ทำลาย element ที่กำลังถูกโฟกัสอยู่ ซึ่งสำคัญมากเมื่อชั้นข้อความเข้ามาในเฟสถัดไป
 *
 * ห้ามใช้ innerHTML หรือ insertAdjacentHTML ในไฟล์นี้เด็ดขาด ข้อความบนกระดาน
 * เป็นข้อความอิสระที่เพื่อนร่วมแผนกคนไหนก็พิมพ์เข้ามาได้ ต้องเขียนผ่าน
 * textContent เท่านั้น มิฉะนั้นกระดานจะกลายเป็นช่องทาง stored XSS
 */

import {svgTransform} from './camera.js';
import {boundsOf} from './geometry.js';
import {pointsToPathData} from './simplify.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

/** ปรับ transform ของชั้นเวกเตอร์ตามกล้องปัจจุบัน */
export const applyCamera = (layer, camera) => {
    layer.setAttribute('transform', svgTransform(camera));
};

/**
 * ทำให้ลูกของ layer ตรงกับรายการชิ้นงาน
 *
 * โหนดที่มีอยู่แล้วถูกใช้ซ้ำและอัปเดตเฉพาะค่าที่เปลี่ยน โหนดที่ไม่มีในฉากแล้ว
 * ถูกลบทิ้ง ลำดับของโหนดถูกจัดให้ตรงกับลำดับในฉาก เพราะลำดับใน DOM คือ
 * ลำดับการซ้อนทับของ SVG
 */
export const renderElements = (layer, elements, doc = layer.ownerDocument) => {
    const existing = new Map();

    Array.from(layer.children).forEach((node) => {
        const id = node.getAttribute('data-el-id');

        if (id !== null) {
            existing.set(id, node);
        }
    });

    elements.forEach((element, index) => {
        const previous = existing.get(element.id);
        let node = previous;

        // ชนิดที่เปลี่ยนไปแปลว่าต้องใช้แท็ก SVG คนละตัว ใช้ซ้ำไม่ได้
        if (! node || node.dataset.elType !== element.type) {
            node = createNode(doc, element);
            previous?.remove();
        }

        applyAttributes(node, element);
        existing.delete(element.id);

        // จัดลำดับให้ตรงกับฉาก insertBefore กับโหนดที่อยู่ถูกที่แล้วไม่ทำอะไร
        // จึงเรียกได้โดยไม่ต้องเช็คก่อน
        const atIndex = layer.children[index];

        if (atIndex !== node) {
            layer.insertBefore(node, atIndex || null);
        }
    });

    existing.forEach((node) => node.remove());
};

const createNode = (doc, element) => {
    const tag = element.type === 'pen' || element.type === 'arrow'
        ? 'path'
        : ({rect: 'rect', ellipse: 'ellipse', line: 'line', image: 'image'}[element.type] || 'rect');

    const node = doc.createElementNS(SVG_NS, tag);
    node.setAttribute('data-el-id', element.id);
    node.dataset.elType = element.type;

    return node;
};

const applyAttributes = (node, element) => {
    switch (element.type) {
        case 'pen':
            node.setAttribute('d', pointsToPathData(element.points));
            node.setAttribute('fill', 'none');
            node.setAttribute('stroke', element.stroke);
            node.setAttribute('stroke-width', String(element.strokeWidth));
            node.setAttribute('stroke-linecap', 'round');
            node.setAttribute('stroke-linejoin', 'round');
            break;

        case 'rect': {
            const box = boundsOf(element);
            node.setAttribute('x', String(box.x));
            node.setAttribute('y', String(box.y));
            node.setAttribute('width', String(box.w));
            node.setAttribute('height', String(box.h));
            node.setAttribute('fill', element.fill || 'none');
            node.setAttribute('stroke', element.stroke);
            node.setAttribute('stroke-width', String(element.strokeWidth));
            break;
        }

        case 'ellipse': {
            const box = boundsOf(element);
            node.setAttribute('cx', String(box.x + box.w / 2));
            node.setAttribute('cy', String(box.y + box.h / 2));
            node.setAttribute('rx', String(box.w / 2));
            node.setAttribute('ry', String(box.h / 2));
            node.setAttribute('fill', element.fill || 'none');
            node.setAttribute('stroke', element.stroke);
            node.setAttribute('stroke-width', String(element.strokeWidth));
            break;
        }

        case 'line':
            node.setAttribute('x1', String(element.x));
            node.setAttribute('y1', String(element.y));
            node.setAttribute('x2', String(element.x + element.w));
            node.setAttribute('y2', String(element.y + element.h));
            node.setAttribute('stroke', element.stroke);
            node.setAttribute('stroke-width', String(element.strokeWidth));
            node.setAttribute('stroke-linecap', 'round');
            break;

        case 'arrow':
            // วาดหัวลูกศรเป็นส่วนหนึ่งของเส้นทางเดียวกัน แทนการใช้ marker ของ SVG
            // เพราะ marker ต้องประกาศไว้ใน <defs> ด้วย id ที่ไม่ซ้ำกันทั้งหน้า
            // และไม่รับสีของเส้นที่มันเกาะอยู่โดยอัตโนมัติในทุกเบราว์เซอร์
            node.setAttribute('d', arrowPathData(element));
            node.setAttribute('fill', 'none');
            node.setAttribute('stroke', element.stroke);
            node.setAttribute('stroke-width', String(element.strokeWidth));
            node.setAttribute('stroke-linecap', 'round');
            node.setAttribute('stroke-linejoin', 'round');
            break;

        case 'image': {
            const box = boundsOf(element);
            node.setAttribute('x', String(box.x));
            node.setAttribute('y', String(box.y));
            node.setAttribute('width', String(box.w));
            node.setAttribute('height', String(box.h));
            node.setAttribute('preserveAspectRatio', 'xMidYMid meet');

            // src มาจาก WorkspaceBoardPresenter ซึ่งสร้างจาก route ของ
            // MediaController ฉากไม่เคยเก็บ path จริงของไฟล์
            if (element.src) {
                node.setAttribute('href', element.src);
            }

            break;
        }

        default:
            break;
    }
};

/** เส้นทางของลูกศร: ก้านหนึ่งเส้น บวกปีกสองข้างที่ปลาย */
const arrowPathData = (element) => {
    const x2 = element.x + element.w;
    const y2 = element.y + element.h;
    const angle = Math.atan2(element.h, element.w);
    const length = Math.hypot(element.w, element.h);

    // ปีกยาวตามความหนาของเส้น แต่ไม่เกินหนึ่งในสามของก้าน ไม่งั้นลูกศรสั้น ๆ
    // จะกลายเป็นสามเหลี่ยมล้วนจนดูไม่ออกว่าเป็นลูกศร
    const wing = Math.min(length / 3, (element.strokeWidth || 2) * 4 + 6);
    const spread = Math.PI / 7;

    const left = `${round(x2 - wing * Math.cos(angle - spread))} ${round(y2 - wing * Math.sin(angle - spread))}`;
    const right = `${round(x2 - wing * Math.cos(angle + spread))} ${round(y2 - wing * Math.sin(angle + spread))}`;

    return `M ${round(element.x)} ${round(element.y)} L ${round(x2)} ${round(y2)}`
        + ` M ${left} L ${round(x2)} ${round(y2)} L ${right}`;
};

const round = (value) => Math.round(value * 100) / 100;

/**
 * ชั้นตัวอย่าง - รูปทรงที่กำลังลากอยู่และกรอบเลือก
 *
 * แยกจากชั้นเนื้อหาจริงโดยตั้งใจ ฉากจึงไม่เคยมีชิ้นงานที่ยังวาดไม่เสร็จอยู่ในนั้น
 * ทำให้ประวัติ undo และการบันทึกอัตโนมัติสะอาด ไม่ต้องคอยกรองของชั่วคราวออก
 */
export const renderPreview = (layer, preview, doc = layer.ownerDocument) => {
    if (! preview) {
        renderElements(layer, [], doc);

        return;
    }

    if (preview.type === 'marquee') {
        renderMarquee(layer, preview, doc);

        return;
    }

    renderElements(layer, [preview], doc);
};

const renderMarquee = (layer, box, doc) => {
    let node = layer.querySelector('[data-el-id="marquee"]');

    if (! node) {
        layer.replaceChildren();
        node = doc.createElementNS(SVG_NS, 'rect');
        node.setAttribute('data-el-id', 'marquee');
        node.setAttribute('class', 'wsb-marquee');
        layer.append(node);
    }

    node.setAttribute('x', String(box.x));
    node.setAttribute('y', String(box.y));
    node.setAttribute('width', String(box.w));
    node.setAttribute('height', String(box.h));
};
