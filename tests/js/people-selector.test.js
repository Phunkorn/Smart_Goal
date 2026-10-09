import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {click, clickCheckbox, mountDom, peopleSelectorMarkup, typeInto} from './helpers/dom.js';
import {
    derivePeopleState,
    initializePeopleSelector,
    normalizeKeyword,
    refreshPeopleSelector,
    selectedIdsOf,
    setExcludedIds,
    updateSelection,
    visibleSelectableIds,
} from '../../resources/js/components/people-selector.js';

const PEOPLE = [
    {id: 1, name: 'สมชาย ใจดี', department: 'ไอที', departmentId: 10},
    {id: 2, name: 'สมหญิง ตั้งใจ', department: 'การตลาด', departmentId: 20},
    {id: 3, name: 'ปิติ รักงาน', department: 'ไอที', departmentId: 10},
];
const DEPARTMENTS = [{id: 10, name: 'ไอที'}, {id: 20, name: 'การตลาด'}];

const dialogsCss = readFileSync(new URL('../../resources/css/components/task-workspace/dialogs.css', import.meta.url), 'utf8');

/** เนื้อในของกฎ CSS หนึ่งกฎ ระบุด้วย selector เต็ม ๆ พร้อมปีกกาเปิด */
function ruleBody(css, selectorWithBrace) {
    const start = css.indexOf(selectorWithBrace);
    assert.notEqual(start, -1, `ไม่พบกฎ ${selectorWithBrace}`);

    return css.slice(start, css.indexOf('}', start));
}

function mountSelector(options = {}) {
    const env = mountDom();
    env.document.body.innerHTML = peopleSelectorMarkup({people: PEOPLE, departments: DEPARTMENTS, ...options});
    const root = env.document.querySelector('[data-people-selector]');
    initializePeopleSelector(root);

    return {
        ...env,
        root,
        rows: () => [...root.querySelectorAll('[data-people-option]')],
        visibleIds: () => [...root.querySelectorAll('[data-people-option]')].filter((row) => !row.hidden).map((row) => row.dataset.personId),
        chipIds: () => [...root.querySelectorAll('[data-people-chip]')].map((chip) => chip.dataset.personId),
        checkbox: (id) => root.querySelector(`[data-people-checkbox][value="${id}"]`),
        count: () => root.querySelector('[data-people-count]').textContent,
    };
}

/* ---------- pure logic ---------- */

test('คำค้นถูกตัดช่องว่างและเทียบแบบไม่สนตัวพิมพ์', () => {
    assert.equal(normalizeKeyword('  ABC  '), 'abc');
    assert.equal(normalizeKeyword(null), '');
});

test('รายการที่เลือกไม่ขึ้นกับตัวกรอง', () => {
    const options = [
        {id: 1, departmentId: 10, search: 'สมชาย ไอที', checked: true},
        {id: 2, departmentId: 20, search: 'สมหญิง การตลาด', checked: false},
        {id: 3, departmentId: 10, search: 'ปิติ ไอที', checked: true},
    ];

    assert.deepEqual(derivePeopleState(options, '', 20).visibleIds, ['2']);
    assert.deepEqual(derivePeopleState(options, '', 20).selectedIds, ['1', '3']);
    assert.deepEqual(derivePeopleState(options, 'ปิติ', 10).visibleIds, ['3']);
});

test('updateSelection เพิ่มและถอดแบบไม่ซ้ำ', () => {
    assert.deepEqual(updateSelection(['1'], 2, true), ['1', '2']);
    assert.deepEqual(updateSelection(['1', '2'], '1', false), ['2']);
    assert.deepEqual(updateSelection(['1'], 1, true), ['1']);
});

/* ---------- DOM behaviour ---------- */

test('ค้นหาได้ทั้งจากชื่อและจากแผนก', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    typeInto(ui.root.querySelector('[data-people-search]'), 'ปิติ');
    assert.deepEqual(ui.visibleIds(), ['3']);

    typeInto(ui.root.querySelector('[data-people-search]'), 'การตลาด');
    assert.deepEqual(ui.visibleIds(), ['2'], 'ค้นด้วยชื่อแผนกต้องเจอคนในแผนกนั้น');

    typeInto(ui.root.querySelector('[data-people-search]'), '');
    assert.deepEqual(ui.visibleIds(), ['1', '2', '3']);
});

test('กรองตามแผนกและรวมกับคำค้นแบบ AND', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    click(ui.root.querySelector('[data-people-department][data-department-id="10"]'));
    assert.deepEqual(ui.visibleIds(), ['1', '3']);
    assert.equal(ui.root.querySelector('[data-people-department][data-department-id="10"]').getAttribute('aria-pressed'), 'true');
    assert.equal(ui.root.querySelector('[data-people-department][data-department-id=""]').getAttribute('aria-pressed'), 'false');

    typeInto(ui.root.querySelector('[data-people-search]'), 'สมชาย');
    assert.deepEqual(ui.visibleIds(), ['1']);

    click(ui.root.querySelector('[data-people-department][data-department-id=""]'));
    assert.deepEqual(ui.visibleIds(), ['1'], 'คำค้นยังมีผลอยู่หลังกดทั้งหมด');
});

test('Team variant ซิงก์ dropdown กับ chips และล้าง staging ได้โดยไม่กระทบสมาชิกจริง', (t) => {
    const ui = mountSelector({variant: 'team-manager'});
    t.after(ui.cleanup);

    const select = ui.root.querySelector('[data-people-department-select]');
    select.value = '20';
    select.dispatchEvent(new ui.window.Event('change', {bubbles: true}));

    assert.deepEqual(ui.visibleIds(), ['2']);
    assert.equal(ui.root.querySelector('[data-people-department][data-department-id="20"]').getAttribute('aria-pressed'), 'true');

    clickCheckbox(ui.checkbox(2));
    assert.equal(ui.root.querySelector('[data-people-stage]').hidden, false);
    assert.equal(ui.root.querySelector('[data-people-summary-count]').textContent, 'เลือกแล้ว 1 คน');

    click(ui.root.querySelector('[data-people-clear]'));
    assert.deepEqual(selectedIdsOf(ui.root), []);
    assert.equal(ui.root.querySelector('[data-people-stage]').hidden, true);
    assert.equal(ui.root.querySelector('[data-people-clear]').disabled, true);
});

/* ---------- โครงคอลัมน์ในโมดัลทีม ---------- */

/*
 * Regression: รายชื่อในโมดัลทีมทะลุกรอบการ์ดแล้วถูกตัดท้ายทิ้ง
 *
 * คอลัมน์ซ้ายเคยกำหนดความสูงแถวด้วยมือเป็น grid-template-rows 5 แถว
 * ขณะที่กล่องมีลูกจริง 6 ตัว (หัวเรื่อง / ช่องค้นหา+ดร็อปดาวน์ / ปุ่มกรองแผนก /
 * แถวเลือกทีละกลุ่ม / รายชื่อ / ตัวนับ) แถว minmax(0,1fr) จึงไปตกที่
 * "แถวเลือกทีละกลุ่ม" แทนรายชื่อ รายชื่อกลายเป็น auto สูงเท่าเนื้อหาจริง
 * เลื่อนในตัวเองไม่ได้ แล้วล้นไปถูก .team-modal-card (overflow:hidden) ตัดทิ้ง
 *
 * เทสต์นี้ตรึงว่า "รายชื่อ" ต้องเป็นแถวที่กินที่ที่เหลือ และคอลัมน์ต้องไม่กลับไป
 * นับแถวด้วยมืออีก เพราะการเพิ่มแถวใหม่ในอนาคตจะทำให้พลาดแบบเดิมซ้ำ
 * (jsdom ไม่คิด layout จริง จึงตรวจที่ตัวสัญญาใน CSS ไม่ใช่ความสูงที่วัดได้)
 */
test('คอลัมน์รายชื่อในโมดัลทีมให้รายชื่อเป็นแถวที่กินที่ที่เหลือ ไม่ใช่นับแถวด้วยมือ', () => {
    const browser = ruleBody(dialogsCss, '.team-modal .people-selector-field--team-manager .people-selector__browser {');

    assert.match(browser, /display:\s*flex/);
    assert.match(browser, /flex-direction:\s*column/);
    assert.ok(
        ! browser.includes('grid-template-rows'),
        'ห้ามนับแถวด้วยมือ จำนวนแถวต้องไม่ผูกกับจำนวนลูกที่ Blade วาด'
    );

    const options = ruleBody(dialogsCss, '.team-modal .people-selector-field--team-manager .people-selector__options {');

    assert.match(options, /flex:\s*1 1 0/, 'รายชื่อต้องเป็นแถวเดียวที่ยืดได้');
    assert.match(options, /min-height:\s*0/, 'ไม่มี min-height:0 แถวที่ยืดได้จะไม่ยอมหดให้ scroll');
});

/* ---------- ดร็อปดาวน์แผนก ---------- */

/*
 * Regression: ตัวกรองแผนกในโมดัลทีมเป็น <select> ดิบของเบราว์เซอร์
 *
 * กล่องรอบนอกถูกจัดสไตล์ไว้ แต่ "แผงรายการ" ที่กางออกมาเป็น popup ของระบบปฏิบัติการ
 * ซึ่ง CSS แตะไม่ได้เลย ผู้ใช้จึงเห็นรายการหน้าตาหลุดจากดีไซน์ของทั้งระบบ
 *
 * ระบบมีดร็อปดาวน์กลางอยู่แล้ว (components/select-dropdown.js) ที่ตัวกรองสถานะ
 * และหน้ารายงานใช้ ตัวเลือกบุคคลแค่ไม่ได้ opt in เข้าไปใช้ — ห้ามเขียนชุดใหม่
 */
test('ตัวกรองแผนกถูกยกระดับเป็นดร็อปดาวน์ของระบบ ไม่ใช่ <select> ดิบของเบราว์เซอร์', (t) => {
    const ui = mountSelector({variant: 'team-manager'});
    t.after(ui.cleanup);

    const box = ui.root.querySelector('.people-selector__department-select');
    const select = box.querySelector('[data-people-department-select]');
    const trigger = box.querySelector('.sg-select__trigger');

    assert.ok(trigger, 'ต้องมีปุ่มของดร็อปดาวน์ที่สคริปต์วาดให้');
    assert.equal(box.querySelector('.sg-select__panel')?.getAttribute('role'), 'listbox');
    assert.equal(trigger.getAttribute('aria-haspopup'), 'listbox');
    // <select> เดิมยังอยู่เป็นแหล่งความจริงเดียวของค่าที่กรอง แต่ถูกซ่อนจากสายตา
    assert.ok(select.classList.contains('sg-select__native'), '<select> เดิมต้องยังอยู่ในฟอร์ม');
    assert.equal(trigger.textContent.trim(), 'แผนกทั้งหมด');
    // ชื่อที่ screen reader อ่านมาจาก <label for> ของกล่อง ไม่ใช่ปุ่มเปล่า ๆ
    assert.match(trigger.getAttribute('aria-labelledby') || '', /-department-filter-label /);
});

test('เลือกแผนกจากแผงดร็อปดาวน์แล้วรายชื่อถูกกรองจริง ตั้งแต่กดปุ่มจนเห็นผล', (t) => {
    const ui = mountSelector({variant: 'team-manager'});
    t.after(ui.cleanup);

    const box = ui.root.querySelector('.people-selector__department-select');
    const dropdown = box.querySelector('.sg-select');

    click(box.querySelector('.sg-select__trigger'));
    assert.equal(dropdown.classList.contains('is-open'), true, 'กดปุ่มแล้วแผงต้องกาง');

    click([...box.querySelectorAll('.sg-select__option')].find((item) => item.dataset.value === '20'));

    assert.deepEqual(ui.visibleIds(), ['2']);
    assert.equal(box.querySelector('.sg-select__trigger').textContent.trim(), 'การตลาด');
    assert.equal(dropdown.classList.contains('is-open'), false, 'เลือกแล้วต้องปิดแผง');
    // ปุ่มกรองแผนกชุดเดิมต้องขยับตามด้วย ตัวกรองทั้งสองทางคือค่าเดียวกัน
    assert.equal(ui.root.querySelector('[data-people-department][data-department-id="20"]').getAttribute('aria-pressed'), 'true');
});

/*
 * ดร็อปดาวน์กลางซิงก์ป้ายบนปุ่มจาก event 'change' เท่านั้น
 * setActiveDepartment() เคยตั้ง select.value ตรง ๆ โดยไม่ยิง event ป้ายจึงค้าง
 * ชื่อแผนกเดิมขณะที่รายชื่อถูกกรองด้วยแผนกใหม่แล้ว — อ่านแล้วขัดกันเอง
 */
test('กดปุ่มกรองแผนกแล้วป้ายบนดร็อปดาวน์ต้องเปลี่ยนตาม ไม่ค้างชื่อแผนกเดิม', (t) => {
    const ui = mountSelector({variant: 'team-manager'});
    t.after(ui.cleanup);

    click(ui.root.querySelector('[data-people-department][data-department-id="10"]'));

    assert.deepEqual(ui.visibleIds(), ['1', '3']);
    assert.equal(ui.root.querySelector('.sg-select__trigger').textContent.trim(), 'ไอที');

    click(ui.root.querySelector('[data-people-department][data-department-id=""]'));
    assert.equal(ui.root.querySelector('.sg-select__trigger').textContent.trim(), 'แผนกทั้งหมด');
});

test('เลือกหลายคนแล้วขึ้นเป็น chip พร้อมจำนวน', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    clickCheckbox(ui.checkbox(1));
    clickCheckbox(ui.checkbox(3));

    assert.deepEqual(ui.chipIds(), ['1', '3']);
    assert.equal(ui.count(), 'เลือกแล้ว 2 คน');
    assert.deepEqual(selectedIdsOf(ui.root), [1, 3]);
});

test('กด × ที่ chip แล้วเอาคนนั้นออกจริง', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    clickCheckbox(ui.checkbox(1));
    clickCheckbox(ui.checkbox(2));
    click(ui.root.querySelector('[data-people-remove][data-person-id="1"]'));

    assert.deepEqual(ui.chipIds(), ['2']);
    assert.equal(ui.checkbox(1).checked, false);
    assert.equal(ui.count(), 'เลือกแล้ว 1 คน');
});

test('คนที่เลือกไว้ไม่หายเมื่อเปลี่ยนคำค้นหรือแผนก', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    clickCheckbox(ui.checkbox(2));
    assert.deepEqual(ui.chipIds(), ['2']);

    // กรองไปแผนกอื่นจนแถวของคนที่เลือกถูกซ่อน
    click(ui.root.querySelector('[data-people-department][data-department-id="10"]'));
    assert.deepEqual(ui.visibleIds(), ['1', '3']);
    assert.deepEqual(ui.chipIds(), ['2'], 'chip ต้องยังอยู่แม้แถวถูกซ่อน');
    assert.equal(ui.checkbox(2).checked, true);
    assert.deepEqual(selectedIdsOf(ui.root), [2]);

    typeInto(ui.root.querySelector('[data-people-search]'), 'ไม่มีใครชื่อนี้');
    assert.deepEqual(ui.chipIds(), ['2']);
    assert.equal(ui.root.querySelector('[data-people-empty]').hidden, false);
});

test('ถอด chip ของคนที่ถูกตัวกรองซ่อนอยู่ได้', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    clickCheckbox(ui.checkbox(2));
    click(ui.root.querySelector('[data-people-department][data-department-id="10"]'));
    click(ui.root.querySelector('[data-people-remove][data-person-id="2"]'));

    assert.deepEqual(ui.chipIds(), []);
    assert.equal(ui.checkbox(2).checked, false);
});

test('โหมดอ่านอย่างเดียวเลือกไม่ได้และปุ่มลบถูกปิด', (t) => {
    const ui = mountSelector({readOnly: true, selected: [1]});
    t.after(ui.cleanup);

    assert.equal(ui.checkbox(2).disabled, true);
    assert.equal(ui.root.querySelector('[data-people-remove][data-person-id="1"]').disabled, true);

    click(ui.root.querySelector('[data-people-remove][data-person-id="1"]'));
    assert.deepEqual(ui.chipIds(), ['1'], 'กดลบไม่ได้ chip ต้องยังอยู่');
});

test('คนที่ถูกกำหนดว่าเลือกซ้ำไม่ได้จะถูก disable ไว้', (t) => {
    const ui = mountSelector({disabled: [3]});
    t.after(ui.cleanup);

    assert.equal(ui.checkbox(3).disabled, true);
    assert.equal(ui.checkbox(1).disabled, false);
});

test('refreshPeopleSelector วาดใหม่หลังโค้ดภายนอกแก้ checkbox เอง', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    ui.checkbox(1).checked = true;
    assert.deepEqual(ui.chipIds(), [], 'ยังไม่วาดจนกว่าจะสั่ง refresh');

    refreshPeopleSelector(ui.root);
    assert.deepEqual(ui.chipIds(), ['1']);
    assert.equal(ui.count(), 'เลือกแล้ว 1 คน');
});

test('สอง instance ในหน้าเดียวกันไม่รบกวนกัน', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML =
        peopleSelectorMarkup({instanceId: 'meeting', inputName: 'attendees[]', people: PEOPLE, departments: DEPARTMENTS})
        + peopleSelectorMarkup({instanceId: 'task', inputName: 'collaborators[]', people: PEOPLE, departments: DEPARTMENTS});

    const [first, second] = [...env.document.querySelectorAll('[data-people-selector]')];
    initializePeopleSelector(first);
    initializePeopleSelector(second);

    clickCheckbox(first.querySelector('[data-people-checkbox][value="1"]'));
    typeInto(first.querySelector('[data-people-search]'), 'ปิติ');

    assert.deepEqual(selectedIdsOf(first), [1]);
    assert.deepEqual(selectedIdsOf(second), [], 'อีกอินสแตนซ์ต้องไม่ถูกเลือกตาม');
    assert.equal(second.querySelectorAll('[data-people-option]:not([hidden])').length, 3, 'ตัวกรองต้องไม่ข้ามอินสแตนซ์');

    // id ของ input ต้องไม่ชนกัน
    const ids = [...env.document.querySelectorAll('[data-people-checkbox]')].map((node) => node.id);
    assert.equal(new Set(ids).size, ids.length);
    assert.equal(first.querySelector('[data-people-checkbox]').name, 'attendees[]');
    assert.equal(second.querySelector('[data-people-checkbox]').name, 'collaborators[]');
});

test('เรียก initialize ซ้ำไม่ผูก event ซ้อน', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    initializePeopleSelector(ui.root);
    initializePeopleSelector(ui.root);
    clickCheckbox(ui.checkbox(1));

    assert.deepEqual(ui.chipIds(), ['1']);
    assert.equal(ui.root.querySelectorAll('[data-people-chip]').length, 1);
});

/* ---------- คนที่อยู่ในทีมแล้วต้องหายไปจากรายการ ไม่ใช่แสดงแบบสีจาง ---------- */

test('setExcludedIds เอาคนออกจากรายการจริง ไม่ใช่แค่ทำให้จาง', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    setExcludedIds(ui.root, [1, 3]);

    assert.deepEqual(ui.visibleIds(), ['2'], 'คนที่อยู่ในทีมแล้วต้องไม่โผล่ในรายการเพิ่ม');
    assert.equal(ui.rows().find((row) => row.dataset.personId === '1').hasAttribute('data-people-excluded'), true);
    assert.equal(ui.checkbox(1).disabled, true);
});

test('คนที่ถูกกันออกไม่ถูกนับเป็นที่เลือกไว้แม้เคยติ๊กไว้ก่อน', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    clickCheckbox(ui.checkbox(1));
    assert.deepEqual(selectedIdsOf(ui.root), [1]);

    setExcludedIds(ui.root, [1]);
    assert.deepEqual(selectedIdsOf(ui.root), [], 'ต้องไม่ค้างอยู่ใน payload');
    assert.deepEqual(ui.chipIds(), []);
    assert.equal(ui.checkbox(1).checked, false);
});

test('ตัวนับใช้ข้อความตาม countTemplate ของผู้เรียก', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = peopleSelectorMarkup({people: PEOPLE, departments: DEPARTMENTS});
    const root = env.document.querySelector('[data-people-selector]');
    root.querySelector('[data-people-count]').dataset.countTemplate = 'เลือกเพิ่ม :count คน';
    initializePeopleSelector(root);

    assert.equal(root.querySelector('[data-people-count]').textContent, 'เลือกเพิ่ม 0 คน');
    clickCheckbox(root.querySelector('[data-people-checkbox][value="2"]'));
    assert.equal(root.querySelector('[data-people-count]').textContent, 'เลือกเพิ่ม 1 คน');
});

test('ประกาศ event ทุกครั้งที่ selection เปลี่ยน เพื่อให้ปุ่มหลักอัปเดตตาม', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    const seen = [];
    ui.root.addEventListener('peopleselector:change', (event) => seen.push(event.detail.selectedIds));

    clickCheckbox(ui.checkbox(1));
    clickCheckbox(ui.checkbox(2));
    click(ui.root.querySelector('[data-people-remove][data-person-id="1"]'));

    assert.deepEqual(seen.at(-1), [2]);
    assert.ok(seen.length >= 3);
});

test('โหมดอ่านอย่างเดียวยังแสดง chip ของคนที่เลือกไว้ก่อนหน้า', (t) => {
    const ui = mountSelector({readOnly: true, selected: [2]});
    t.after(ui.cleanup);

    assert.deepEqual(ui.chipIds(), ['2'], 'readOnly ต่างจาก excluded ต้องยังเห็นของเดิม');
    assert.deepEqual(selectedIdsOf(ui.root), [2]);
});

/* ---------- showAvatar แยกอิสระจาก team-manager (เช่น ผู้เข้าร่วมประชุม) ---------- */

test('showAvatar=true โดยไม่มี variant team-manager แสดง avatar ได้ แต่ไม่พ่วงฟีเจอร์อื่นของ team-manager', (t) => {
    const peopleWithAvatar = [
        {...PEOPLE[0], avatarUrl: 'https://example.test/avatar/1.jpg'},
        PEOPLE[1],
        PEOPLE[2],
    ];
    const ui = mountSelector({people: peopleWithAvatar, showAvatar: true});
    t.after(ui.cleanup);

    // แถวตัวเลือก: คนมีรูปได้ <img>, คนไม่มีรูปได้ตัวอักษรย่อ
    const rowOne = ui.root.querySelector('[data-people-option][data-person-id="1"]');
    const rowTwo = ui.root.querySelector('[data-people-option][data-person-id="2"]');
    assert.equal(rowOne.querySelector('.people-selector__avatar img')?.getAttribute('src'), 'https://example.test/avatar/1.jpg');
    assert.equal(rowTwo.querySelector('.people-selector__avatar img'), null);
    assert.ok(rowTwo.querySelector('.people-selector__avatar')?.textContent.length > 0, 'ไม่มีรูปต้องเห็นตัวอักษรย่อแทน');

    // chip ก็ต้องมี avatar เช่นกัน
    clickCheckbox(ui.checkbox(1));
    clickCheckbox(ui.checkbox(2));
    const chipOne = ui.root.querySelector('[data-people-chip][data-person-id="1"]');
    const chipTwo = ui.root.querySelector('[data-people-chip][data-person-id="2"]');
    assert.equal(chipOne.querySelector('.people-selector__chip-avatar img')?.getAttribute('src'), 'https://example.test/avatar/1.jpg');
    assert.ok(chipTwo.querySelector('.people-selector__chip-avatar')?.textContent.length > 0);

    // ฟีเจอร์เฉพาะ team-manager ต้องไม่ถูกเปิดตามมาด้วย
    assert.equal(ui.root.querySelector('[data-people-department-select]'), null);
    assert.equal(ui.root.querySelector('[data-people-summary-count]'), null);
    assert.equal(ui.root.querySelector('.people-selector__department-badge'), null);
    assert.equal(chipOne.tagName.toLowerCase(), 'span', 'chip ของ showAvatar เฉย ๆ ยังเป็นแท็กเดิม ไม่ใช่ article แบบ team-manager');
});

test('ไม่เปิด showAvatar แล้วไม่มี avatar โผล่ในแถวหรือ chip', (t) => {
    const ui = mountSelector();
    t.after(ui.cleanup);

    clickCheckbox(ui.checkbox(1));

    assert.equal(ui.root.querySelector('.people-selector__avatar'), null);
    assert.equal(ui.root.querySelector('.people-selector__chip-avatar'), null);
});

/* ---------- เลือกทีละกลุ่ม ---------- */

/*
 * ปุ่มนี้อยู่ใน component กลาง จึงได้ทุกที่ที่เลือกบุคคล:
 * ผู้เข้าร่วมประชุม ผู้ร่วมนัดหมาย และผู้ร่วมงานของ Task
 */
test('visibleSelectableIds เคารพทั้งคำค้นและแผนกพร้อมกัน', () => {
    const options = [
        {id: 1, departmentId: 10, search: 'สมชาย ใจดี ไอที', checked: false, excluded: false},
        {id: 2, departmentId: 20, search: 'สมหญิง ตั้งใจ การตลาด', checked: false, excluded: false},
        {id: 3, departmentId: 10, search: 'ปิติ รักงาน ไอที', checked: false, excluded: false},
    ];

    assert.deepEqual(visibleSelectableIds(options), ['1', '2', '3']);
    assert.deepEqual(visibleSelectableIds(options, '', 10), ['1', '3']);
    // คำค้นต้องตัดคนในแผนกเดียวกันที่ไม่ตรงออกด้วย ไม่ใช่กรองแค่แผนก
    assert.deepEqual(visibleSelectableIds(options, 'ปิติ', 10), ['3']);
    assert.deepEqual(visibleSelectableIds(options, 'ไม่มีใครชื่อนี้'), []);
});

test('visibleSelectableIds ไม่คืนคนที่ถูกกันออกจากรายการ', () => {
    const options = [
        {id: 1, departmentId: 10, search: 'สมชาย', checked: false, excluded: false},
        {id: 2, departmentId: 10, search: 'สมหญิง', checked: false, excluded: true},
    ];

    assert.deepEqual(visibleSelectableIds(options, '', 10), ['1']);
});

test('กด "เลือกทั้งแผนก" เลือกเฉพาะคนในแผนกที่กรองอยู่', () => {
    const ui = mountSelector();

    click(ui.root.querySelector('[data-people-department][data-department-id="10"]'));
    click(ui.root.querySelector('[data-people-select-visible]'));

    assert.deepEqual(selectedIdsOf(ui.root), [1, 3]);
});

test('ป้ายปุ่มบอกสิ่งที่จะเกิดขึ้นจริงตามตัวกรองที่ใช้อยู่', () => {
    const ui = mountSelector();
    const button = ui.root.querySelector('[data-people-select-visible]');

    assert.equal(button.textContent, 'เลือกทั้งหมดที่แสดง');

    click(ui.root.querySelector('[data-people-department][data-department-id="10"]'));
    assert.equal(button.textContent, 'เลือกทั้งแผนก');

    click(ui.root.querySelector('[data-people-department][data-department-id=""]'));
    assert.equal(button.textContent, 'เลือกทั้งหมดที่แสดง');
});

test('เลือกทีละแผนกสะสมกันได้ ไม่ล้างของแผนกก่อนหน้า', () => {
    const ui = mountSelector();

    click(ui.root.querySelector('[data-people-department][data-department-id="10"]'));
    click(ui.root.querySelector('[data-people-select-visible]'));
    click(ui.root.querySelector('[data-people-department][data-department-id="20"]'));
    click(ui.root.querySelector('[data-people-select-visible]'));

    assert.deepEqual(selectedIdsOf(ui.root).sort(), [1, 2, 3]);
});

test('ปุ่มเลือกทั้งหมดไม่แตะคนที่ถูกกันออกจากรายการ', () => {
    const ui = mountSelector();

    setExcludedIds(ui.root, [3]);
    click(ui.root.querySelector('[data-people-select-visible]'));

    assert.deepEqual(selectedIdsOf(ui.root).sort(), [1, 2], 'คนที่อยู่ในทีมแล้วต้องไม่ถูกเลือกซ้ำ');
});

test('ปุ่มเลือกทั้งหมดไม่แตะ checkbox ที่ถูกปิดไว้', () => {
    const ui = mountSelector({disabled: [2]});

    click(ui.root.querySelector('[data-people-select-visible]'));

    assert.deepEqual(selectedIdsOf(ui.root).sort(), [1, 3]);
    assert.equal(ui.checkbox(2).checked, false);
});

test('โหมดอ่านอย่างเดียวกดเลือกทั้งหมดไม่ได้', () => {
    const ui = mountSelector({readOnly: true});

    assert.equal(ui.root.querySelector('[data-people-select-visible]').disabled, true);

    click(ui.root.querySelector('[data-people-select-visible]'));
    assert.deepEqual(selectedIdsOf(ui.root), []);
});

test('ปุ่มถูกปิดเมื่อไม่มีใครให้เลือก และเปิดกลับเมื่อมี', () => {
    const ui = mountSelector();
    const button = ui.root.querySelector('[data-people-select-visible]');

    typeInto(ui.root.querySelector('[data-people-search]'), 'ไม่มีใครชื่อนี้');
    assert.equal(button.disabled, true);

    typeInto(ui.root.querySelector('[data-people-search]'), '');
    assert.equal(button.disabled, false);
});

test('ปุ่มล้างการเลือกมีทุก variant ไม่ใช่เฉพาะ team-manager', () => {
    const ui = mountSelector();

    click(ui.root.querySelector('[data-people-select-visible]'));
    assert.equal(selectedIdsOf(ui.root).length, 3);

    click(ui.root.querySelector('[data-people-clear]'));
    assert.deepEqual(selectedIdsOf(ui.root), []);
});

test('การเลือกทั้งหมดยิง peopleselector:change ครั้งเดียวให้ผู้เรียกภายนอก', () => {
    const ui = mountSelector();
    const payloads = [];

    ui.root.addEventListener('peopleselector:change', (event) => payloads.push(event.detail.selectedIds));
    click(ui.root.querySelector('[data-people-select-visible]'));

    assert.equal(payloads.length, 1, 'ตัวนับฝั่งผู้เรียกต้องอัปเดตรอบเดียว');
    assert.deepEqual(payloads[0].sort(), [1, 2, 3]);
});

test('เรียก initializePeopleSelector ซ้ำไม่ทำให้กดหนึ่งครั้งทำงานสองรอบ', () => {
    const ui = mountSelector();
    const payloads = [];

    initializePeopleSelector(ui.root);
    ui.root.addEventListener('peopleselector:change', (event) => payloads.push(event.detail.selectedIds));
    click(ui.root.querySelector('[data-people-select-visible]'));

    assert.equal(payloads.length, 1);
});
