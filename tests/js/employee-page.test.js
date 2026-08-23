import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {
    employeeMatchesSearch,
    formatTemporaryPassword,
    normalizeEmployeeSearch,
} from '../../resources/js/pages/employees/index.js';

const source = readFileSync(new URL('../../resources/js/pages/employees/index.js', import.meta.url), 'utf8');
const employeeCss = readFileSync(new URL('../../resources/css/pages/employees/index.css', import.meta.url), 'utf8');
const modalSource = readFileSync(new URL('../../resources/views/employees/partials/form-modal.blade.php', import.meta.url), 'utf8');

test('employee search normalizes whitespace and case', () => {
    assert.equal(normalizeEmployeeSearch('  ALICE.Admin  '), 'alice.admin');
    assert.equal(employeeMatchesSearch('Alice Admin alice.admin Finance', '  FINANCE '), true);
    assert.equal(employeeMatchesSearch('Alice Admin alice.admin Finance', 'marketing'), false);
});

test('temporary password keeps policy-compatible word and five digits', () => {
    assert.equal(formatTemporaryPassword('SmartGoal', 42), 'SmartGoal!00042');
    assert.match(formatTemporaryPassword('SecureTeam', 987654), /^SecureTeam!\d{5}$/);
});

test('employee actions use SweetAlert2 without native browser dialogs', () => {
    assert.match(source, /window\.Swal\.fire/);
    assert.doesNotMatch(source, /window\.(?:alert|confirm|prompt)\s*\(/);
    assert.match(employeeCss, /\.employee-action--delete\s*\{[^}]*border-color:\s*var\(--red\);[^}]*background:\s*var\(--red-dim\)/s);
    assert.match(employeeCss, /\.employee-action--delete:hover\s*\{/);
    assert.match(employeeCss, /\.employee-action--delete:focus-visible\s*\{/);
});

test('employee form modal uses compact natural page scrolling', () => {
    assert.doesNotMatch(modalSource, /modal-dialog-scrollable/);
    assert.match(modalSource, /class="row g-2"/);
});

test('invalid form modal reopens only after resolving a real modal element', () => {
    assert.match(source, /document\.getElementById\(modalId\)/);
    assert.match(source, /if \(! modalElement \|\| ! window\.bootstrap\?\.Modal\) return;/);
    assert.match(source, /Modal\.getOrCreateInstance\(modalElement\)\.show\(\)/);
});
