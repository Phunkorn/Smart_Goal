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

test('mobile calendar fits seven days and uses compact focusable event controls', async () => {
    const source = await css('resources/css/components/task-workspace/calendar/base.css');
    const mobile = source.slice(source.lastIndexOf('@media (max-width: 760px)'));

    assert.match(mobile, /\.mytasks-calendar__canvas\s*\{[^}]*width:\s*100%[^}]*min-width:\s*0/s);
    assert.match(mobile, /\.mytasks-calendar__task\s*\{[^}]*width:\s*18px[^}]*height:\s*18px/s);
    assert.match(mobile, /\.mytasks-calendar__task > span\s*\{[^}]*clip-path:\s*inset\(50%\)/s);
});

test('mobile calendar navigation has deterministic control and selector rows', async () => {
    const source = await css('resources/css/components/task-workspace/calendar/navigation.css');
    const mobile = source.slice(source.lastIndexOf('@media (max-width: 760px)'));

    assert.match(mobile, /grid-template-columns:\s*repeat\(4, minmax\(0, 1fr\)\)/);
    assert.match(mobile, /label:nth-of-type\(1\)[^{]*\{[^}]*grid-column:\s*1 \/ 3/s);
    assert.match(mobile, /label:nth-of-type\(2\)[^{]*\{[^}]*grid-column:\s*3 \/ 5/s);
    assert.match(mobile, /\[data-calendar-month\][^}]*\[data-calendar-year\]\s*\{[^}]*min-width:\s*0/s);
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
