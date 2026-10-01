import fs from 'node:fs';
import path from 'node:path';

/*
 * อ่าน CSS ของกระดานไอเดียทั้งชุดเป็นสตริงเดียว
 *
 * ไฟล์ resources/css/pages/workspace.css เป็นเพียงสารบัญ @import กฎจริงอยู่ใน
 * partial ใต้ workspace/ เทสต์สัญญาที่ตรวจข้อความใน CSS จึงต้องอ่านทั้งชุด
 * ไม่ใช่อ่านแต่สารบัญ
 *
 * ต่อไฟล์ตามลำดับ @import จริง ไม่ใช่ตามชื่อไฟล์หรือรายการที่เขียนไว้ในเทสต์
 * ด้วยเหตุผลสองข้อ
 *   1. เทสต์ที่ตัดข้อความด้วย indexOf ต้องเห็นกฎเรียงลำดับเดียวกับที่เบราว์เซอร์เห็น
 *   2. partial ที่ลืมใส่ @import จะไม่ถูกอ่าน กฎในนั้นจึงหายไปจากสตริง แล้ว
 *      เทสต์สัญญาจะตกทันที — เป็นกับดักที่จับ partial กำพร้าให้ฟรี
 */

const MANIFEST = 'resources/css/pages/workspace.css';

const read = (file) => fs.readFileSync(file, 'utf8');

/** เนื้อหาของสารบัญเพียว ๆ ใช้ตรวจว่ามันไม่แอบมีกฎของตัวเอง */
export const workspaceCssManifest = () => read(MANIFEST);

/** รายการ partial ตามลำดับที่สารบัญ @import ไว้ */
export const workspaceCssParts = () =>
    [...read(MANIFEST).matchAll(/@import\s+'([^']+)'/g)]
        .map((match) => path.join(path.dirname(MANIFEST), match[1]));

export const workspaceCss = () => [read(MANIFEST), ...workspaceCssParts().map(read)].join('\n');
