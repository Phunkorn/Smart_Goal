import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {
    employeeMatchesSearch,
    formatTemporaryPassword,
    normalizeEmployeeSearch,
    usernameValidationMessage,
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
    /*
     * ปุ่มลบต้องเงียบก่อน แล้วค่อยเป็นสีแดงตอนชี้หรือโฟกัส
     *
     * เดิมเป็นปุ่มแดงทึบตลอดเวลา จึงเป็นสิ่งที่เด่นที่สุดในการ์ดทั้งที่ควรกดน้อยที่สุด
     * สีแดงยังต้องมีอยู่ เพราะเป็นสัญญาณว่าทำแล้วย้อนไม่ได้ แค่ย้ายไปอยู่ที่ hover/focus
     */
    assert.match(employeeCss, /\.employee-action--delete\s*\{[^}]*color:\s*var\(--text-muted\)/s);
    assert.doesNotMatch(employeeCss, /\.employee-action--delete\s*\{[^}]*background:\s*var\(--red-dim\)/s);
    assert.match(employeeCss, /\.employee-action--delete:hover\s*\{[^}]*color:\s*var\(--red\)/s);
    assert.match(employeeCss, /\.employee-action--delete:focus-visible\s*\{[^}]*outline-color:\s*var\(--red\)/s);
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

/*
 * การจัดวางของการ์ดพนักงาน
 *
 * สามเรื่องที่เคยทำให้หน้านี้ดูกระจาย และต้องไม่ย้อนกลับไป
 */
test('employee card actions keep a fixed position and a real priority order', () => {
    // 1. แถวปุ่มเป็นกริดคอลัมน์ตายตัว ไม่ใช่ flex-wrap ที่ทำให้ปุ่มลบไปอยู่คนละที่ในแต่ละการ์ด
    assert.match(employeeCss, /\.employee-card__actions\s*\{[^}]*display:\s*grid[^}]*grid-template-columns:\s*minmax\(0, 1fr\) minmax\(0, 1fr\) auto/s);
    assert.doesNotMatch(employeeCss, /\.employee-card__actions\s*\{[^}]*flex-wrap/s);
    assert.doesNotMatch(employeeCss, /\.employee-action--delete\s*\{[^}]*margin-left:\s*auto/s);

    // 2. แก้ไขคือปุ่มหลักใบเดียวที่มีพื้นสี ส่วนรีเซ็ตรหัสผ่านเป็นปุ่มรองแบบเส้นขอบ
    assert.match(employeeCss, /\.employee-action--edit\s*\{[^}]*background:\s*var\(--accent-dim\)/s);
    assert.match(employeeCss, /\.employee-action--reset\s*\{[^}]*color:\s*var\(--text-muted\)/s);

    // 3. ปุ่มลบเป็นไอคอนล้วน จึงต้องมีชื่อให้ screen reader อ่านได้
    assert.match(pageSource, /employee-action--delete"[\s\S]*?aria-label="ลบบัญชีของ/);
});

test('colour in an employee card carries status only', () => {
    // สถานะปกติไม่ใช่ป้ายทึบ เหลือแต่ "ปิดใช้งาน" ที่ยังต้องสะดุดตา
    assert.match(employeeCss, /\.employee-status\.is-active\s*\{[^}]*background:\s*transparent/s);
    assert.match(employeeCss, /\.employee-status\.is-inactive\s*\{[^}]*background:\s*var\(--red-dim\)/s);

    // admin กับหัวหน้าแผนกเคยใช้ accent ชุดเดียวกันจนแยกจากกันไม่ออก
    const admin = employeeCss.match(/\.employee-role--admin\s*\{([^}]*)\}/s)[1];
    const head = employeeCss.match(/\.employee-role--department-head\s*\{([^}]*)\}/s)[1];
    assert.notEqual(admin.trim(), head.trim(), 'สองบทบาทนี้ต้องไม่ใช้สีชุดเดียวกัน');

    // ป้ายกำกับในรายละเอียดบัญชีไม่มีไอคอนแล้ว
    assert.match(pageSource, /<dt>บัญชีผู้ใช้งาน<\/dt>/);
    assert.doesNotMatch(pageSource, /<dt><i class="bi bi-person-badge"/);
});

test('the visible-account count sits in the toolbar instead of its own band', () => {
    assert.match(pageSource, /class="employee-count"/);
    assert.doesNotMatch(pageSource, /employee-results-meta/);
    // มองเฉพาะ selector จริง คอมเมนต์ที่อธิบายว่าของเดิมเป็นอย่างไรยังอยู่ได้
    assert.doesNotMatch(employeeCss, /\.employee-results-meta\s*\{/);

    // CSS ที่ไม่มี markup รองรับต้องถูกลบทิ้ง ไม่ใช่ปล่อยค้างไว้
    assert.doesNotMatch(employeeCss, /\.employee-summary/);
    assert.doesNotMatch(source, /employee-summary-count/);
});

test('invalid form modal reopens only after resolving a real modal element', () => {
    assert.match(source, /document\.getElementById\(modalId\)/);
    assert.match(source, /if \(! modalElement \|\| ! window\.bootstrap\?\.Modal\) return;/);
    assert.match(source, /Modal\.getOrCreateInstance\(modalElement\)\.show\(\)/);
});

test('username constraint messages are Thai and never demand a dot', () => {
    // ข้อความ constraint ของเบราว์เซอร์เป็นอังกฤษเสมอ จึงต้องแทนด้วย setCustomValidity
    assert.equal(usernameValidationMessage(''), 'กรุณากรอกบัญชีผู้ใช้งาน');
    assert.equal(usernameValidationMessage('ab'), 'บัญชีผู้ใช้งานต้องยาวอย่างน้อย 3 ตัวอักษร');
    assert.match(usernameValidationMessage('ผู้ใช้'), /ตัวอักษรอังกฤษและตัวเลข/);
    assert.equal(usernameValidationMessage('a'.repeat(51)), 'บัญชีผู้ใช้งานต้องยาวไม่เกิน 50 ตัวอักษร');

    // ตัวอักษรล้วนต้องผ่าน จุดเป็นตัวเลือก ไม่ใช่ข้อบังคับ
    assert.equal(usernameValidationMessage('beam'), '');
    assert.equal(usernameValidationMessage('beam.dev'), '');
    assert.equal(usernameValidationMessage('beam_dev-01'), '');

    assert.match(source, /setCustomValidity\(usernameValidationMessage/);
    assert.match(modalSource, /data-username-input/);
    // คำใบ้ต้องไม่ทำให้เข้าใจว่าจุดเป็นสิ่งจำเป็น
    assert.match(modalSource, /จะใส่ \. - _ เพิ่มด้วยก็ได้ \(ไม่บังคับ\)/);
});
