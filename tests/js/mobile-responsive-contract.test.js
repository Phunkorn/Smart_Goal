import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const css = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/*
 * ทั้งสามหน้า auth ใช้โครงหน้าเดียวกันจาก base.css แล้ว จุดหักจึงย้ายมาที่ 1100px
 * ตามดีไซน์ชุดใหม่ คุณสมบัติที่ต้องมีเหมือนเดิมคือแถบแบรนด์ต้องซ้อนลงมาเป็นแถว
 * ไม่ใช่บีบให้แคบจนอ่านไม่ออก และการ์ดต้องเลื่อนแนวตั้งได้เมื่อเนื้อหาสูงเกินจอ
 */
test('the shared auth layout stacks instead of squeezing on tablet', async () => {
    const source = await css('resources/css/components/auth/base.css');
    const tablet = source.slice(source.indexOf('@media (max-width: 1100px)'));

    assert.match(tablet, /grid-template-columns:\s*1fr/);
    assert.match(tablet, /grid-template-rows:\s*max-content max-content/);
    assert.match(tablet, /align-content:\s*start/);
    assert.match(tablet, /overflow-y:\s*auto/);

    // การ์ดต้องกินความกว้างเต็มแทนที่จะค้างอยู่ที่ 410px จนล้นจอแคบ
    const form = await css('resources/css/components/auth/form-base.css');
    assert.match(form, /@media \(max-width: 1100px\)[\s\S]*?\.auth-stage[^{]*\{[^}]*width:\s*100%/);
});

/* ทั้งสามหน้าต้องได้ฉากหลัง ฟอนต์ และสคริปต์ชุดเดียวกัน ห้ามหน้าใดหลุดออกไปเอง */
test('every auth page shares one backdrop, font set, and experience script', async () => {
    const pages = ['login', 'setup-password', 'welcome'];
    const entries = {login: 'auth-login', 'setup-password': 'auth-setup-password', welcome: 'auth-welcome'};

    for (const page of pages) {
        const blade = await css(`resources/views/auth/${page}.blade.php`);
        const entry = await css(`resources/css/pages/${entries[page]}.css`);

        assert.match(blade, /@include\('auth\.partials\.backdrop'\)/, page);
        assert.match(blade, /@include\('auth\.partials\.brand'/, page);
        assert.match(blade, /family=Anuphan[^"]*IBM\+Plex\+Sans\+Thai/, page);
        assert.match(blade, /resources\/js\/pages\/auth\/experience\.js/, page);
        assert.match(entry, /components\/auth\/base\.css/, page);
        assert.match(entry, /components\/auth\/form-base\.css/, page);
    }
});

/*
 * Regression: ripple ที่ experience.js สร้างเป็น <span> ลูกโดยตรงของ .btn เหมือนกับ
 * span ที่ครอบข้อความปุ่ม กฎ `.btn > span` (1 class + 1 type) จึงเคยชนะ `.btn__ripple`
 * (1 class) แล้วทับ position:absolute ทิ้ง ripple กลายเป็น element ในสายงานปกติ
 * ขนาดเท่าความกว้างปุ่ม ดันปุ่มให้สูงขึ้นราว 340px ทันทีที่กด — ทุกปุ่มทั้งสามหน้า
 */
test('the button ripple cannot fall back into normal flow and stretch the button', async () => {
    const source = await css('resources/css/components/auth/form-base.css');
    const script = await css('resources/js/pages/auth/experience.js');

    // ripple ยังเป็น span ลูกโดยตรงของปุ่มอยู่ กฎ CSS จึงต้องกันการชนกันเอง
    assert.match(script, /createElement\('span'\)/);
    assert.match(script, /className = 'btn__ripple'/);

    // กฎของ label ต้องกัน ripple ออก และกฎของ ripple ต้องจำเพาะกว่า
    assert.match(source, /\.btn > span:not\(\.btn__ripple\)\s*\{/);
    assert.match(source, /\.btn > \.btn__ripple\s*\{[^}]*position:\s*absolute/s);

    // ปุ่มต้องยังคลิป ripple ไว้ในตัวเอง
    assert.match(source, /^\.btn \{[^}]*overflow:\s*hidden/ms);
});

/* โลโก้ต้องเป็นไฟล์ภาพจริงของ PremiumCare ไม่ใช่ตัวอักษรที่จัดให้ดูคล้ายโลโก้ */
test('the auth brand mark uses the real PremiumCare logo file', async () => {
    const brand = await css('resources/views/auth/partials/brand.blade.php');
    const source = await css('resources/css/components/auth/base.css');

    assert.match(brand, /images\/premiuum-care-logo\.png/);
    assert.match(brand, /class="brand-mark__logo"/);
    assert.doesNotMatch(brand, /brand-mark__word/);
    assert.match(source, /\.brand-mark__logo\s*\{[^}]*width:\s*122px/s);
});

/* เอฟเฟกต์แสงวิ่งและ orb คือแกนของดีไซน์ชุดนี้ และต้องปิดได้เมื่อผู้ใช้ขอลดการเคลื่อนไหว */
test('the animated backdrop ships with a reduced-motion escape hatch', async () => {
    const source = await css('resources/css/components/auth/base.css');
    const reduced = source.slice(source.indexOf('@media (prefers-reduced-motion: reduce)'));

    assert.match(source, /\.auth-bg__sheen\s*\{[^}]*animation:\s*auth-sheen/s);
    assert.match(source, /@keyframes auth-sheen/);
    assert.match(source, /\.auth-bg__orb--a\s*\{[^}]*animation:\s*auth-drift-a/s);
    assert.match(reduced, /\.auth-bg__sheen,?[\s\S]{0,200}animation:\s*none/);
});

test('mobile role chip keeps a compact visible label with an accessible name', async () => {
    const source = await css('resources/css/components/layout/responsive.css');
    const blade = await css('resources/views/layouts/app.blade.php');
    const mobile = source.slice(source.indexOf('@media (max-width: 991px)'));

    assert.match(mobile, /\.role-chip\s*\{[^}]*width:\s*auto[^}]*min-width:\s*0[^}]*max-width:\s*none[^}]*flex:\s*0 0 auto[^}]*justify-content:\s*center[^}]*gap:\s*\.35rem[^}]*padding:\s*0 \.65rem/s);
    assert.match(mobile, /\.role-chip i\s*\{[^}]*margin:\s*0/s);
    assert.match(mobile, /\.role-chip__label\s*\{[^}]*display:\s*inline[^}]*font-size:\s*\.72rem/s);
    assert.match(blade, /class="role-chip \{\{ \$isAdmin[^"]+" aria-label="\{\{ \$roleLabel \}\}" title="\{\{ \$roleLabel \}\}"/);
    assert.match(blade, /<span class="role-chip__label">\{\{ \$roleLabel \}\}<\/span>/);
});

test('mobile kanban stacks status columns without the desktop minimum width', async () => {
    const source = await css('resources/css/components/task-workspace/kanban.css');

    assert.match(source, /@media \(max-width: 760px\) \{\s*\.my-tasks-page \.mytasks-kanban__columns\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\)[^}]*min-width:\s*0/s);
});

/*
 * บนมือถือคอลัมน์อื่นถูกซ่อนด้วย is-mobile-selected และ HTML5 drag event ไม่ยิงบน touch
 * บอร์ดจึงต้องมีเส้นทางเปลี่ยนสถานะที่ไม่ใช่การลาก มิฉะนั้นผู้ใช้มือถือจะติดตายทั้งหน้า
 */
test('mobile board can change status without dragging', async () => {
    const script = await css('resources/js/pages/mytasks/table-kanban.js');
    const source = await css('resources/css/components/task-workspace/kanban.css');

    // ปุ่ม "ขั้นถัดไป" ต้องเป็น <button> จริงและวิ่งผ่านเส้นทางเดียวกับการลาก
    assert.match(script, /document\.createElement\(actionable \? 'button' : 'span'\)/);
    assert.match(script, /closest\('button\[data-kanban-next\]'\)/);
    assert.match(script, /applyTransition\(card, Number\(trigger\.dataset\.kanbanNextStatus\)\)/);
    assert.match(source, /button\.mytasks-kanban__next\s*\{[^}]*cursor:\s*pointer/s);
});

test('mobile board controls and project header remain scoped to the board page', async () => {
    const source = await css('resources/css/pages/mytasks/project-board.css');
    const mobile = source.slice(source.lastIndexOf('@media (max-width: 760px)'));

    assert.match(mobile, /\[data-board-toolbar\]/);
    assert.match(mobile, /grid-template-columns:\s*repeat\(3, minmax\(0, 1fr\)\)/);
    assert.match(mobile, /\.board-project-group__header\s*\{/);
    assert.match(mobile, /grid-template-columns:\s*auto auto minmax\(0, 1fr\) auto/);
    assert.match(mobile, /\[data-board-toolbar\] select\s*\{[^}]*width:\s*100%[^}]*max-width:\s*100%/s);
    assert.match(mobile, /\.board-project-rule\s*\{\s*display:\s*none/);
});

test('shared board titles and assignee header use explicit compact contracts', async () => {
    const source = await css('resources/css/pages/mytasks/project-board.css');
    const blade = await css('resources/views/tasks/partials/project-board-card.blade.php');
    const script = await css('resources/js/mytasks-project-board.js');
    const adminMember = await css('resources/views/work-board/admin/member.blade.php');

    assert.match(blade, /<strong class=\x22board-project-group__title\x22>/);
    assert.match(blade, /<span class=\x22board-reference-task__title\x22>/);
    assert.doesNotMatch(blade, /board-reference-task__open[^>]*>\s*<strong/);
    assert.match(source, /\.board-project-group__title\s*\{[^}]*font-weight:\s*600/s);
    assert.match(source, /\.board-reference-task__title\s*\{[^}]*font-weight:\s*500/s);
    assert.match(source, /span:nth-child\(6\)\s*\{[^}]*white-space:\s*nowrap/s);
    assert.match(script, /querySelector\('\.board-reference-task__title'\)/);
    assert.doesNotMatch(script, /board-reference-task__open strong/);
    assert.match(adminMember, /family=IBM\+Plex\+Sans\+Thai:wght@400;500;600;700/);
});

test('the month grid summarises each day by priority and never scrolls sideways on mobile', async () => {
    const source = await css('resources/css/components/task-workspace/calendar/base.css');
    const mobile = source.slice(source.lastIndexOf('@media (max-width: 760px)'));

    assert.doesNotMatch(source, /\.mytasks-calendar__legend\s*\{[^}]*border:\s*1px/s);
    assert.match(source, /\.mytasks-calendar__legend-item\s*\{[^}]*padding:\s*5px 9px[^}]*border:\s*1px solid var\(--calendar-legend-border[^}]*border-radius:\s*999px[^}]*background:\s*var\(--calendar-legend-background/s);
    for (const tone of ['urgent', 'quick', 'important', 'flexible', 'routine']) {
        assert.match(source, new RegExp(`\\.mytasks-calendar__legend-item\\.priority-${tone}\\s*\\{[^}]*--calendar-legend-border:[^}]*--calendar-legend-background:`, 's'));
    }
    assert.match(source, /\.mytasks-calendar__legend-item--meeting\s*\{[^}]*--calendar-legend-border:[^}]*--calendar-legend-background:/s);
    assert.match(mobile, /\.mytasks-calendar__legend\s*\{[^}]*width:\s*100%/s);
    assert.match(source, /\.mytasks-calendar__canvas\s*\{[^}]*width:\s*100%[^}]*min-width:\s*0/s);
    assert.match(source, /\.mytasks-calendar__counts\s*\{[^}]*flex-direction:\s*column/s);
    assert.match(source, /\.calendar-dot\s*\{[^}]*background:\s*var\(--calendar-tone/s);

    // แถบลากข้ามวันถูกถอดออกทั้งชุด ต้องไม่เหลือสไตล์ค้างไว้ให้เผลอกลับมาใช้
    assert.doesNotMatch(source, /mytasks-calendar__event-layer/);
    assert.doesNotMatch(source, /mytasks-calendar__task--segment/);
    assert.doesNotMatch(source, /mytasks-calendar__popover/);

    assert.match(mobile, /\.mytasks-calendar__viewport\s*\{[^}]*overflow-x:\s*hidden/s);
    assert.match(mobile, /\.mytasks-calendar__day\s*\{[^}]*padding:\s*5px/s);
});

test('mobile calendar navigation has deterministic control and selector rows', async () => {
    const source = await css('resources/css/components/task-workspace/calendar/navigation.css');
    const mobile = source.slice(source.lastIndexOf('@media (max-width: 760px)'));

    assert.match(mobile, /grid-template-columns:\s*repeat\(4, minmax\(0, 1fr\)\)/);
    assert.match(mobile, /label:nth-of-type\(1\)[^{]*\{[^}]*grid-column:\s*1 \/ 3/s);
    assert.match(mobile, /label:nth-of-type\(2\)[^{]*\{[^}]*grid-column:\s*3 \/ 5/s);
    assert.match(mobile, /\[data-calendar-month\][^}]*\[data-calendar-year\]\s*\{[^}]*min-width:\s*0/s);
});

test('summary cards stack full width and use the same six-column table', async () => {
    const source = await css('resources/css/components/task-workspace/calendar/agenda.css');
    const entry = await css('resources/css/pages/mytasks.css');
    const blade = await css('resources/views/tasks/partials/calendar.blade.php');
    const script = await css('resources/js/pages/mytasks/calendar.js');
    const desktop = source.slice(0, source.indexOf('@media'));
    const mobile = source.slice(source.lastIndexOf('@media (max-width: 760px)'));

    assert.match(entry, /calendar\/agenda\.css/);
    assert.match(source, /^\.my-tasks-page \.mytasks-calendar-agenda/m);

    assert.match(desktop, /\.mytasks-calendar-agenda\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\)[^}]*align-items:\s*start/s);
    assert.match(desktop, /\.mytasks-calendar-agenda__section\s*\{[^}]*display:\s*flex[^}]*flex-direction:\s*column[^}]*width:\s*100%/s);
    assert.match(desktop, /\.mytasks-calendar-agenda__footer\s*\{[^}]*margin-top:\s*auto/s);
    assert.doesNotMatch(desktop, /\.mytasks-calendar-agenda__section--meeting/);
    assert.doesNotMatch(desktop, /\.mytasks-calendar-agenda \.calendar-table__head\s*\{[^}]*display:\s*none/s);
    assert.match(source, /\.calendar-table--today,\s*\.my-tasks-page \.calendar-table--due\s*\{[^}]*--calendar-columns:/s);
    assert.match(script, /today: \['title', 'project', 'owner', 'collaborators', 'priority', 'time'\]/);
    assert.match(script, /due: \['title', 'project', 'owner', 'collaborators', 'priority', 'time'\]/);
    assert.match(script, /today: \['title', 'project', 'organizer', 'attendees', 'blank', 'time'\]/);
    assert.match(script, /due: \['title', 'project', 'organizer', 'attendees', 'blank', 'time'\]/);
    const agendaStart = blade.indexOf('<div class="mytasks-calendar-agenda"');
    const agendaEnd = blade.indexOf('@if($calendarShowsMeetings)', agendaStart);
    const agendaMarkup = blade.slice(agendaStart, agendaEnd);
    assert.equal((agendaMarkup.match(/<span role="columnheader">/g) || []).length, 12);
    assert.equal((agendaMarkup.match(/<span role="columnheader">เวลา<\/span>/g) || []).length, 2);

    // mobile ยังเปลี่ยนแต่ละแถวเป็นบล็อกอ่านง่ายแทนการเลื่อนแนวนอน
    assert.match(mobile, /\.calendar-table__head\s*\{[^}]*display:\s*none/s);
    assert.match(mobile, /\.calendar-table__row\s*\{[^}]*display:\s*flex[^}]*flex-wrap:\s*wrap/s);
    assert.match(mobile, /\.calendar-table__cell\.is-title\s*\{[^}]*flex:\s*1 0 100%/s);
    assert.match(source, /\.calendar-table__row:focus-visible\s*\{/);
});

test('every calendar table declares its own column track and matches the blade headers', async () => {
    const source = await css('resources/css/components/task-workspace/calendar/agenda.css');
    const blade = await css('resources/views/tasks/partials/calendar.blade.php');
    const script = await css('resources/js/pages/mytasks/calendar.js');

    // today กับ due ใช้ track ชุดเดียวกันจึงประกาศเป็น selector list ได้
    // regex ต้องยอมรับทั้งแบบเดี่ยวและแบบรวม ไม่งั้นการรวม selector ที่ถูกต้องจะทำให้ test แดง
    const declaration = (variant) => new RegExp(
        `\\.calendar-table--${variant}\\s*(?:,[^{]*)?\\{\\s*--calendar-columns:([^;]*);`,
    );

    for (const variant of ['today', 'due', 'day-task', 'day-meeting']) {
        assert.match(source, declaration(variant), variant);
        assert.match(blade, new RegExp(`calendar-table--${variant}`), variant);
    }

    // จำนวนคอลัมน์ใน CSS ต้องเท่ากับจำนวนช่องที่ calendar.js วางจริงในแต่ละแบบ
    // TASK_LAYOUTS กับ MEETING_LAYOUTS ต่างก็มีคีย์ชื่อ `day` จึงต้องอ่านทีละก้อน
    const block = (name) => {
        const from = script.indexOf(`const ${name} = {`);
        return script.slice(from, script.indexOf('};', from));
    };
    // \b กันไม่ให้คีย์ `day` ไปแมตช์ท้ายคำว่า `today`
    const layoutLength = (name, key) => block(name).match(new RegExp(`\\b${key}: \\[([^\\]]*)\\]`))[1].split(',').length;
    const trackLength = (variant) => source.match(declaration(variant))[1]
        .replace(/minmax\([^)]*\)/g, 'x').trim().split(/\s+/).length;

    assert.equal(trackLength('today'), layoutLength('TASK_LAYOUTS', 'today'));
    assert.equal(trackLength('due'), layoutLength('TASK_LAYOUTS', 'due'));
    assert.equal(trackLength('today'), layoutLength('MEETING_LAYOUTS', 'today'));
    assert.equal(trackLength('due'), layoutLength('MEETING_LAYOUTS', 'due'));
    assert.equal(trackLength('day-task'), layoutLength('TASK_LAYOUTS', 'day'));
    assert.equal(trackLength('day-meeting'), layoutLength('MEETING_LAYOUTS', 'day'));
    assert.match(script, /item\.dataset\.label = CELL_LABELS\[column\]/);
    assert.match(source, /\.calendar-table__cell::before\s*\{[^}]*content:\s*attr\(data-label\)/s);
});

test('calendar view removes the outer database card and keeps only the content cards', async () => {
    const base = await css('resources/css/components/task-workspace/calendar/base.css');
    const agenda = await css('resources/css/components/task-workspace/calendar/agenda.css');
    const blade = await css('resources/views/tasks/partials/calendar.blade.php');

    // ตัวห่อชั้นนอกโปร่งและไม่มีกรอบ/เงา เหลือ panel กับ agenda card เพียงชั้นเดียว
    assert.match(base, /\.mytasks-calendar\s*\{[^}]*background:\s*transparent/s);
    assert.match(base, /\.notion-database\[data-view="calendar"\]\s*\{[^}]*border:\s*0[^}]*background:\s*transparent[^}]*box-shadow:\s*none/s);
    assert.match(base, /\.notion-database\[data-view="calendar"\] \.notion-table-scroll\s*\{[^}]*border:\s*0[^}]*border-radius:\s*0[^}]*background:\s*transparent[^}]*box-shadow:\s*none/s);
    assert.match(base, /\.mytasks-calendar__panel\s*\{[^}]*border:\s*1px solid[^}]*background:\s*#fff[^}]*box-shadow:/s);
    assert.match(blade, /class="mytasks-calendar__panel"/);

    // การ์ดสรุปต้องโปร่ง ไม่งั้นจะกลับไปกลืนเป็นแผ่นขาวแผ่นเดียวกับปฏิทิน
    assert.match(agenda, /\.mytasks-calendar-agenda\s*\{[^}]*background:\s*transparent/s);
    assert.match(agenda, /\.mytasks-calendar-agenda__section\s*\{[^}]*border:\s*1px solid #dde4ee[^}]*box-shadow:/s);
});

test('every priority level tints the whole day cell, meetings included', async () => {
    const base = await css('resources/css/components/task-workspace/calendar/base.css');

    for (const tone of ['urgent', 'quick', 'important', 'flexible', 'routine', 'meeting']) {
        assert.match(
            base,
            new RegExp(`\\.mytasks-calendar__day\\.is-tone-${tone}\\s*\\{[^}]*--calendar-tone:[^}]*background:`, 's'),
            tone,
        );
    }

    // แถบขอบซ้ายอ่านโทนจากตัวแปรเดียวกัน แต่วันนี้ไม่มีกรอบม่วงรอบช่อง
    assert.match(base, /\.mytasks-calendar__day\.is-busy\s*\{[^}]*box-shadow:\s*inset 3px 0 0 var\(--calendar-tone/s);
    assert.match(base, /\.mytasks-calendar__day\.is-today\.is-busy\s*\{[^}]*box-shadow:\s*inset 3px 0 0 var\(--calendar-tone/s);
    assert.doesNotMatch(base, /\.mytasks-calendar__day\.is-today[^}]*inset 0 0 0 2px #6d3fd4/s);
});

test('the calendar day modal owns its own layer and reuses the shared table', async () => {
    const source = await css('resources/css/components/task-workspace/calendar/day-modal.css');
    const entry = await css('resources/css/pages/mytasks.css');
    const base = await css('resources/css/components/task-workspace/calendar/base.css');
    const blade = await css('resources/views/tasks/partials/calendar.blade.php');

    assert.match(entry, /calendar\/day-modal\.css/);
    assert.match(source, /\.mytasks-calendar-day__card\s*\{[^}]*max-height:\s*calc\(100vh - 28px\)/s);
    assert.match(source, /\.mytasks-calendar-day__body\s*\{[^}]*overflow-y:\s*auto/s);

    // แถวในกล่องนี้ใช้ .calendar-table ห้ามคัดลอกสไตล์ของแถวมาไว้ที่นี่
    assert.doesNotMatch(source, /\.calendar-table__row\s*\{/);

    // ทั้งช่องวันที่เป็นปุ่มเปิดกล่อง และ popover ของปุ่ม "+N" เดิมต้องถูกลบออกไปแล้วจริง ๆ
    assert.match(base, /\.mytasks-calendar__day-open\s*\{[^}]*position:\s*absolute[^}]*inset:\s*0/s);
    assert.doesNotMatch(blade, /data-calendar-popover/);
    assert.match(blade, /data-calendar-day-modal/);

    // งานกับการประชุมแยก section กันเสมอ
    assert.match(blade, /data-calendar-day-tasks/);
    assert.match(blade, /data-calendar-day-meetings/);
});

test('admin member profile owns a desktop/tablet/mobile composition instead of scrolling sideways', async () => {
    const source = await css('resources/css/pages/work-board/admin.css');
    const desktop = source.slice(0, source.indexOf('@media'));
    const tablet = source.slice(source.indexOf('@media (max-width: 1024px)'), source.indexOf('@media (max-width: 767px)'));
    const mobile = source.slice(source.indexOf('@media (max-width: 767px)'));

    // Desktop: identity ซ้าย actions ขวา และต้องยกเลิก overflow-x:auto ที่ .wb-profile-card ตั้งไว้
    assert.match(desktop, /\.admin-member-profile\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\) auto/s);
    assert.match(desktop, /\.admin-member-profile\s*\{[^}]*overflow:\s*visible/s);
    assert.doesNotMatch(source, /\.admin-member-profile[^}]*overflow-x:\s*auto/s);
    assert.match(desktop, /\.admin-member-profile__identity\s*\{[^}]*min-width:\s*0/s);
    assert.match(desktop, /\.admin-member-profile__metrics\s*\{[^}]*grid-template-columns:\s*repeat\(2,/s);
    assert.match(desktop, /\.admin-member-profile__metric\s*\{[^}]*grid-template-columns:\s*18px auto/s);
    assert.match(desktop, /\.admin-member-profile__metric strong\s*\{[^}]*font-size:\s*19px[^}]*font-weight:\s*600/s);
    assert.match(desktop, /\.admin-assign-button > span:first-child\s*\{[^}]*width:\s*30px[^}]*height:\s*30px/s);

    // Tablet: การ์ดเหลือคอลัมน์เดียว KPI กับปุ่มลงมาอยู่แถวถัดไป
    assert.match(tablet, /\.admin-member-profile\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\)/s);
    assert.match(tablet, /\.admin-member-profile__actions\s*\{[^}]*flex-wrap:\s*wrap/s);
    assert.match(tablet, /\.admin-member-profile__metrics\s*\{[^}]*grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\)/s);

    // Mobile: คอลัมน์เดียว KPI สองช่อง และปุ่มเต็มความกว้าง
    assert.match(mobile, /\.admin-member-profile__actions\s*\{[^}]*flex-direction:\s*column/s);
    assert.match(mobile, /\.admin-member-profile__metrics\s*\{[^}]*grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\)/s);
    assert.match(mobile, /\.admin-assign-button\s*\{[^}]*width:\s*100%/s);

    // Touch target และ focus ต้องมองเห็นได้
    assert.match(desktop, /\.admin-assign-button\s*\{[^}]*min-height:\s*44px/s);
    assert.match(desktop, /\.admin-assign-button:focus-visible\s*\{[^}]*outline:/s);
});

test('the shared assignment modal ships with the admin work board entry point', async () => {
    const entry = await css('resources/css/pages/work-board-admin.css');
    const modal = await css('resources/css/pages/board/modal.css');

    assert.match(entry, /@import '\.\/board\/tokens\.css';/);
    assert.match(entry, /@import '\.\/board\/modal\.css';/);
    // token ต้องมาก่อน component ที่ใช้ token และ delta ของ admin ต้องปิดท้าย cascade
    assert.ok(entry.indexOf("'./board/tokens.css'") < entry.indexOf("'./board/modal.css'"));
    assert.ok(entry.indexOf("'./board/modal.css'") < entry.indexOf("'./work-board/admin.css'"));

    // modal ต้องพึ่งพาเฉพาะ class ไม่ใช่ id ของงานแรก จึงใช้ได้กับทุกแถวงาน
    assert.match(modal, /\.board-collaborator-hint\s*\{/);
    assert.doesNotMatch(modal, /#boardCollaboratorHint/);
    assert.doesNotMatch(modal, /#boardCollaboratorList/);
    assert.match(modal, /\.avatar-mini\s*\{/);
});
