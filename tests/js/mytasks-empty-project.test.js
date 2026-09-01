import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const readSource = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

test('deleting the last task keeps its project visible in the shared board', async () => {
    const source = await readSource('resources/js/mytasks-project-board.js');

    assert.match(source, /task\.remove\(\);[\s\S]*count\.textContent = remaining;/);
    assert.doesNotMatch(source, /if \(!remaining\) projectHeader\?\.remove\(\)/);
});

test('deleting the last task keeps its project section in the shared table source', async () => {
    const source = await readSource('resources/js/mytasks-management.js');

    assert.match(source, /row\.remove\(\);[\s\S]*countNode\.textContent/);
    assert.doesNotMatch(source, /if \(!count\) section\?\.remove\(\)/);
});

test('user and admin member workspaces load the same project lifecycle scripts', async () => {
    const [userView, adminView, entry] = await Promise.all([
        readSource('resources/views/tasks/index.blade.php'),
        readSource('resources/views/work-board/admin/member.blade.php'),
        readSource('resources/js/pages/mytasks/index.js'),
    ]);

    assert.match(userView, /resources\/js\/pages\/mytasks\/index\.js/);
    assert.match(adminView, /resources\/js\/pages\/mytasks\/index\.js/);
    assert.match(entry, /import '\.\.\/\.\.\/mytasks-management\.js';/);
    assert.match(entry, /import '\.\.\/\.\.\/mytasks-project-board\.js';/);
});
