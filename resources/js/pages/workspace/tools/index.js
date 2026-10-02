/*
 * เครื่องมือบนแถบเครื่องมือ
 *
 * เครื่องมือทุกตัวมีหน้าตาเดียวกัน คือรับ context แล้วคืน "สิ่งที่เปลี่ยน"
 * โดยไม่แตะ DOM เลย:
 *
 *   onPointerDown(context) -> patch | undefined
 *   onPointerMove(context) -> patch | undefined
 *   onPointerUp(context)   -> patch | undefined
 *
 * context = {point, start, scene, selection, camera, style, draft, idFactory}
 *   point     พิกัดโลกของเคอร์เซอร์ในขณะนั้น
 *   start     พิกัดโลกตอนกดลง
 *   draft     สถานะชั่วคราวที่เครื่องมือเก็บไว้เองระหว่างท่าลาก
 *
 * patch = {scene?, selection?, camera?, preview?, draft?, commit?}
 *   preview   ชิ้นงานชั่วคราวที่ยังไม่เข้าฉาก (เส้นที่กำลังลาก กรอบที่กำลังเลือก)
 *   commit    true เมื่อจบท่าแล้วควรบันทึกลงประวัติ undo หนึ่งก้าว
 *
 * การแยกแบบนี้ทำให้ทดสอบเครื่องมือได้ด้วยการเรียกฟังก์ชันตรง ๆ โดยไม่ต้องมี
 * เบราว์เซอร์ และทำให้ index.js เป็นที่เดียวที่ถือสถานะจริง
 */

import {handTool} from './hand.js';
import {selectTool} from './select.js';
import {penTool} from './pen.js';
import {eraserTool} from './eraser.js';
import {shapeTool} from './shape.js';
import {stickyTool, textTool} from './sticky.js';

export const TOOLS = {
    hand: handTool,
    select: selectTool,
    pen: penTool,
    eraser: eraserTool,
    sticky: stickyTool,
    text: textTool,
    rect: shapeTool('rect'),
    ellipse: shapeTool('ellipse'),
    line: shapeTool('line'),
    arrow: shapeTool('arrow'),
};

/** เครื่องมือที่ชื่อนี้ ถ้าไม่รู้จักให้ถอยไปใช้เครื่องมือเลือก */
export const toolFor = (name) => TOOLS[name] || TOOLS.select;

/** เครื่องมือนี้แก้เนื้อหากระดานหรือไม่ (ผู้ที่ดูอย่างเดียวใช้ได้เฉพาะที่ไม่แก้) */
export const isReadOnlyTool = (name) => name === 'hand' || name === 'select';

/**
 * เครื่องมือนี้ทำงานกับ "มุมมอง" ไม่ใช่กับชิ้นงานบนกระดานหรือไม่
 *
 * ต่างจาก isReadOnlyTool ที่นับเครื่องมือเลือกเข้าไปด้วย เครื่องมือเลือกไม่ได้แก้
 * เนื้อหาด้วยตัวเองแต่ยังทำงานกับชิ้นงานอยู่ (เลือก ลากย้าย ดับเบิลคลิกเปิดพิมพ์)
 * ส่วนเครื่องมือกลุ่มนี้ไม่ควรแตะชิ้นงานเลย แค่เลื่อนกล้องไปมาเท่านั้น
 *
 * ธงอยู่บนตัวเครื่องมือ ไม่ใช่รายชื่อที่นี่ เพื่อให้เครื่องมือที่เพิ่มเข้ามาใหม่
 * ประกาศคุณสมบัติของตัวเองได้ที่ไฟล์ของมันเอง
 */
export const isViewportTool = (name) => toolFor(name).viewportOnly === true;
