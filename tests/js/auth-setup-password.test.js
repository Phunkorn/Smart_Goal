import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {mountDom} from './helpers/dom.js';

const blade = readFileSync(new URL('../../resources/views/auth/setup-password.blade.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../../resources/css/components/auth/setup-password.css', import.meta.url), 'utf8');
const loginBlade = readFileSync(new URL('../../resources/views/auth/login.blade.php', import.meta.url), 'utf8');

const MIN_LENGTH = 12;
const RULE_IDS = ['rule-length', 'rule-lowercase', 'rule-uppercase', 'rule-number', 'rule-symbol', 'rule-match'];

/** แปลง Blade เป็น HTML แบบหยาบ ๆ พอให้ jsdom ใช้ markup ชุดเดียวกับของจริงได้ */
const renderBlade = () => blade
    .replace(/@php[\s\S]*?@endphp/g, '')
    .replace(/@if[\s\S]*?@endif/g, '')
    .replace(/@csrf/g, '')
    .replace(/@vite\([^)]*\)/g, '')
    .replace(/\{\{ \$minLength \}\}/g, String(MIN_LENGTH))
    .replace(/\{\{[\s\S]*?\}\}/g, '#');

/** jsdom ของโปรเจกต์นี้ไม่เปิด runScripts จึงต้องดึงสคริปต์ inline มาผูกกับ document เอง */
const mountSetupPassword = () => {
    const context = mountDom(renderBlade());
    const scriptBody = blade.match(/<script>([\s\S]*?)<\/script>/)[1];
    const api = new Function('document', `${scriptBody}\nreturn {checkPassword, togglePassword};`)(context.document);

    return {...context, ...api};
};

const stateOf = (document) => ({
    passed: RULE_IDS.filter((id) => document.getElementById(id).classList.contains('ok')),
    submitDisabled: document.getElementById('submitBtn').disabled,
});

const stateFor = (password, confirmation = password) => {
    const {document, checkPassword, cleanup} = mountSetupPassword();

    document.getElementById('password').value = password;
    document.getElementById('password_confirmation').value = confirmation;
    checkPassword();

    const state = stateOf(document);
    cleanup();

    return state;
};

test('setup password page renders one visible checklist row per policy rule', () => {
    const {document, cleanup} = mountSetupPassword();

    RULE_IDS.forEach((id) => assert.ok(document.getElementById(id), `ต้องมีแถวกฎ ${id}`));
    assert.equal(document.getElementById('passwordRules').dataset.minLength, String(MIN_LENGTH));
    assert.match(document.getElementById('rule-length').textContent, new RegExp(`${MIN_LENGTH} ตัวอักษร`));
    assert.equal(document.getElementById('password').getAttribute('placeholder'), `อย่างน้อย ${MIN_LENGTH} ตัวอักษร`);

    cleanup();

    // ก่อนหน้านี้ .rules ถูกซ่อนด้วย display:none ผู้ใช้จึงเห็นแค่ปุ่มที่กดไม่ได้โดยไม่รู้สาเหตุ
    const rulesBlock = css.match(/\.rules\{([^}]*)\}/);
    assert.ok(rulesBlock, 'ต้องมีสไตล์ของ .rules');
    assert.doesNotMatch(rulesBlock[1], /display:\s*none/);
    assert.match(css, /\.rule\.ok\{color:var\(--success\)\}/);
});

test('both password fields recheck the policy on every keystroke', () => {
    assert.match(blade, /id="password"[^>]*oninput="checkPassword\(\)"/);
    assert.match(blade, /id="password_confirmation"[^>]*oninput="checkPassword\(\)"/);
});

test('submit stays disabled until every policy rule and the confirmation pass', () => {
    assert.deepEqual(stateFor('', ''), {passed: [], submitDisabled: true});

    assert.deepEqual(stateFor('Sh0rt!Aa'), {
        passed: ['rule-lowercase', 'rule-uppercase', 'rule-number', 'rule-symbol', 'rule-match'],
        submitDisabled: true,
    });

    assert.deepEqual(stateFor('UPPERCASE!123'), {
        passed: ['rule-length', 'rule-uppercase', 'rule-number', 'rule-symbol', 'rule-match'],
        submitDisabled: true,
    });

    assert.deepEqual(stateFor('lowercase!123'), {
        passed: ['rule-length', 'rule-lowercase', 'rule-number', 'rule-symbol', 'rule-match'],
        submitDisabled: true,
    });

    assert.deepEqual(stateFor('NoDigitsHere!'), {
        passed: ['rule-length', 'rule-lowercase', 'rule-uppercase', 'rule-symbol', 'rule-match'],
        submitDisabled: true,
    });

    assert.deepEqual(stateFor('NoSymbols12345'), {
        passed: ['rule-length', 'rule-lowercase', 'rule-uppercase', 'rule-number', 'rule-match'],
        submitDisabled: true,
    });

    assert.deepEqual(stateFor('PolicyPerfect!2026', 'DifferentPassword!456'), {
        passed: ['rule-length', 'rule-lowercase', 'rule-uppercase', 'rule-number', 'rule-symbol'],
        submitDisabled: true,
    });

    assert.deepEqual(stateFor('PolicyPerfect!2026'), {passed: RULE_IDS, submitDisabled: false});
});

test('the checklist marks a rule with the same check icon the ok state uses', () => {
    const {document, checkPassword, cleanup} = mountSetupPassword();

    assert.equal(document.querySelector('#rule-length i').className, 'bi bi-circle');

    document.getElementById('password').value = 'PolicyPerfect!2026';
    checkPassword();

    assert.equal(document.querySelector('#rule-length i').className, 'bi bi-check-circle-fill');
    assert.equal(document.querySelector('#rule-match i').className, 'bi bi-circle');

    cleanup();
});

test('auth pages no longer advertise the retired 6 or 8 character minimum', () => {
    [blade, loginBlade].forEach((source) => {
        assert.doesNotMatch(source, /(?:^|[^\d])[68] ตัวอักษร/);
    });
});
