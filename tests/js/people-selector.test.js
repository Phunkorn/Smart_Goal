import test from 'node:test';
import assert from 'node:assert/strict';
import {click, clickCheckbox, mountDom, peopleSelectorMarkup, typeInto} from './helpers/dom.js';
import {
    derivePeopleState,
    initializePeopleSelector,
    normalizeKeyword,
    refreshPeopleSelector,
    selectedIdsOf,
    setExcludedIds,
    updateSelection,
} from '../../resources/js/components/people-selector.js';

const PEOPLE = [
    {id: 1, name: 'สมชาย ใจดี', department: 'ไอที', departmentId: 10},
    {id: 2, name: 'สมหญิง ตั้งใจ', department: 'การตลาด', departmentId: 20},
    {id: 3, name: 'ปิติ รักงาน', department: 'ไอที', departmentId: 10},
];
const DEPARTMENTS = [{id: 10, name: 'ไอที'}, {id: 20, name: 'การตลาด'}];

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
