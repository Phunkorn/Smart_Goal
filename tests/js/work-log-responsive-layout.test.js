import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

/*
 * โครงหน้าบันทึกงานประจำวันบนจอแคบ (ครึ่งจอ ~800–960px)
 *
 * ตรวจจากซอร์ส CSS เพราะ jsdom ไม่คำนวณ layout จึงพิสูจน์ได้แค่ว่ากฎที่ทำให้พังไม่กลับมา
 * ไม่ได้พิสูจน์ว่าหน้าตาถูกต้อง — เรื่องนั้นต้องเปิดเบราว์เซอร์จริง
 *
 * อาการเดิม: หัวหน้าเป็น grid สองคอลัมน์ `minmax(0,1fr) auto` คอลัมน์ขวากว้างตามตัวควบคุม
 * คอลัมน์ซ้ายเหลือไม่ถึง 180px หัวเรื่องตัดคำทีละพยางค์ แท็บล้นไปซ้อนตัวเลือกสมาชิก
 * และรายการงานกลายเป็นตาราง 920px ที่ต้องเลื่อนแนวนอน
 */
const codeOf = (relativePath) => readFileSync(new URL(`../../${relativePath}`, import.meta.url), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');

const layout = codeOf('resources/css/pages/daily-logs/layout.css');
const responsive = codeOf('resources/css/pages/daily-logs/responsive.css');
const timeline = codeOf('resources/css/pages/daily-logs/timeline.css');

/** คืนเนื้อในของกฎแรกที่ selector ตรงกันทั้งบรรทัด */
const ruleOf = (css, selector) => {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = css.match(new RegExp(`(?:^|[}\\n])\\s*${escaped}\\s*\\{([^}]*)\\}`));

    assert.ok(match, `ไม่พบกฎ ${selector}`);

    return match[1];
};

test('หัวหน้าไม่ใช้ grid ที่คอลัมน์ตัวควบคุมบีบหัวเรื่องกับแท็บ', () => {
    const header = ruleOf(layout, '.daily-log__header');

    assert.match(header, /display:flex/);
    assert.match(header, /flex-wrap:wrap/);
    assert.doesNotMatch(header, /grid-template-columns/);
    assert.doesNotMatch(layout + responsive, /\.daily-log__(tabs|controls)[^{]*\{[^}]*grid-(column|row):/);
});

test('หัวเรื่องกินเต็มแถว และแท็บไม่ตัดคำหรือหดจนซ้อนกัน', () => {
    assert.match(ruleOf(layout, '.daily-log__heading'), /flex:1 1 100%/);
    assert.match(ruleOf(layout, '.daily-log__tabs'), /flex:0 0 auto/);
    assert.match(ruleOf(layout, '.daily-log__tabs a'), /white-space:nowrap/);
});

test('ตัวควบคุมไม่ถูกบังคับเต็มแถวที่ 900px แต่ลงแถวใหม่เองเมื่อไม่พอ', () => {
    assert.doesNotMatch(responsive, /max-width:\s*900px/);
});

test('รูปแบบการ์ดรายการงานเลือกจากความกว้างของกรอบ ไม่ใช่ของหน้าจอ', () => {
    assert.match(ruleOf(timeline, '.daily-log .log-timeline__scroll'), /container-type:inline-size/);
    assert.match(ruleOf(timeline, '.daily-log .log-timeline__scroll'), /container-name:log-timeline/);

    const queries = timeline.match(/@container log-timeline /g) ?? [];

    assert.equal(queries.length, 3,'ต้องมีสามช่วง: การ์ดทั้งหมด / การ์ดสองแถว / การ์ดคอลัมน์เดียว');
    assert.doesNotMatch(timeline, /@media\s*\(max-width:\s*575px\)/, 'รูปแบบจอโทรศัพท์ย้ายไปอยู่ใน @container แล้ว ต้องไม่เหลือสองแหล่ง');
});

test('ต่ำกว่า 980px การ์ดเลิกบังคับความกว้างตารางและซ่อนหัวคอลัมน์', () => {
    const block = timeline.slice(timeline.indexOf('@container log-timeline (width < 980px)'));
    const end = block.indexOf('@container', 1);
    const compact = block.slice(0, end);

    assert.match(compact, /\.log-timeline__columns\s*\{\s*display:none/);
    assert.match(compact, /\.log-card\s*\{[^}]*min-width:0/);
    assert.match(compact, /\.log-card__actions\s*\{[^}]*flex-wrap:wrap/);
});
