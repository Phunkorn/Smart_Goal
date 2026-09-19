import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/*
 * วันที่บนแกนของกราฟต้องเป็นภาษาไทย
 *
 * format() คืนชื่อเดือนภาษาอังกฤษเสมอไม่ว่าตั้ง locale อะไรไว้ ต้องใช้
 * translatedFormat() แกนวันจึงเคยขึ้นเป็น "5 Sep" ปนอยู่กับข้อความไทยทั้งหน้า
 */
test('operational date and month labels are rendered in Thai', async () => {
    const service = await read('app/Services/OperationalWorkloadReportService.php');
    const scope = await read('app/Support/OperationalReportScope.php');
    // ป้ายเดือนย้ายไปอยู่ที่ ReportMonth ซึ่งรายงานโปรเจกต์ใช้ร่วมกัน
    const month = await read('app/Support/ReportMonth.php');

    assert.match(service, /locale\('th'\)->translatedFormat\('j M'\)/);
    assert.match(scope, /ReportMonth::label\(\$month\)/);
    assert.match(month, /locale\('th'\)->translatedFormat\('F'\)/);
    assert.doesNotMatch(service, /->format\('j M'\)|->format\('M'\)/, 'ห้ามใช้ format() สร้างป้ายที่ผู้ใช้อ่าน');
});

/*
 * ตารางสรุปรายวันและตารางเหตุการณ์มีเจ็ดคอลัมน์ บนจอกว้างต้องเลื่อนแนวนอนได้แทนการอัดคอลัมน์
 * ส่วนจอแคบต้องเปลี่ยนเป็นการ์ดทีละรายการ ไม่ใช่ปล่อยให้ min-width ดันหน้าจนล้น
 */
test('the operational tables scroll on desktop and never force their width on a phone', async () => {
    const css = await read('resources/css/pages/reports/operational.css');
    const mobile = css.slice(css.indexOf('@media (max-width: 760px)'));

    const events = Number(css.match(/\.operational-table--events \{ min-width: (\d+)px; \}/)?.[1]);
    assert.ok(events >= 860, 'ตารางเหตุการณ์เจ็ดคอลัมน์ต้องการอย่างน้อยราว 860px จึงจะอ่านออก');
    const daily = Number(css.match(/\.operational-table--daily \{ min-width: (\d+)px; \}/)?.[1]);
    assert.ok(daily >= 1400, 'ตารางสรุปรายวันสิบสามคอลัมน์ (เวลา ผู้ร่วมงาน สถานที่ เหตุผล) ต้องกว้างพอจะไม่บีบข้อความ');
    assert.match(css, /\.operational-table-scroll \{[^}]*overflow-x: auto/);
    // การ์ดตารางต้องไม่มีแถบเลื่อนขึ้นลง — overflow-x: auto ลาก overflow-y เป็น auto ตามไปด้วย
    assert.match(css, /\.operational-table-scroll \{[^}]*overflow-y: hidden/);
    assert.match(mobile, /\.operational-table--daily,\s*\.operational-table--events,\s*\.operational-table--team \{ min-width: 0; \}/);
});

/*
 * รูปโปรไฟล์ที่โหลดไม่ขึ้นต้องกลับไปเป็นตัวย่อชื่อ ไม่ใช่โชว์ไอคอนรูปเสีย
 *
 * คอลัมน์ profile_image เป็นเพียง path ในฐานข้อมูล ไม่ได้การันตีว่าไฟล์ยังอยู่จริง
 * เมื่อรูปหาย เบราว์เซอร์จะแสดงไอคอนรูปเสียพร้อมข้อความ alt เต็มการ์ด
 */
test('avatars fall back to initials when the profile image is gone', async () => {
    const shared = await read('resources/views/work-board/partials/avatar.blade.php');
    const memberCardView = await read('resources/views/work-board/components/member-card.blade.php');
    const module = await read('resources/js/components/avatar-fallback.js');

    // ตัวย่อชื่อต้องถูกเรนเดอร์เสมอ ไม่ใช่อยู่ในสาขา @else ของ @if
    assert.match(shared, /WorkBoardDesign::initials/, 'avatar partial ต้องมีตัวย่อชื่อ');
    assert.doesNotMatch(shared, /@else<span aria-hidden/, 'avatar partial ยังผูกตัวย่อชื่อไว้กับสาขา else');
    assert.match(shared, /data-avatar-image/, 'avatar partial ต้องติดป้ายให้ตัวสำรองรู้จัก');

    // การ์ดสมาชิกต้องใช้ partial ตัวเดียวกัน ไม่ใช่เขียนรูปโปรไฟล์ของตัวเองคู่ขนาน
    assert.match(memberCardView, /@include\('work-board\.partials\.avatar'/, 'member card ต้องใช้ avatar partial ร่วมกัน');

    // ต้องซ้อนช่องเดียวกัน ไม่งั้นตัวย่อชื่อจะโผล่ใต้รูปตลอดเวลา
    const memberCard = await read('resources/css/components/member-card.css');
    assert.match(memberCard, /\.wb-avatar > \* \{ grid-area: 1 \/ 1/);

    // ข้อความกับลูกศรของปุ่มในการ์ดอ่านต่อเนื่องกัน ไม่ถูกดันไปคนละฝั่ง
    const action = memberCard.match(/\.wb-member-card__action \{([^}]*)\}/)?.[1] ?? '';
    assert.doesNotMatch(action, /justify-content: space-between/, 'ห้ามดันข้อความกับลูกศรไปคนละฝั่ง');
    assert.match(action, /justify-content: center/);

    // ต้องดักในเฟส capture เพราะ error ของ <img> ไม่ bubble
    assert.match(module, /addEventListener\(\s*'error'[\s\S]*?true\s*\)/);
});

/*
 * การ์ดของรายงานปฏิบัติงานไม่ได้ใช้ .report-dashboard-card จึงต้องประกาศระยะขอบในเอง
 * ไม่งั้นหัวข้อและตารางจะชิดขอบการ์ดทั้งสี่ด้าน
 */
test('every operational card keeps its text away from the card edge', async () => {
    const css = await read('resources/css/pages/reports/operational.css');
    const blade = await read('resources/views/reports/operational.blade.php');

    assert.match(blade, /class="report-panel operational-card"/);
    assert.match(css, /\.operational-card \{[^}]*padding: \d+px/, 'การ์ดนี้ต้องประกาศระยะขอบในเอง');
});

/*
 * จอแคบ: ตัวกรองเต็มแถว และตารางเปลี่ยนเป็นการ์ดทีละรายการ โดยป้ายคอลัมน์มาจาก data-label
 */
test('the operational page keeps its filter and tables readable on a phone', async () => {
    const css = await read('resources/css/pages/reports/operational.css');
    const mobile = css.slice(css.indexOf('@media (max-width: 760px)'));

    assert.match(mobile, /\.operational-filter__field \{ flex: 1 1 100%; \}/);
    assert.match(mobile, /\.operational-table,[\s\S]{0,200}display: block/);
    assert.match(mobile, /\.operational-table thead \{ display: none; \}/);
    assert.match(mobile, /content: attr\(data-label\)/);

    // กฎของหน้าจอเก่า (การ์ดรายคน ตัวกรองช่วงเวลา) ต้องไม่เหลือค้าง
    assert.doesNotMatch(css, /report-people-card|report-operational-days|report-operational \.report-filter/);
});

test('every operational page shares one header, one entry and the same table rows', async () => {
    const [overview, daily, frequent, delays, team, header, entry] = await Promise.all([
        read('resources/views/reports/operational.blade.php'),
        read('resources/views/reports/operational/daily.blade.php'),
        read('resources/views/reports/operational/frequent.blade.php'),
        read('resources/views/reports/operational/delays.blade.php'),
        read('resources/views/reports/operational/team.blade.php'),
        read('resources/views/reports/components/operational/header.blade.php'),
        read('resources/js/pages/reports/operational.js'),
    ]);

    for (const page of [overview, daily, frequent, delays, team]) {
        assert.match(page, /@include\('reports\.components\.operational\.header'/);
        assert.match(page, /@vite\('resources\/js\/pages\/reports\/operational\.js'\)/);
    }

    // ภาพรวมทีมกับ Overview รายบุคคลใช้แถวกราฟตัวเดียวกัน ไม่มี markup ของกราฟชุดที่สอง
    assert.match(overview, /@include\('reports\.components\.operational\.charts'/);
    assert.match(team, /@include\('reports\.components\.operational\.charts'/);
    assert.doesNotMatch(overview + team, /<canvas/, 'canvas ต้องอยู่ใน component ของกราฟที่เดียว');

    // ตัวกรองเดือน/พนักงานใช้ดร็อปดาวน์ร่วมของระบบ ไม่ใช่ <select> เปล่า
    assert.equal((header.match(/data-sg-select/g) || []).length, 2);
    assert.match(entry, /import \{initSelectDropdowns\} from '\.\.\/\.\.\/components\/select-dropdown\.js'/);
    assert.match(await read('resources/css/pages/report-operational.css'), /@import '\.\.\/components\/select-dropdown\.css'/);

    // Overview กับรายงานฉบับเต็มใช้ตารางรายวันตัวเดียวกัน ไม่มี markup ชุดที่สอง
    assert.match(overview, /@include\('reports\.components\.operational\.daily-items-table'/);
    assert.match(daily, /@include\('reports\.components\.operational\.daily-items-table'/);
    assert.match(overview, /@include\('reports\.components\.operational\.frequent-table'/);
    assert.match(frequent, /@include\('reports\.components\.operational\.frequent-table'/);

    // ตัวกรองเหลือเดือน (และพนักงานสำหรับหัวหน้า/admin) ไม่มีช่วงเวลาชุดเดิม
    assert.match(header, /name="month"/);
    assert.doesNotMatch(header, /name="period"|start_date|end_date/);

    assert.match(entry, /import \{initCsvMenu, initReportFilter\} from '\.\/operational-controls\.js'/);
    assert.doesNotMatch(entry, /people-modal|table-pager|todayMembers/, 'โค้ดของหน้าจอเก่าต้องไม่ถูกโหลดอีก');
});
