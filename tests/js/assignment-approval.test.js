import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL('../../resources/js/pages/admin/approvals.js', import.meta.url), 'utf8');
const blade = readFileSync(new URL('../../resources/views/admin/approvals/components/assignment-queue.blade.php', import.meta.url), 'utf8');
const collaboratorBlade = readFileSync(new URL('../../resources/views/admin/approvals/components/collaborator-queue.blade.php', import.meta.url), 'utf8');
const indexBlade = readFileSync(new URL('../../resources/views/admin/approvals/index.blade.php', import.meta.url), 'utf8');
const outgoingBlade = readFileSync(new URL('../../resources/views/admin/approvals/components/outgoing-queue.blade.php', import.meta.url), 'utf8');

test('assignment approval actions use SweetAlert2 confirmation', () => {
    assert.match(source, /window\.Swal\.fire/);
    assert.match(source, /result\.isConfirmed/);
    assert.match(blade, /data-assignment-approval-form/);
    assert.match(blade, /approval_status/);
    assert.match(collaboratorBlade, /data-approval-kind="collaborator"/);
    assert.match(collaboratorBlade, /name="status"/);
    assert.match(source, /form\.submit\(\)/);
});

test('approval queue tabs are server-side links, not a client-side scroll target', () => {
    // ?approval_queue= ถูกกรองที่ server ย้ายความรับผิดชอบจาก approvals.js มาที่ลิงก์แท็บ
    assert.match(indexBlade, /'approval_queue' => \$queue/);
    assert.match(indexBlade, /aria-current="page"/);
    assert.doesNotMatch(source, /getElementById\(\s*requestedQueue/);
});

test('assignment approval UI contains no native browser dialogs', () => {
    for (const pattern of [/window\.alert\s*\(/, /window\.confirm\s*\(/, /(^|[^.\w])alert\s*\(/m, /(^|[^.\w])confirm\s*\(/m]) {
        assert.doesNotMatch(source, pattern);
        assert.doesNotMatch(blade, pattern);
        assert.doesNotMatch(collaboratorBlade, pattern);
        assert.doesNotMatch(outgoingBlade, pattern);
    }
});
