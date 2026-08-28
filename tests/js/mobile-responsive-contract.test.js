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
