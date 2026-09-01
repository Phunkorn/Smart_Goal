import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const css = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

test('login owns its tablet composition through 820px', async () => {
    const source = await css('resources/css/components/auth/login.css');

    assert.match(source, /@media\(max-width:820px\)/);
    assert.match(source, /grid-template-rows:max-content max-content/);
    assert.match(source, /align-content:start/);
    assert.match(source, /overflow-y:auto/);
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

test('mobile calendar fits seven days and keeps compact continuous event bars', async () => {
    const source = await css('resources/css/components/task-workspace/calendar/base.css');
    const mobile = source.slice(source.lastIndexOf('@media (max-width: 760px)'));

    assert.match(source, /\.mytasks-calendar__canvas\s*\{[^}]*width:\s*100%[^}]*min-width:\s*0/s);
    assert.match(mobile, /\.mytasks-calendar__event-layer\s*\{[^}]*grid-template-rows:\s*repeat\(3, 16px\)/s);
    assert.match(mobile, /\.mytasks-calendar__task--segment\s*\{[^}]*width:\s*auto[^}]*height:\s*16px/s);
    assert.match(mobile, /\.mytasks-calendar__task--segment > span\s*\{[^}]*clip-path:\s*none[^}]*text-overflow:\s*ellipsis/s);
});

test('mobile calendar navigation has deterministic control and selector rows', async () => {
    const source = await css('resources/css/components/task-workspace/calendar/navigation.css');
    const mobile = source.slice(source.lastIndexOf('@media (max-width: 760px)'));

    assert.match(mobile, /grid-template-columns:\s*repeat\(4, minmax\(0, 1fr\)\)/);
    assert.match(mobile, /label:nth-of-type\(1\)[^{]*\{[^}]*grid-column:\s*1 \/ 3/s);
    assert.match(mobile, /label:nth-of-type\(2\)[^{]*\{[^}]*grid-column:\s*3 \/ 5/s);
    assert.match(mobile, /\[data-calendar-month\][^}]*\[data-calendar-year\]\s*\{[^}]*min-width:\s*0/s);
});

test('calendar agenda stays scoped and collapses metadata below the task on mobile', async () => {
    const source = await css('resources/css/components/task-workspace/calendar/agenda.css');
    const entry = await css('resources/css/pages/mytasks.css');
    const mobile = source.slice(source.lastIndexOf('@media (max-width: 760px)'));

    assert.match(entry, /calendar\/agenda\.css/);
    assert.match(source, /^\.my-tasks-page \.mytasks-calendar-agenda/m);
    assert.match(mobile, /grid-template-columns:\s*42px minmax\(0, 1fr\) 14px/);
    assert.match(mobile, /\.mytasks-calendar-agenda__meta\s*\{[^}]*grid-column:\s*2 \/ 3[^}]*grid-row:\s*2/s);
    assert.match(source, /\.mytasks-calendar-agenda__item:focus-visible\s*\{/);
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
