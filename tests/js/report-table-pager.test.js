import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {initTablePager, paginationState} from '../../resources/js/pages/reports/table-pager.js';
import {mountDom} from './helpers/dom.js';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/*
 * ตารางภาระงานรายคนแสดงครั้งละ 10 แถว
 *
 * ข้อมูลเป็นผลสรุปที่คำนวณในหน่วยความจำ ไม่ใช่ paginator ของฐานข้อมูล การกลับไป
 * ถามเซิร์ฟเวอร์ทุกครั้งที่เปลี่ยนหน้าจึงต้องคำนวณสรุปทั้งชุดใหม่โดยไม่ประหยัดอะไร
 */

test('pagination maths never lands the reader on a page that does not exist', () => {
    assert.deepEqual(paginationState({total: 25, pageSize: 10, page: 1}), {
        page: 1, pages: 3, start: 0, end: 10, needsPager: true, label: '1 / 3',
    });

    // หน้าสุดท้ายมีแถวไม่ครบ end ต้องหยุดที่จำนวนจริง ไม่ใช่ที่ขอบของหน้า
    assert.equal(paginationState({total: 25, pageSize: 10, page: 3}).end, 25);

    // ขอหน้าที่เกินมาต้องถูกบีบกลับ ไม่ใช่คืนตารางว่าง
    assert.equal(paginationState({total: 25, pageSize: 10, page: 99}).page, 3);
    assert.equal(paginationState({total: 25, pageSize: 10, page: 0}).page, 1);

    // ตารางที่พอดีหน้าเดียวไม่ต้องมีปุ่มให้กดเปล่า
    assert.equal(paginationState({total: 10, pageSize: 10}).needsPager, false);
    assert.equal(paginationState({total: 0, pageSize: 10}).pages, 1);
});

const mountTable = (t, rowCount) => {
    const env = mountDom();
    t.after(env.cleanup);

    const rows = Array.from({length: rowCount}, (_, index) => `<tr data-operational-member-row><td>คนที่ ${index + 1}</td></tr>`).join('');

    env.document.body.innerHTML = `
        <table data-operational-member-table data-page-size="10"><tbody>${rows}</tbody></table>
        <nav data-operational-member-pager hidden>
            <button type="button" data-operational-member-previous></button>
            <span data-operational-member-page></span>
            <button type="button" data-operational-member-next></button>
        </nav>`;

    const pager = initTablePager({
        table: env.document.querySelector('[data-operational-member-table]'),
        pager: env.document.querySelector('[data-operational-member-pager]'),
        rowSelector: '[data-operational-member-row]',
        pageLabel: env.document.querySelector('[data-operational-member-page]'),
        previous: env.document.querySelector('[data-operational-member-previous]'),
        next: env.document.querySelector('[data-operational-member-next]'),
    });

    return {
        ...env,
        pager,
        visible: () => [...env.document.querySelectorAll('[data-operational-member-row]')].filter((row) => !row.hidden),
        label: () => env.document.querySelector('[data-operational-member-page]').textContent,
        nav: () => env.document.querySelector('[data-operational-member-pager]'),
        previous: () => env.document.querySelector('[data-operational-member-previous]'),
        next: () => env.document.querySelector('[data-operational-member-next]'),
        click: (button) => button.dispatchEvent(new env.window.Event('click', {bubbles: true})),
    };
};

test('the member table shows ten rows and moves forward and back', (t) => {
    const ui = mountTable(t, 25);

    assert.equal(ui.visible().length, 10);
    assert.equal(ui.label(), '1 / 3');
    assert.equal(ui.nav().hidden, false);
    assert.equal(ui.previous().disabled, true, 'หน้าแรกกดย้อนกลับไม่ได้');

    ui.click(ui.next());
    assert.equal(ui.label(), '2 / 3');
    assert.equal(ui.visible()[0].textContent.trim(), 'คนที่ 11');

    ui.click(ui.next());
    assert.equal(ui.label(), '3 / 3');
    assert.equal(ui.visible().length, 5, 'หน้าสุดท้ายแสดงเท่าที่เหลือจริง');
    assert.equal(ui.next().disabled, true, 'หน้าสุดท้ายกดถัดไปไม่ได้');

    ui.click(ui.previous());
    assert.equal(ui.label(), '2 / 3');
    assert.equal(ui.next().disabled, false);
});

test('a table that fits on one page hides the pager entirely', (t) => {
    const ui = mountTable(t, 7);

    assert.equal(ui.visible().length, 7);
    assert.equal(ui.nav().hidden, true);
});

/*
 * ปุ่มต้องมาจาก Blade ไม่ใช่สร้างจาก JavaScript
 * ตามแบบเดียวกับตัวแบ่งหน้าของการ์ดสรุปในปฏิทิน เพื่อไม่ให้มีเทมเพลตปุ่มชุดที่สอง
 */
test('the pager markup ships from Blade, not from the module', async () => {
    const blade = await read('resources/views/reports/components/operational-member-table.blade.php');
    const module = await read('resources/js/pages/reports/table-pager.js');

    assert.match(blade, /data-operational-member-pager/);
    assert.match(blade, /data-page-size="10"/);
    assert.match(blade, /data-operational-member-previous/);
    assert.match(blade, /data-operational-member-next/);

    assert.doesNotMatch(module, /createElement|innerHTML/, 'โมดูลต้องไม่สร้าง DOM ของปุ่มเอง');
});

test('the personal attention table reuses the ten-row pager', async () => {
    const blade = await read('resources/views/reports/components/personal-attention-table.blade.php');
    const module = await read('resources/js/pages/reports/my.js');
    const css = await read('resources/css/pages/reports/personal.css');

    assert.match(blade, /data-personal-attention-table data-page-size="10"/);
    assert.match(blade, /data-personal-attention-row/);
    assert.match(blade, /data-personal-attention-(?:previous|next)/);
    assert.match(module, /initTablePager/);
    assert.match(module, /rowSelector: '\[data-personal-attention-row\]'/);
    assert.doesNotMatch(blade, /table-responsive/, 'ตารางนี้ต้องไม่สร้างแถบเลื่อนแนวนอน');
    assert.match(css, /\.personal-report__table\s*\{[^}]*width:\s*100%[^}]*table-layout:\s*fixed/);
    assert.doesNotMatch(css, /\.personal-report__table\s*\{[^}]*min-width/, 'ตารางต้องย่อตามการ์ดได้');
    assert.match(css, /\.personal-report__table tbody tr\s*\{[^}]*display:\s*grid/, 'จอแคบต้องเปลี่ยนแถวเป็นบล็อกข้อมูล');
});

/*
 * วันที่บนแกนของกราฟต้องเป็นภาษาไทย
 *
 * format() คืนชื่อเดือนภาษาอังกฤษเสมอไม่ว่าตั้ง locale อะไรไว้ ต้องใช้
 * translatedFormat() แกนวันจึงเคยขึ้นเป็น "5 Sep" ปนอยู่กับข้อความไทยทั้งหน้า
 */
test('operational chart axis labels are rendered in Thai', async () => {
    const service = await read('app/Services/OperationalWorkloadReportService.php');

    assert.match(service, /locale\('th'\)->translatedFormat\('j M'\)/);
    assert.match(service, /locale\('th'\)->translatedFormat\('M'\)/);
    assert.doesNotMatch(service, /periodRow\(\$cursor->format\(/, 'ห้ามใช้ format() สร้างป้ายที่ผู้ใช้อ่าน');
});

/*
 * การ์ดตัวกรองต้องเรียงจากซ้ายและเติมความกว้างที่มี
 *
 * ของเดิมตรึงไว้สามคอลัมน์แล้วดันไปชิดขวา ครึ่งซ้ายของการ์ดจึงว่างเปล่า และหน้าที่มี
 * ตัวกรองสี่ตัวจะดันกลุ่มปุ่มตกไปคนละแถวแบบชิดซ้ายขณะที่ตัวกรองชิดขวา
 */
test('the report filter card fills its width instead of hugging the right edge', async () => {
    const css = await read('resources/css/pages/reports/organization.css');

    const form = css.match(/\.report-filter__form \{([^}]*)\}/)?.[1] ?? '';
    assert.match(form, /grid-template-columns:repeat\(auto-fit,minmax\(180px,1fr\)\)/);
    assert.doesNotMatch(form, /justify-content:end/, 'ห้ามดันตัวกรองไปชิดขวาจนซ้ายว่าง');
    assert.doesNotMatch(form, /repeat\(3,/, 'ห้ามตรึงจำนวนตัวกรองไว้ตายตัว');

    // ปุ่มอยู่แถวของตัวเองเสมอ ไม่ไปแย่งช่องของตัวกรอง
    const actions = css.match(/\.report-filter__actions \{([^}]*)\}/)?.[1] ?? '';
    assert.match(actions, /grid-column:1\/-1/);
    assert.match(actions, /border-top:1px solid/);
});

/*
 * ตารางภาระงานรายคนมีแปดคอลัมน์ มากกว่าตารางแผนกที่ยืมสไตล์มาใช้
 *
 * min-width ที่สืบทอดมาคือ 620px ซึ่งทำให้แปดคอลัมน์ถูกอัดอยู่ในความกว้างเท่านั้น
 * โดยไม่เกิดแถบเลื่อน หัวตารางจึงเบียดกันจนอ่านไม่ออกบนจอที่แคบกว่าราว 900px
 */
test('the operational table scrolls rather than cramming eight columns together', async () => {
    const css = await read('resources/css/pages/reports/operational.css');
    const shared = await read('resources/css/pages/reports/organization.css');

    const inherited = Number(shared.match(/\.report-department-table \{[^}]*min-width:(\d+)px/)?.[1]);
    const own = Number(css.match(/\.report-operational-table \.report-department-table \{[^}]*min-width:(\d+)px/)?.[1]);

    assert.ok(own > inherited, `ตารางแปดคอลัมน์ยังใช้ min-width ${own}px ซึ่งไม่กว้างกว่าของตารางแผนก`);
    assert.ok(own >= 860, 'แปดคอลัมน์ต้องการอย่างน้อยราว 860px จึงจะอ่านออก');

    // คอลัมน์แรกและสุดท้ายต้องไม่ชิดขอบการ์ดจนตัวเลขดูล้นออกนอกกรอบ
    assert.match(css, /\.report-operational-table \.report-department-table th:first-child/);
    assert.match(css, /\.report-operational-table \.report-department-table td:last-child/);
});

/*
 * รูปโปรไฟล์ที่โหลดไม่ขึ้นต้องกลับไปเป็นตัวย่อชื่อ ไม่ใช่โชว์ไอคอนรูปเสีย
 *
 * คอลัมน์ profile_image เป็นเพียง path ในฐานข้อมูล ไม่ได้การันตีว่าไฟล์ยังอยู่จริง
 * เมื่อรูปหาย เบราว์เซอร์จะแสดงไอคอนรูปเสียพร้อมข้อความ alt เต็มการ์ด
 */
test('avatars fall back to initials when the profile image is gone', async () => {
    const shared = await read('resources/views/work-board/partials/avatar.blade.php');
    const picker = await read('resources/views/reports/employees/index.blade.php');
    const module = await read('resources/js/components/avatar-fallback.js');

    // ตัวย่อชื่อต้องถูกเรนเดอร์เสมอ ไม่ใช่อยู่ในสาขา @else ของ @if
    assert.match(shared, /WorkBoardDesign::initials/, 'avatar partial ต้องมีตัวย่อชื่อ');
    assert.doesNotMatch(shared, /@else<span aria-hidden/, 'avatar partial ยังผูกตัวย่อชื่อไว้กับสาขา else');
    assert.match(shared, /data-avatar-image/, 'avatar partial ต้องติดป้ายให้ตัวสำรองรู้จัก');

    // หน้าเลือกพนักงานต้องใช้ partial ตัวเดียวกัน ไม่ใช่เขียนรูปโปรไฟล์ของตัวเองคู่ขนาน
    assert.match(picker, /@include\('work-board\.partials\.avatar'/, 'employee picker ต้องใช้ avatar partial ร่วมกัน');

    // ต้องซ้อนช่องเดียวกัน ไม่งั้นตัวย่อชื่อจะโผล่ใต้รูปตลอดเวลา
    const memberCard = await read('resources/css/components/member-card.css');
    assert.match(memberCard, /\.wb-avatar > \* \{ grid-area: 1 \/ 1/);

    // ต้องดักในเฟส capture เพราะ error ของ <img> ไม่ bubble
    assert.match(module, /addEventListener\(\s*'error'[\s\S]*?true\s*\)/);
});

/*
 * การ์ดพนักงานกว้างตามเนื้อหา ไม่ใช่ยืดเต็มหนึ่งในสามของหน้าจอ
 */
test('employee picker cards fill the row instead of leaving the right side empty', async () => {
    const css = await read('resources/css/pages/reports/employee-selection.css');

    const grid = css.match(/\.employee-picker__grid\{([^}]*)\}/)?.[1] ?? '';

    // เพดาน 1fr คือสิ่งที่ทำให้คอลัมน์แบ่งที่ว่างจนเต็มแถว ไม่ใช่หยุดโตแล้วกองไปทางซ้าย
    assert.match(grid, /repeat\(auto-fill,minmax\(240px,1fr\)\)/);
    assert.doesNotMatch(grid, /360px/, 'เพดานความกว้างคอลัมน์ทำให้เหลือที่ว่างทางขวา');
    assert.doesNotMatch(grid, /justify-content:start/, 'ไม่ต้องดันการ์ดไปชิดซ้ายเมื่อคอลัมน์เต็มแถวแล้ว');
    // auto-fill ยังต้องอยู่ ไม่งั้นการ์ดจะยักษ์เหมือนตอนตรึงจำนวนคอลัมน์ไว้ตายตัว
    assert.doesNotMatch(grid, /repeat\(3,/, 'ห้ามตรึงจำนวนคอลัมน์ไว้ตายตัว');
});

/*
 * การ์ดที่ใช้แค่ .report-panel ไม่ได้ระยะขอบในมาด้วย
 *
 * padding มาจาก .report-dashboard-card ซึ่งการ์ด "งานที่ทำบ่อยที่สุด" ไม่ได้ใช้
 * หัวข้อและรายการจึงชิดขอบการ์ดทั้งสี่ด้าน
 */
test('every report panel keeps its text away from the card edge', async () => {
    const css = await read('resources/css/pages/reports/operational.css');
    const blade = await read('resources/views/reports/operational.blade.php');

    // ยืนยันก่อนว่าการ์ดใบนี้ไม่ได้ใช้ .report-dashboard-card จริง จึงต้องมี padding เอง
    assert.match(blade, /class="report-panel report-operational-top"/);
    assert.match(css, /\.report-operational-top \{[^}]*padding:\d+px/, 'การ์ดนี้ต้องประกาศระยะขอบในเอง');
});

/*
 * ตารางรายคนอยู่นอก .report-dashboard จึงไม่ได้ gap ของกริดนั้น
 * grid-column ที่เคยประกาศไว้ไม่มีผลใด ๆ เพราะพ่อแม่ไม่ใช่กริด
 */
test('the operational page keeps its filter and board compact on a phone', async () => {
    const css = await read('resources/css/pages/reports/operational.css');
    const mobile = css.slice(css.lastIndexOf('@media (max-width: 760px)'));

    /*
     * กระดานรายคนเป็นการ์ดแล้ว กริดจึงยุบเหลือคอลัมน์เดียวเองด้วย auto-fill
     * ไม่ต้องมีกฎแปลงตารางเป็นการ์ดสำหรับกระดานนี้อีก และต้องไม่เหลือกฎของตารางเดิมทิ้งไว้
     */
    assert.match(css, /\.report-people-cards \{[^}]*grid-template-columns: repeat\(auto-fill/);
    assert.doesNotMatch(css, /report-operational-people__table/, 'ตารางรายคนถูกแทนที่ด้วยการ์ดแล้ว ห้ามเหลือกฎของมันไว้');

    /* ตารางรายวันของหน้าคนคนเดียวยังเป็นตารางจริง จึงยังต้องกลายเป็นการ์ดบนมือถือ */
    assert.match(mobile, /\.report-operational-days__table[\s\S]{0,200}display: block/);
    assert.match(mobile, /thead \{ display: none/);
    assert.match(mobile, /content: attr\(data-label\)/);

    // การ์ดต้องมีระยะขอบในของตัวเอง ไม่งั้นข้อความชิดขอบจนดูเหมือนล้นออกนอกกรอบ
    assert.match(css, /\.report-operational-people,[\s\S]{0,80}\{[^}]*padding: \d+px/);

    // ตัวกรองเหลือช่องเดียว จึงถูกบีบให้เป็นแถบเดียวแนวนอน ไม่ใช่การ์ดเต็มความกว้าง
    assert.match(css, /\.report-operational \.report-filter \{[^}]*display: flex/);
    assert.match(css, /\.report-operational \.report-filter__form \{[^}]*display: flex/);
    assert.match(css, /\.report-operational \.report-filter__heading p \{ display: none/);
});

test('both operational tables page ten rows with the shared pager', async () => {
    /*
     * หน้ารายงานปฏิบัติงานเหลือสองตาราง: รายชื่อพนักงาน และรายวันของคนที่เลือก
     * ทั้งคู่ต้องใช้ initTablePager ตัวเดียวกับรายงานอื่น ไม่ใช่โค้ดแบ่งหน้าชุดใหม่
     */
    const [people, days, entry] = await Promise.all([
        read('resources/views/reports/components/operational-people.blade.php'),
        read('resources/views/reports/components/operational-routine-days.blade.php'),
        read('resources/js/pages/reports/operational.js'),
    ]);

    for (const markup of [people, days]) {
        assert.match(markup, /data-page-size="10"/);
    }

    /*
     * กระดานรายคนเป็นกริดของการ์ด ไม่ใช่ตาราง แต่ยังใช้ตัวแบ่งหน้าตัวเดียวกัน
     * เพราะ initTablePager ซ่อนแถวด้วย hidden ล้วน ๆ ไม่ได้ผูกกับ tbody
     */
    assert.match(people, /class="report-people-cards" data-operational-people-table/);
    assert.match(people, /<article class="report-people-card[^"]*" data-operational-people-row/);

    assert.match(entry, /import \{initTablePager\} from '\.\/table-pager\.js'/);
    assert.match(entry, /data-operational-people-table/);
    assert.match(entry, /data-operational-days-table/);

    /*
     * เหลือกราฟใบเดียว คืองานของวันนี้รายคน ซึ่งเป็นข้อมูลชุดเดียวกับการ์ดด้านล่าง
     * id ต้องตรงกันสามที่: Blade, ตัวเชื่อมที่นี่ และคีย์ของ config
     */
    assert.match(entry, /operationalTodayMemberChart', key: 'todayMembers'/);
    assert.doesNotMatch(entry, /operationalRoutine/, 'กราฟชุดเดิมถูกถอดออกจากหน้านี้แล้ว');
    assert.doesNotMatch(entry, /operationalCategoryChart/, 'กราฟชุดของทั้งแผนกไม่ถูกใช้บนหน้านี้แล้ว');
});

/*
 * การ์ดพนักงานใช้คอมโพเนนต์เดียวกับไดเรกทอรีสมาชิกของแผนก
 *
 * หน้าเลือกพนักงานเคยมีการ์ดของตัวเองที่หน้าตาไม่เหมือนใคร ตอนนี้ทั้งสองหน้าอ่านสไตล์
 * จาก components/member-card.css ที่เดียว และหน้ารายงานเรนเดอร์เฉพาะรูป ชื่อ แผนก
 */
test('the employee picker reuses the shared member card', async () => {
    const blade = await read('resources/views/reports/employees/index.blade.php');
    const selection = await read('resources/css/pages/reports/employee-selection.css');
    const memberCard = await read('resources/css/components/member-card.css');

    assert.match(blade, /class="wb-member-card"/);
    assert.match(blade, /wb-member-card__portrait/);
    assert.match(blade, /wb-member-card__identity/);
    assert.match(blade, /wb-member-card__action/);

    // หน้านี้ถามแค่ว่าจะดูรายงานของใคร จึงต้องไม่มีบล็อกภาระงานหรือเวลาอัปเดตล่าสุด
    assert.doesNotMatch(blade, /wb-member-card__summary/, 'ห้ามแสดงสรุปภาระงานในหน้าเลือกพนักงาน');
    assert.doesNotMatch(blade, /wb-member-card__activity/, 'ห้ามแสดงเวลาอัปเดตล่าสุดในหน้าเลือกพนักงาน');
    assert.doesNotMatch(blade, /employee-card/, 'การ์ดชุดเดิมต้องถูกลบทิ้ง ไม่ใช่ทิ้งไว้คู่ขนาน');

    // สไตล์ต้องมาจากคอมโพเนนต์ร่วม ไม่ใช่คัดลอกมาไว้ในไฟล์ของหน้า
    assert.match(selection, /@import '\.\.\/\.\.\/components\/member-card\.css';/);
    assert.doesNotMatch(selection, /\.employee-card/, 'CSS ของการ์ดชุดเดิมต้องถูกลบทิ้ง');

    // ข้อความกับลูกศรอ่านต่อเนื่องกัน ไม่ถูกดันไปคนละฝั่งของการ์ด
    const action = memberCard.match(/\.wb-member-card__action \{([^}]*)\}/)?.[1] ?? '';
    assert.doesNotMatch(action, /justify-content: space-between/, 'ห้ามดันข้อความกับลูกศรไปคนละฝั่ง');
    assert.match(action, /justify-content: center/);

    // ช่องค้นหาไม่ต้องยาวเต็มจอ เคอร์เซอร์จะได้อยู่ใกล้ปุ่มค้นหา
    assert.match(selection, /\.employee-picker__filters\s*\{[^}]*width:\s*fit-content/);
    assert.match(selection, /grid-template-columns:\s*minmax\(260px, 400px\) auto auto/);
    assert.match(selection, /justify-content:\s*start/);
});
