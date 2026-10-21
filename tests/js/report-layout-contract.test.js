import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const projectsCss = readFileSync(new URL('../../resources/css/pages/reports/projects.css', import.meta.url), 'utf8');
const sharedCss = readFileSync(new URL('../../resources/css/pages/reports/shared.css', import.meta.url), 'utf8');
const operationalCss = readFileSync(new URL('../../resources/css/pages/reports/operational.css', import.meta.url), 'utf8');

const mediaBlock = (css, query) => {
    const start = css.indexOf(`@media (${query})`);
    assert.notEqual(start, -1, `Missing @media (${query})`);
    const openingBrace = css.indexOf('{', start);
    let depth = 0;
    for (let index = openingBrace; index < css.length; index += 1) {
        if (css[index] === '{') depth += 1;
        if (css[index] === '}') depth -= 1;
        if (depth === 0) return css.slice(openingBrace + 1, index);
    }
    assert.fail(`Unclosed @media (${query})`);
};

test('report pages use the full width and share chart state styles', () => {
    // รายงานโปรเจกต์และรายงานปฏิบัติงานกว้างเต็มพื้นที่เท่ากัน
    assert.match(projectsCss, /\.project-report \{[^}]*width: 100%;/);
    assert.match(sharedCss, /\.report-page \{ width:100%;/);
    for (const css of [projectsCss, sharedCss, operationalCss]) {
        assert.doesNotMatch(css, /1560px/);
    }
    assert.match(sharedCss, /data-chart-kind="doughnut"/);
    assert.match(sharedCss, /prefers-reduced-motion:reduce/);
});

test('ready-state skeleton handoff shares the chart stagger and reduced motion disables it', () => {
    assert.match(sharedCss, /report-chart-skeleton[^}]*opacity \.28s var\(--report-ease,ease\) var\(--report-card-delay,0ms\)[^}]*visibility 0s linear calc\(var\(--report-card-delay,0ms\) \+ \.28s\)/);
    assert.match(sharedCss, /data-chart-state="ready"[^}]*visibility:hidden[^}]*opacity:0/);
    assert.doesNotMatch(sharedCss, /data-chart-state="ready"[^}]*display:none/);
    assert.match(mediaBlock(sharedCss, 'prefers-reduced-motion:reduce'), /report-chart-skeleton,.report-chart-wrap[^}]*transition:none/);
    // โครงที่ซ่อนแล้วหยุดแสงวิ่ง ไม่วิ่งต่อไปตลอดอายุของหน้า
    assert.match(sharedCss, /\[data-chart-state="ready"\] \.report-chart-skeleton::after \{ animation:none; \}/);
});

/*
 * การเข้าฉากของ Dashboard — การ์ดตัวเลขทยอยขึ้นทีละใบ ตารางจางเข้าทั้งก้อน เส้นโค้งเดียวกันทั้งหน้า
 * และปิดทั้งหมดเมื่อผู้ใช้ขอลดการเคลื่อนไหว
 */
test('dashboard entrance: staggered kpi rise, one table fade, one easing, reduced motion turns it off', () => {
    assert.match(sharedCss, /--report-ease:cubic-bezier\(0\.22, 1, 0\.36, 1\)/);
    assert.match(sharedCss, /\.project-report__kpis > \.project-report__kpi,\n\.operational-kpis > \.operational-kpi \{ animation:report-rise \.48s var\(--report-ease\) backwards; animation-delay:calc\(var\(--report-stagger,0\) \* 40ms\); \}/);
    // backwards ไม่ใช่ both/forwards — transform ที่ค้างไว้จะทับการยกการ์ดตอน hover
    assert.doesNotMatch(sharedCss, /animation:report-rise[^;]*(both|forwards)/);
    for (const index of [2, 3, 4, 5, 6, 7]) {
        assert.ok(sharedCss.includes(`.project-report__kpis > :nth-child(${index}),.operational-kpis > :nth-child(${index}) { --report-stagger:${index - 1}; }`), `stagger ${index}`);
    }
    // ตารางจางเข้าครั้งเดียวทั้งการ์ด ไม่ไล่ทีละแถว และเฉพาะหน้า Dashboard (หน้ารายละเอียดไม่มีแถวกราฟนำหน้า)
    assert.match(sharedCss, /\.project-report__charts ~ \.project-report__table-card,\n\.report-operational > \.operational-card,\n\.report-operational > \.operational-grid--tops \{ animation:report-fade \.45s var\(--report-ease\) \.12s backwards; \}/);
    assert.doesNotMatch(sharedCss, /(^|[\s,>])(tr|tbody|li)(\b|:)[^{]*\{[^}]*animation:report-/m);
    // เคลื่อนเฉพาะ opacity/transform จึงไม่ดันเลย์เอาต์
    for (const name of ['report-rise', 'report-fade']) {
        const frames = sharedCss.split('\n').find((line) => line.startsWith(`@keyframes ${name} {`)) ?? '';
        assert.ok(frames, name);
        assert.doesNotMatch(frames, /height|width|margin|top|padding/);
    }

    const reduced = mediaBlock(sharedCss, 'prefers-reduced-motion:reduce');
    assert.match(reduced, /\.project-report__kpis > \.project-report__kpi,\.operational-kpis > \.operational-kpi,\.project-report__charts ~ \.project-report__table-card,\.report-operational > \.operational-card,\.report-operational > \.operational-grid--tops \{ animation:none; \}/);
});

/*
 * รายงานปฏิบัติงานประจำเดือน — กริดตามแบบ
 *
 * แถวกราฟ: ชั่วโมงงานรายวันกว้างสองส่วน สัดส่วนประเภทงานหนึ่งส่วน
 * แถว Top: สองการ์ดเท่ากัน และทุกแถวยุบเป็นคอลัมน์เดียวบนแท็บเล็ต
 * KPI หกใบเต็มแถวบนจอกว้าง ลดเป็นสามและสองคอลัมน์ตามความกว้าง
 */
test('the operational overview grid follows the monthly layout and stacks on tablets', () => {
    assert.match(operationalCss, /\.operational-grid--charts \{ grid-template-columns: minmax\(0, 2fr\) minmax\(0, 1fr\); \}/);
    assert.match(operationalCss, /\.operational-grid--tops \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\); \}/);
    assert.match(operationalCss, /\.operational-kpis \{[^}]*grid-template-columns: repeat\(6, minmax\(0, 1fr\)\)/);

    const tablet = mediaBlock(operationalCss, 'max-width: 991.98px');
    assert.match(tablet, /\.operational-grid--charts,[\s\S]*grid-template-columns: minmax\(0, 1fr\)/);

    const card = operationalCss.match(/\.operational-card \{([^}]*)\}/)?.[1] ?? '';
    assert.match(card, /padding:\s*17px/);
    assert.match(card, /margin-bottom:\s*16px/);
});

/*
 * รายงานโปรเจกต์ประจำเดือน — KPI เต็มแถวบนจอกว้าง ลดเป็นสี่ แล้วคงสองคอลัมน์บนจอโทรศัพท์
 * ตารางกว้างเลื่อนแนวนอนในกรอบของตัวเอง หน้าไม่เลื่อนแนวนอนตาม
 */
test('the project report keeps seven kpis per row (4 + 3 below 1600px) and scrolls only its table sideways', () => {
    assert.match(projectsCss, /\.project-report__kpis \{[^}]*grid-template-columns: repeat\(7, minmax\(0, 1fr\)\)/);
    assert.match(mediaBlock(projectsCss, 'max-width: 1599px'), /grid-template-columns: repeat\(4, minmax\(0, 1fr\)\)/);
    assert.match(mediaBlock(projectsCss, 'max-width: 760px'), /\.project-report__kpis \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/);
    // จอโทรศัพท์คงสองคอลัมน์ (ไอคอนขึ้นไปอยู่บนข้อความ) ไม่ยุบเหลือคอลัมน์เดียว
    assert.match(mediaBlock(projectsCss, 'max-width: 430px'), /\.project-report__kpis \{ grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/);
    assert.match(mediaBlock(projectsCss, 'max-width: 430px'), /\.project-report__kpi \{[^}]*flex-direction: column/);
    assert.match(projectsCss, /\.project-report__table-scroll \{[^}]*overflow-x: auto/);
    assert.match(projectsCss, /\.project-report__table \{[^}]*min-width: 1340px/);
});
