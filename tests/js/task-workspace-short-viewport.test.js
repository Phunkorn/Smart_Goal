import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

/*
 * Task Workspace บนจอกว้างแต่เตี้ย
 *
 * โน้ตบุ๊กที่ Windows ขยายหน้าจอ 125–150% กว้างเกิน 1100px จึงได้เลย์เอาต์ Desktop
 * แต่สูงเพียง 520–700px ส่วนหัว แถบสรุป ช่องพิมพ์ และ footer สูงคงที่ รายการแชทซึ่งเป็น
 * ส่วนเดียวที่หดได้จึงเหลือที่ไม่ถึงหนึ่งข้อความ และทั้งโมดัลดูใหญ่เกินจอ
 *
 * jsdom ไม่คำนวณ layout test นี้จึงตรึงกฎ CSS ที่คืนความสูงให้รายการแชท
 * ผลจริงต้องดูบนเบราว์เซอร์ที่ความสูง 520 / 650 / 768px
 */

const css = readFileSync(new URL('../../resources/css/components/task-workspace/workspace-modal.css', import.meta.url), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

const CONDITION = '@media (min-width: 1100px) and (max-height: 820px)';

function shortViewportBlock() {
    const start = css.indexOf(CONDITION);
    assert.notEqual(start, -1, `ต้องมี ${CONDITION}`);

    const open = css.indexOf('{', start);
    let depth = 0;
    for (let index = open; index < css.length; index += 1) {
        if (css[index] === '{') depth += 1;
        if (css[index] === '}') depth -= 1;
        if (depth === 0) return css.slice(open + 1, index);
    }

    return '';
}

function rule(block, selector) {
    const found = [...block.matchAll(/([^{}]+)\{([^{}]*)\}/g)].find(([, head]) => head.trim() === selector);
    assert.ok(found, `ต้องมีกฎ ${selector} ในบล็อกจอเตี้ย`);

    return found[2];
}

const pixels = (body, property) => Number(body.match(new RegExp(`(?:^|;)\\s*${property}\\s*:\\s*(\\d+)px`))?.[1]);

test('จอกว้างแต่เตี้ยใช้ความสูงของจอได้มากขึ้น', () => {
    const block = shortViewportBlock();

    assert.match(rule(block, '.task-workspace-modal'), /padding:\s*12px/);
    assert.match(rule(block, '.task-workspace'), /height:\s*min\(900px,\s*calc\(100dvh - 24px\)\)/, 'ความสูงต้องหักเท่ากับ padding บนล่างของ overlay');
});

test('ส่วนที่สูงคงที่ถูกย่อ ความสูงที่ได้คืนตกเป็นของรายการแชท', () => {
    const block = shortViewportBlock();

    assert.match(rule(block, '.task-workspace__subtitle'), /display:\s*none/);
    assert.ok(pixels(rule(block, '.task-workspace .task-timeline__compose textarea'), 'min-height') < 100, 'ช่องพิมพ์ต้องเตี้ยกว่าค่าของจอปกติ (100px)');
    assert.ok(pixels(rule(block, '.task-workspace__actions button'), 'min-height') < 38, 'ปุ่มใน footer ต้องเตี้ยกว่าค่าของจอปกติ (38px)');
});

test('กฎจอเตี้ยไม่ไปแตะการเลื่อนของรายการแชท', () => {
    const items = rule(shortViewportBlock(), '.task-workspace__timeline-items');

    assert.ok(! /overflow|max-height|grid-template-columns/.test(items), 'การเลื่อนและความกว้างคอลัมน์ต้องมาจากกฎหลักที่เดียว');
});
