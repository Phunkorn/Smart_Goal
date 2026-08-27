import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL('../../resources/js/pages/board/assignment-approval.js', import.meta.url), 'utf8');
const blade = readFileSync(new URL('../../resources/views/board/components/assignment-approval-queue.blade.php', import.meta.url), 'utf8');

test('assignment approval actions use SweetAlert2 confirmation', () => {
    assert.match(source, /window\.Swal\.fire/);
    assert.match(source, /result\.isConfirmed/);
    assert.match(blade, /data-assignment-approval-form/);
    assert.match(blade, /approval_status/);
});

test('assignment approval UI contains no native browser dialogs', () => {
    for (const pattern of [/window\.alert\s*\(/, /window\.confirm\s*\(/, /(^|[^.\w])alert\s*\(/m, /(^|[^.\w])confirm\s*\(/m]) {
        assert.doesNotMatch(source, pattern);
        assert.doesNotMatch(blade, pattern);
    }
});
