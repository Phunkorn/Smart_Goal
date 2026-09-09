import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/*
 * โทนของการ์ดทางเข้ารายงานต้องอยู่ในสไตล์ชีตที่หน้ารวมรายงานโหลดจริง
 *
 * reports.css นำเข้าเฉพาะ reports/landing.css ส่วน reports/operational.css ถูกโหลด
 * ที่หน้ารายงานภาระงานปฏิบัติการเท่านั้น กฎ .report-choice--operational ที่เคยอยู่
 * ในไฟล์นั้นจึงไม่เคยมีผลกับการ์ดบนหน้ารวม การ์ดจึงตกกลับไปใช้สีเริ่มต้นและดูเหมือน
 * การ์ด "ภาพรวมองค์กร" ทุกประการ
 */
test('every report landing card tone ships in the stylesheet the landing page loads', async () => {
    const entry = await read('resources/css/pages/reports.css');
    const landing = await read('resources/css/pages/reports/landing.css');
    const operational = await read('resources/css/pages/reports/operational.css');
    // รายการการ์ดถูกประกอบใน ReportController::landingCards() ไม่ใช่ใน Blade แล้ว
    // เพราะ "ใครเห็นการ์ดไหน" เป็นเรื่องสิทธิ์ที่ต้องตัดสินฝั่งเซิร์ฟเวอร์
    const cardSource = await read('app/Http/Controllers/ReportController.php');

    assert.match(entry, /\.\/reports\/landing\.css/);
    assert.doesNotMatch(entry, /operational\.css/, 'หน้ารวมรายงานไม่ได้โหลดสไตล์ของหน้ารายงานปฏิบัติการ');

    // ทุก tone ที่ Blade ส่งเข้ามาต้องมีกฎรองรับใน landing.css
    const tones = [...cardSource.matchAll(/'tone'\s*=>\s*'([a-z-]+)'/g)].map((match) => match[1]);
    assert.ok(tones.length >= 3, 'ต้องมีการ์ดอย่างน้อยสามใบให้ตรวจ');

    for (const tone of tones.filter((name) => name !== 'organization')) {
        assert.match(
            landing,
            new RegExp(`\\.report-choice--${tone} \\{[^}]*--choice:`),
            `tone "${tone}" ยังไม่มีสีของตัวเองใน landing.css`,
        );
    }

    assert.doesNotMatch(operational, /\.report-choice--/, 'ห้ามทิ้งกฎของการ์ดไว้ในไฟล์ที่หน้ารวมไม่โหลด');
});

/*
 * การ์ดสามใบต้องไม่ซ้ำสีกัน
 *
 * สีคือสิ่งเดียวที่แยกการ์ดสามใบออกจากกันตั้งแต่แรกเห็น ถ้าสองใบใช้สีเดียวกัน
 * ผู้ใช้ต้องอ่านหัวข้อทุกใบก่อนถึงจะรู้ว่ากดอันไหน
 */
test('no two report landing cards share the same accent colour', async () => {
    const landing = await read('resources/css/pages/reports/landing.css');

    const base = landing.match(/\.report-choice \{[^}]*--choice:\s*(#[0-9a-f]{3,8})/i)?.[1];
    const variants = [...landing.matchAll(/\.report-choice--([a-z-]+) \{[^}]*--choice:\s*(#[0-9a-f]{3,8})/gi)]
        .map((match) => match[2].toLowerCase());

    assert.ok(base, 'การ์ดต้องมีสีเริ่มต้น');

    const colours = [base.toLowerCase(), ...variants];
    assert.equal(new Set(colours).size, colours.length, `สีซ้ำกัน: ${colours.join(', ')}`);
});

/*
 * จำนวนการ์ดไม่คงที่ กริดจึงต้องนับคอลัมน์เอง
 *
 * viewer เห็นสองใบ ส่วน admin และหัวหน้าแผนกเห็นสามใบ กริดสองคอลัมน์ตายตัวทิ้งใบที่สาม
 * ไว้ลำพังครึ่งแถวโดยมีที่ว่างข้าง ๆ
 */
test('the landing grid adapts to however many cards the viewer may open', async () => {
    const landing = await read('resources/css/pages/reports/landing.css');

    const grid = landing.match(/\.report-landing__grid \{([^}]*)\}/)?.[1] ?? '';

    assert.match(grid, /grid-template-columns:\s*repeat\(auto-fit,\s*minmax\([^)]+\)\)/);
    assert.doesNotMatch(grid, /repeat\(\d+,/, 'ห้ามตรึงจำนวนคอลัมน์ไว้ตายตัว');
});
