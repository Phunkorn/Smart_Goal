import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom, click, pressKey} from './helpers/dom.js';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/**
 * markup ตัวอย่างของช่องกรองที่ใช้ data-sg-select (โครงเดียวกับตัวกรองของรายงาน)
 * ใช้ทดสอบพฤติกรรมของคอมโพเนนต์กลาง ไม่ได้อ้างอิงหน้าใดหน้าหนึ่ง
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

test('an option can render an icon description and visual divider', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = `
        <div data-sg-select>
            <select>
                <option value="review" data-icon="bi-eye" data-description="Only work waiting for you" data-divider="true">Needs review</option>
            </select>
        </div>`;

    const {initSelectDropdowns} = await import('../../resources/js/components/select-dropdown.js');
    initSelectDropdowns(env.document);
    click(env.document.querySelector('.sg-select__trigger'));

    const option = env.document.querySelector('.sg-select__option');
    assert.equal(option.classList.contains('sg-select__option--divider'), true);
    assert.equal(option.querySelector('.sg-select__option-icon').classList.contains('bi-eye'), true);
    assert.equal(option.querySelector('strong').textContent, 'Needs review');
    assert.equal(option.querySelector('small').textContent, 'Only work waiting for you');
});

test('the project report enhances its filters with the shared component', async () => {
    const [projects, ...projectsPartials] = await Promise.all([
        read('resources/js/pages/reports/projects.js'),
        read('resources/views/reports/components/projects/header.blade.php'),
        read('resources/views/reports/components/projects/filters.blade.php'),
        read('resources/views/reports/components/projects/sort.blade.php'),
    ]);
    const projectsView = projectsPartials.join('\n');

    assert.match(projects, /from '\.\.\/\.\.\/components\/select-dropdown\.js'/);
    assert.match(projects, /initSelectDropdowns\(page\)/);

    // ช่องพนักงาน เดือน ตัวกรอง และการเรียงของรายงานโปรเจกต์ใช้คอมโพเนนต์เดียวกันทุกช่อง
    for (const id of ['projectReportOwner', 'projectReportMonth', 'projectReportProject', 'projectReportStatus', 'projectReportScope', 'projectReportRole', 'projectReportSort']) {
        assert.match(projectsView, new RegExp(`data-sg-select[^>]*>\\s*(?:<i[^>]*></i>\\s*)?<label for="${id}"`));
    }
});

/*
 * ฟอร์มในกล่องเพิ่มงานถูก form.reset() ทุกครั้งที่เปิด ซึ่งไม่ยิง change
 * ป้ายบนปุ่มต้องกลับไปตรงกับค่าของ <select> ไม่ใช่ค้างค่าที่เลือกไว้รอบก่อน
 */
test('the trigger label follows a form reset, which fires no change event', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = `
        <form><div data-sg-select>
            <select name="category"><option value="">เลือกหมวดงาน</option><option value="1">ตรวจเช็ก</option></select>
        </div></form>`;
    const {initSelectDropdowns} = await import('../../resources/js/components/select-dropdown.js');
    initSelectDropdowns(env.document);

    const label = env.document.querySelector('.sg-select__value');
    click(env.document.querySelector('.sg-select__trigger'));
    click(env.document.querySelectorAll('.sg-select__option')[1]);
    assert.equal(label.textContent, 'ตรวจเช็ก');

    env.document.querySelector('form').reset();
    await Promise.resolve();

    assert.equal(label.textContent, 'เลือกหมวดงาน');
});

test('the panel opens upward only when space below is short and above is larger', async () => {
    const {shouldOpenUpward} = await import('../../resources/js/components/select-dropdown.js');

    // ช่องท้ายกล่อง: ใต้ปุ่มเหลือ 40px เหนือปุ่มมี 300px
    assert.equal(shouldOpenUpward({triggerTop: 340, triggerBottom: 378, boundaryTop: 40, boundaryBottom: 418, panelHeight: 200}), true);
    // ใต้ปุ่มพอ
    assert.equal(shouldOpenUpward({triggerTop: 60, triggerBottom: 98, boundaryTop: 40, boundaryBottom: 418, panelHeight: 200}), false);
    // ไม่มีข้อมูล layout (jsdom) ต้องกางลงตามเดิม
    assert.equal(shouldOpenUpward({}), false);
});
