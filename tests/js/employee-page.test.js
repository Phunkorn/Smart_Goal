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
const modalCss = readFileSync(new URL('../../resources/css/pages/employees/modal.css', import.meta.url), 'utf8');
const pageSource = readFileSync(new URL('../../resources/views/employees/index.blade.php', import.meta.url), 'utf8');
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
    assert.match(modalSource, />บัญชีผู้ใช้งาน<\/label>/);
    assert.match(modalCss, /employee-form-modal--employee\.employee-form-modal--create \.modal-dialog\s*\{[^}]*max-width:\s*680px/s);
    assert.match(modalCss, /employee-form-section--temporary-password,[^{]+employee-form-section--profile\s*\{[^}]*background:\s*var\(--surface-2\)/s);
});

test('create employee uses one simple temporary password field with create-only styling', () => {
    const createStart = modalSource.indexOf('@if(! $isEdit)');
    const profileStart = modalSource.indexOf('<section class="employee-form-section employee-form-section--profile" aria-labelledby="{{ $modalId }}ProfileTitle">');
    const createPasswordSection = modalSource.slice(createStart, profileStart);

    assert.notEqual(createStart, -1);
    assert.notEqual(profileStart, -1);
    assert.equal((createPasswordSection.match(/name="password"/g) ?? []).length, 1);
    assert.doesNotMatch(createPasswordSection, /password_confirmation|PasswordConfirmation/);
    assert.doesNotMatch(createPasswordSection, /minlength|PasswordPolicy/);
    assert.match(createPasswordSection, /ใช้สำหรับเข้าสู่ระบบครั้งแรก พนักงานจะต้องตั้งรหัสผ่านใหม่หลังเข้าสู่ระบบ/);
    assert.match(modalSource, /employee-form-modal--create/);
    assert.match(modalCss, /\.employee-form-modal--create \.employee-form-section/);
    assert.doesNotMatch(modalCss, /\.employee-form-modal--edit \.employee-form-section/);
});

test('employee cards use compact neutral roles and plain account names', () => {
    assert.match(pageSource, /data-account-context="{{ \$accountContext }}"/);
    assert.match(pageSource, />แผนก:<\/span>/);
    assert.match(pageSource, />บัญชีผู้ใช้งาน<\/dt><dd>{{ \$employee->username }}/);
    assert.doesNotMatch(pageSource, /{{ '@'\.\$employee->username }}/);
    assert.match(employeeCss, /employee-page\[data-account-context='employee'\]\s*\{[^}]*max-width:\s*1180px/s);
    assert.match(employeeCss, /\.employee-role--user\s*\{[^}]*background:\s*var\(--surface-2\)/s);
    assert.match(employeeCss, /employee-action--delete\s*\{[^}]*margin-left:\s*0/s);
});

test('invalid form modal reopens only after resolving a real modal element', () => {
    assert.match(source, /document\.getElementById\(modalId\)/);
    assert.match(source, /if \(! modalElement \|\| ! window\.bootstrap\?\.Modal\) return;/);
    assert.match(source, /Modal\.getOrCreateInstance\(modalElement\)\.show\(\)/);
});
