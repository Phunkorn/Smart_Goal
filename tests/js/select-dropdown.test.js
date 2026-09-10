import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom, click, pressKey} from './helpers/dom.js';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/**
 * markup ที่ตรงกับ resources/views/reports/components/personal-filters.blade.php
 * ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย test จึงจะยังสะท้อนของจริง
 */
function filterMarkup() {
    return `
        <form class="personal-report__filters">
            <div class="personal-report__filter" data-sg-select>
                <label for="personalReportStatus">สถานะงาน</label>
                <select id="personalReportStatus" name="status">
                    <option value="">ทุกสถานะ</option>
                    <option value="4">เสร็จสิ้น</option>
                    <option value="6" selected>ล่าช้า</option>
                </select>
            </div>
            <button type="submit">แสดงผล</button>
        </form>`;
}

async function mountFilters(t) {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = filterMarkup();

    const {initSelectDropdowns} = await import('../../resources/js/components/select-dropdown.js');
    initSelectDropdowns(env.document);

    const root = env.document.querySelector('.sg-select');

    return {
        env,
        root,
        select: env.document.querySelector('#personalReportStatus'),
        trigger: root.querySelector('.sg-select__trigger'),
        panel: root.querySelector('.sg-select__panel'),
    };
}

test('trigger shows the selected option and opens the panel on click', async (t) => {
    const {root, trigger, panel} = await mountFilters(t);

    assert.equal(trigger.querySelector('.sg-select__value').textContent, 'ล่าช้า');
    assert.equal(trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(root.classList.contains('is-open'), false);

    click(trigger);

    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(root.classList.contains('is-open'), true);
    assert.deepEqual(
        [...panel.querySelectorAll('.sg-select__option')].map((item) => item.textContent),
        ['ทุกสถานะ', 'เสร็จสิ้น', 'ล่าช้า']
    );
});

/*
 * เส้นทางจริงตั้งแต่กดเปิดจนค่าถูกเขียนกลับ ไม่ใช่แค่เรียกฟังก์ชันภายใน
 * <select> ต้องเป็นแหล่งความจริงเดียว ฟอร์มจึงยังส่งค่าที่ถูกต้อง
 */
test('choosing an option writes back to the native select and closes the panel', async (t) => {
    const {root, trigger, panel, select} = await mountFilters(t);
    let changes = 0;
    select.addEventListener('change', () => {
        changes += 1;
    });

    click(trigger);
    click([...panel.querySelectorAll('.sg-select__option')][1]);

    assert.equal(select.value, '4');
    assert.equal(changes, 1);
    assert.equal(trigger.querySelector('.sg-select__value').textContent, 'เสร็จสิ้น');
    assert.equal(root.classList.contains('is-open'), false);
});

test('keyboard opens, moves, selects and wraps around the ends', async (t) => {
    const {trigger, select} = await mountFilters(t);

    pressKey(trigger, 'ArrowDown');
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    // เปิดมาแล้วชี้ที่ค่าที่เลือกอยู่ (ล่าช้า = ตัวสุดท้าย) ลูกศรลงจึงวนกลับไปตัวแรก
    pressKey(trigger, 'ArrowDown');
    pressKey(trigger, 'Enter');

    assert.equal(select.value, '');
    assert.equal(trigger.getAttribute('aria-expanded'), 'false');
});

test('escape closes the panel and returns focus to its trigger', async (t) => {
    const {env, root, trigger} = await mountFilters(t);

    trigger.focus();
    click(trigger);
    pressKey(trigger, 'Escape');

    assert.equal(root.classList.contains('is-open'), false);
    assert.equal(env.document.activeElement, trigger);
});

test('pointerdown outside closes the panel without stealing focus back', async (t) => {
    const {env, root, trigger} = await mountFilters(t);

    click(trigger);
    env.document.querySelector('button[type="submit"]').dispatchEvent(
        new env.window.MouseEvent('pointerdown', {bubbles: true})
    );

    assert.equal(root.classList.contains('is-open'), false);
    assert.notEqual(env.document.activeElement, trigger);
});

test('initialising twice does not stack duplicate dropdowns or listeners', async (t) => {
    const {env, trigger, select} = await mountFilters(t);
    const {initSelectDropdowns} = await import('../../resources/js/components/select-dropdown.js');

    initSelectDropdowns(env.document);

    assert.equal(env.document.querySelectorAll('.sg-select').length, 1);
    assert.equal(env.document.querySelectorAll('.sg-select__trigger').length, 1);

    let changes = 0;
    select.addEventListener('change', () => {
        changes += 1;
    });
    click(trigger);
    click(env.document.querySelectorAll('.sg-select__option')[1]);

    assert.equal(changes, 1);
});

test('nextOptionIndex wraps in both directions and copes with an empty list', async () => {
    const {nextOptionIndex} = await import('../../resources/js/components/select-dropdown.js');

    assert.equal(nextOptionIndex({total: 3, current: -1, step: 1}), 0);
    assert.equal(nextOptionIndex({total: 3, current: -1, step: -1}), 2);
    assert.equal(nextOptionIndex({total: 3, current: 2, step: 1}), 0);
    assert.equal(nextOptionIndex({total: 3, current: 0, step: -1}), 2);
    assert.equal(nextOptionIndex({total: 0, current: -1, step: 1}), -1);
});

/*
 * แผงต้องปิดด้วย visibility ไม่ใช่ display:none เพราะ display ตัด transition ทิ้ง
 * แล้วดร็อปดาวน์จะกระโดดหายแทนที่จะสไลด์ ซึ่งเป็นสิ่งที่ผู้ใช้ขอให้แก้พอดี
 */
test('the panel animates instead of snapping and stays a popover, not a modal', async () => {
    const css = await read('resources/css/components/select-dropdown.css');

    assert.match(css, /\.sg-select__panel\s*\{[^}]*transition:[^}]*opacity/s);
    assert.match(css, /\.sg-select__panel\s*\{[^}]*visibility:\s*hidden/s);
    assert.doesNotMatch(css, /\.sg-select__panel\s*\{[^}]*display:\s*none/s);
    assert.match(css, /\.sg-select__panel\s*\{[^}]*position:\s*absolute/s);
    assert.match(css, /prefers-reduced-motion/);
    // popover ต้องไม่ล็อกการเลื่อนหน้าและไม่มีฉากหลังคลุมจอ
    assert.doesNotMatch(css, /\.sg-select[^{]*\{[^}]*position:\s*fixed/s);
    assert.doesNotMatch(css, /aria-modal/);
});

test('both report pages enhance their filters with the shared component', async () => {
    const [my, employee, personalFilters, employeeView] = await Promise.all([
        read('resources/js/pages/reports/my.js'),
        read('resources/js/pages/reports/employee.js'),
        read('resources/views/reports/components/personal-filters.blade.php'),
        read('resources/views/reports/employee.blade.php'),
    ]);

    for (const source of [my, employee]) {
        assert.match(source, /from '\.\.\/\.\.\/components\/select-dropdown\.js'/);
        assert.match(source, /initSelectDropdowns\(page\)/);
    }

    assert.match(personalFilters, /data-sg-select/);
    assert.match(employeeView, /class="employee-report__period" data-sg-select/);
});
