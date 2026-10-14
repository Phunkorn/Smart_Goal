import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

/*
 * กล่องแชทของ Task Workspace ต้องไม่มีแถบเลื่อนแนวนอน และเปิดมาต้องอยู่ที่ข้อความล่าสุด
 *
 * ปัญหาที่ผู้ใช้เจอจริง:
 * 1. ตอบกลับข้อความยาว ๆ แล้วทั้งกล่องแชทมีแถบเลื่อนแนวนอน ฟองฝั่งขวาถูกตัดหาย
 *    ข้อความอ้างอิงเป็น nowrap ส่วนคอลัมน์ของรายการเป็น auto คอลัมน์จึงกว้างตามข้อความนั้น
 * 2. ข้อความท้าย ๆ ที่มีรูปทำให้เปิดมาแล้วไม่อยู่ที่ข้อความล่าสุด เพราะกรอบรูปสูง 0
 *    จนกว่ารูปจะโหลดเสร็จ รายการจึงยาวขึ้นหลังจากเลื่อนไปท้ายสุดแล้ว
 * 3. บนจอแคบรายการแชทยาวตามเนื้อหา ต้องเลื่อนผ่านทุกข้อความก่อนถึงข้อความล่าสุด
 *
 * jsdom ไม่คำนวณ layout จึงพิสูจน์ความกว้างจริงไม่ได้ test นี้ตรึงกฎ CSS ที่เป็นตัวแก้
 * ส่วนผลจริงวัดบนเบราว์เซอร์ (scrollWidth เท่ากับ clientWidth ที่ 1920/1366/768/375)
 */

const CSS_FILE = 'resources/css/components/task-workspace/workspace-modal.css';
const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');
const withoutComments = (source) => source.replace(/\/\*[\s\S]*?\*\//g, '');

/** อ่านค่า property จากกฎที่ selector ตรงเป๊ะ ถ้าประกาศซ้ำใช้ตัวท้ายสุด */
function declaration(source, selector, property) {
    const rules = [...withoutComments(source).matchAll(/([^{}]+)\{([^{}]*)\}/g)]
        .filter(([, head]) => head.split(',').some((part) => part.trim() === selector));

    for (const [, , body] of rules.reverse()) {
        const found = body.match(new RegExp(`(?:^|;)\\s*${property}\\s*:([^;]+)`));
        if (found) return found[1].trim();
    }

    return null;
}

/** เนื้อหาของ @media ก้อนแรกที่ตรงเงื่อนไข นับวงเล็บปีกกาเพื่อหาจุดปิดจริง */
function mediaBlock(source, condition) {
    const clean = withoutComments(source);
    const start = clean.indexOf(`@media ${condition}`);
    assert.notEqual(start, -1, `ต้องมี @media ${condition}`);

    const open = clean.indexOf('{', start);
    let depth = 0;
    for (let index = open; index < clean.length; index += 1) {
        if (clean[index] === '{') depth += 1;
        if (clean[index] === '}') depth -= 1;
        if (depth === 0) return clean.slice(open + 1, index);
    }

    return '';
}

test('คอลัมน์ของรายการแชทบีบได้ ฟองจึงไม่กว้างตามข้อความที่ตัดบรรทัดไม่ได้', async () => {
    const css = await read(CSS_FILE);

    assert.match(
        declaration(css, '.task-workspace__timeline-items', 'grid-template-columns') || '',
        /^minmax\(\s*0\s*,\s*1fr\s*\)$/,
        'คอลัมน์ต้องเป็น minmax(0, 1fr) ไม่ใช่ auto',
    );
    assert.equal(declaration(css, '.task-workspace .task-timeline-entry__content', 'min-width'), '0');
    assert.equal(
        declaration(css, '.task-workspace .task-timeline-entry__bubble', 'max-width'),
        '100%',
        'ฟองต้องไม่กว้างเกินคอลัมน์ ไม่งั้นข้อความอ้างอิงแบบ nowrap ดันฟองล้นและ ellipsis ไม่ทำงาน',
    );
    assert.equal(declaration(css, '.task-workspace .task-timeline-entry__bubble', 'min-width'), '0');
    assert.equal(declaration(css, '.task-workspace .task-timeline-entry__quote-body', 'min-width'), '0');
    assert.equal(declaration(css, '.task-workspace .task-timeline-entry__quote-text', 'text-overflow'), 'ellipsis');
});

test('กรอบรูปในแชทมีขนาดตายตัวตั้งแต่ก่อนรูปโหลด รายการจึงไม่ยาวขึ้นทีหลัง', async () => {
    const css = await read(CSS_FILE);
    const tile = '.task-workspace .task-timeline-entry__image';

    assert.equal(declaration(css, tile, 'width'), '170px');
    assert.match(declaration(css, tile, 'aspect-ratio') || '', /^\d+\s*\/\s*\d+$/, 'กรอบต้องจองความสูงด้วย aspect-ratio');
    assert.equal(declaration(css, tile, 'max-width'), '100%', 'กรอบต้องหดตามฟองบนจอแคบ');
    assert.equal(
        declaration(css, `${tile} img`, 'height'),
        '100%',
        'รูปต้องเติมกรอบ ไม่ใช่ height: auto ที่ทำให้กรอบสูงตามรูปหลังโหลดเสร็จ',
    );
    assert.equal(declaration(css, `${tile} img`, 'object-fit'), 'cover');
});

test('จอแคบ: รายการแชทเลื่อนในกรอบของตัวเอง ไม่ยาวตามเนื้อหา', async () => {
    const css = await read(CSS_FILE);
    const mobile = mediaBlock(css, '(max-width: 1099px)');

    assert.match(
        declaration(mobile, '.task-workspace__timeline-items', 'max-height') || '',
        /^min\(\s*60dvh\s*,\s*520px\s*\)$/,
        'ต้องจำกัดความสูงให้การ์ดแชทพอดีหนึ่งหน้าจอ',
    );
    assert.equal(
        declaration(mobile, '.task-workspace__timeline-items', 'overflow'),
        null,
        'ต้องไม่ปิด scroll ของรายการแชทบนจอแคบ',
    );
    assert.equal(declaration(css, '.task-workspace__timeline-items', 'overflow'), 'auto');
});
