import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

test('editable and read-only status and priority controls share table column alignment', async () => {
    const source = await read('resources/css/components/task-workspace/table.css');
    const alignment = source.slice(source.indexOf('Editable rows render'));

    assert.ok(alignment.includes('.notion-row > .table-status-menu,'));
    assert.ok(alignment.includes('.notion-row > .table-priority-menu {'));
    assert.ok(alignment.includes('padding-inline: 0;'));

    assert.ok(alignment.includes('.notion-row > .board-status-pill,'));
    assert.ok(alignment.includes('.notion-row > .board-priority,'));
    assert.ok(alignment.includes('justify-self: center;'));
    assert.ok(alignment.includes('max-width: calc(100% - 24px);'));
});
