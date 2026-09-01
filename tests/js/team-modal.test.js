import test from 'node:test';
import assert from 'node:assert/strict';
import {click, pressKey} from './helpers/dom.js';
import {mountTaskWorkspace} from './helpers/task-workspace-fixture.js';

/**
 * Regression: ปุ่ม .task-workspace__cell-action ใน Task Workspace กดแล้วไม่เปิด Team Modal
 *
 * สาเหตุคือ team IIFE ใน mytasks-task-modal.js ขาดการประกาศ `let activeTeam = null;`
 * ตอน refactor จึงโยน ReferenceError ตั้งแต่บรรทัดแรกของ open() และหยุดทั้ง handler
 * ชุดทดสอบนี้จำลองการคลิกจริงบน DOM เพื่อไม่ให้พลาดแบบเดิมอีก
 */

const PEOPLE = [
    {id: 1, name: 'สมชาย ใจดี', department: 'ไอที', departmentId: 10},
    {id: 2, name: 'สมหญิง ตั้งใจ', department: 'การตลาด', departmentId: 20},
];
const DEPARTMENTS = [{id: 10, name: 'ไอที'}, {id: 20, name: 'การตลาด'}];

async function openWorkspace(t, options = {}) {
    const ui = await mountTaskWorkspace({people: PEOPLE, departments: DEPARTMENTS, ...options});
    t.after(ui.cleanup);
    click(ui.openTask());

    return ui;
}

test('กดปุ่มผู้ร่วมงานใน Task Workspace แล้ว Team Modal เปิดจริง', async (t) => {
    const ui = await openWorkspace(t);

    assert.equal(ui.teamModal.hidden, true);
    click(ui.manageTeam());

    assert.equal(ui.teamModal.hidden, false, 'นี่คือบั๊กเดิมที่ปุ่มกดแล้วเงียบ');
    assert.equal(ui.document.querySelector('[data-team-topic]').textContent, 'งานทดสอบ');
});

test('ปุ่มเป็น type=button จึงไม่ submit ฟอร์มของ Task Workspace', async (t) => {
    const ui = await openWorkspace(t);
    const button = ui.manageTeam();

    assert.equal(button.getAttribute('type'), 'button');
    assert.equal(button.dataset.manageTeam, '7', 'ต้องผูกกับงานที่เปิดอยู่');
});

test('Team Modal ซ้อนเหนือ Task Workspace อย่างถูกต้อง', async (t) => {
    const ui = await openWorkspace(t);
    click(ui.manageTeam());

    assert.equal(ui.taskModal.hasAttribute('inert'), true, 'ชั้นล่างต้องรับ focus ไม่ได้');
    assert.equal(ui.teamModal.hasAttribute('inert'), false);
    assert.equal(ui.teamModal.dataset.modalBackdrop, 'on');
    assert.equal(ui.taskModal.dataset.modalBackdrop, 'off', 'ต้องมี backdrop ชั้นเดียว');
    assert.ok(Number(ui.teamModal.style.zIndex) > Number(ui.taskModal.style.zIndex));
    assert.equal(ui.document.body.classList.contains('modal-open'), true);
});

test('ปิด Team Modal แล้วกลับมาที่ Task Workspace และ focus กลับปุ่มเดิม', async (t) => {
    const ui = await openWorkspace(t);
    const button = ui.manageTeam();
    click(button);
    click(ui.document.querySelector('[data-close-team]'));

    assert.equal(ui.teamModal.hidden, true);
    assert.equal(ui.taskModal.hidden, false, 'Task Workspace ต้องยังเปิดอยู่');
    assert.equal(ui.taskModal.hasAttribute('inert'), false, 'กลับมาเป็นชั้นบนสุด');
    assert.equal(ui.taskModal.dataset.modalBackdrop, 'on');
    assert.equal(ui.document.body.classList.contains('modal-open'), true, 'ห้ามปลดล็อกทั้งที่ยังมี modal เปิด');
    assert.equal(ui.document.activeElement, button, 'focus ต้องกลับไปที่ปุ่มที่กดเปิด');
});

test('Escape ปิดเฉพาะ Team Modal ไม่ทะลุไปปิด Task Workspace', async (t) => {
    const ui = await openWorkspace(t);
    click(ui.manageTeam());

    pressKey(ui.document, 'Escape');

    assert.equal(ui.teamModal.hidden, true);
    assert.equal(ui.taskModal.hidden, false);
});

test('สมาชิกปัจจุบันไม่ปรากฏซ้ำในรายการที่เพิ่มได้ และตัวนับแยกกัน', async (t) => {
    const ui = await openWorkspace(t, {
        team: {
            collaborators: [
                {id: 1, name: 'สมชาย ใจดี', department: 'ไอที', status: 'accepted', is_active: true},
            ],
        },
    });
    click(ui.manageTeam());

    const rows = [...ui.document.querySelectorAll('[data-people-option]')];
    const visible = rows.filter((row) => !row.hidden).map((row) => row.dataset.personId);

    assert.deepEqual(visible, ['2'], 'คนที่อยู่ในทีมแล้วต้องหายจากรายการ ไม่ใช่แสดงแบบจาง');
    assert.equal(ui.document.querySelector('[data-team-count]').textContent, 'ทีมปัจจุบัน (2 คน)');
    assert.equal(ui.document.querySelector('[data-people-count]').textContent, 'เลือกเพิ่ม 0 คน');
    assert.equal(ui.document.querySelectorAll('[data-team-members] .team-member').length, 1, 'สมาชิกต้องแสดงครั้งเดียว');
});

test('บัญชีที่ถูกปิดแสดงสถานะปิดบัญชีและลบผู้รับผิดชอบหลักไม่ได้', async (t) => {
    const ui = await openWorkspace(t, {
        team: {
            collaborators: [
                {id: 1, name: 'สมชาย ใจดี', department: 'ไอที', status: 'accepted', is_active: false},
                {id: 99, name: 'เจ้าของงาน', department: 'ไอที', status: 'accepted', is_active: true},
            ],
        },
    });
    click(ui.manageTeam());

    const html = ui.document.querySelector('[data-team-members]').innerHTML;
    assert.match(html, /team-state inactive/);
    assert.match(html, /ปิดบัญชี/);
    assert.equal(ui.document.querySelector('[data-team-count]').textContent, 'ทีมปัจจุบัน (2 คน)', 'assignee ที่ซ้ำใน collaborators ต้องนับครั้งเดียวใน UI');
    assert.equal(ui.document.querySelectorAll('[data-team-members] .team-member').length, 1);
    // 99 อยู่ใน protected_ids จึงต้องไม่มีปุ่มลบ
    assert.equal(ui.document.querySelector('[data-remove-team-member="99"]'), null);
    assert.ok(ui.document.querySelector('[data-remove-team-member="1"]'));
});

test('ปุ่มยืนยันกดไม่ได้เมื่อยังไม่ได้เลือกใคร และบอกจำนวนเมื่อเลือกแล้ว', async (t) => {
    const ui = await openWorkspace(t);
    click(ui.manageTeam());

    const submit = ui.document.querySelector('[data-team-submit]');
    const label = ui.document.querySelector('[data-team-submit-label]');
    assert.equal(submit.disabled, true);
    assert.equal(label.textContent, 'เพิ่มผู้ร่วมงาน (0 คน)');

    const checkbox = ui.document.querySelector('[data-people-checkbox][value="2"]');
    checkbox.checked = true;
    checkbox.dispatchEvent(new ui.window.Event('change', {bubbles: true}));

    assert.equal(submit.disabled, false);
    assert.equal(label.textContent, 'เพิ่มผู้ร่วมงาน (1 คน)');
});

test('ยกเลิกคนที่เตรียมเพิ่มไม่ยิงคำขอไปที่ server', async (t) => {
    const ui = await openWorkspace(t);
    click(ui.manageTeam());

    const calls = [];
    globalThis.fetch = async (url, init) => {
        calls.push({url, method: init?.method});
        return {ok: true, json: async () => ({})};
    };

    const checkbox = ui.document.querySelector('[data-people-checkbox][value="2"]');
    checkbox.checked = true;
    checkbox.dispatchEvent(new ui.window.Event('change', {bubbles: true}));
    click(ui.document.querySelector('[data-people-remove][data-person-id="2"]'));

    assert.deepEqual(calls, [], 'การยกเลิก selection ต้องไม่เรียก DELETE');
    assert.equal(ui.document.querySelector('[data-people-count]').textContent, 'เลือกเพิ่ม 0 คน');
    assert.equal(ui.document.querySelector('[data-team-submit]').disabled, true);
});

test('ปิดแล้วเปิด Team Modal ใหม่ต้องล้าง staging ที่ยังไม่ได้ยืนยัน', async (t) => {
    const ui = await openWorkspace(t);
    click(ui.manageTeam());

    const checkbox = ui.document.querySelector('[data-people-checkbox][value="2"]');
    checkbox.checked = true;
    checkbox.dispatchEvent(new ui.window.Event('change', {bubbles: true}));
    assert.equal(ui.document.querySelector('[data-people-stage]').hidden, false);

    click(ui.document.querySelector('[data-close-team]'));
    click(ui.manageTeam());

    assert.deepEqual(
        [...ui.document.querySelectorAll('[data-people-checkbox]:checked')].map((node) => node.value),
        [],
    );
    assert.equal(ui.document.querySelector('[data-people-stage]').hidden, true);
    assert.equal(ui.document.querySelector('[data-team-submit-label]').textContent, 'เพิ่มผู้ร่วมงาน (0 คน)');
});

test('protected IDs ถูกกันจากตัวเลือกเพิ่มแม้ยังไม่อยู่ใน collaborators', async (t) => {
    const ui = await openWorkspace(t, {team: {protected_ids: [99, 2]}});
    click(ui.manageTeam());

    const visible = [...ui.document.querySelectorAll('[data-people-option]')]
        .filter((row) => !row.hidden)
        .map((row) => row.dataset.personId);
    assert.deepEqual(visible, ['1']);
});

test('สถานะ pending และ rejected แสดงตามข้อมูล server โดยไม่เปลี่ยน workflow', async (t) => {
    const ui = await openWorkspace(t, {
        team: {
            collaborators: [
                {id: 1, name: 'สมชาย ใจดี', department: 'ไอที', status: 'pending', is_active: true},
                {id: 2, name: 'สมหญิง ตั้งใจ', department: 'การตลาด', status: 'rejected', is_active: true},
            ],
        },
    });
    click(ui.manageTeam());

    assert.equal(ui.document.querySelectorAll('.team-state.pending').length, 1);
    assert.equal(ui.document.querySelectorAll('.team-state.rejected').length, 1);
    assert.match(ui.document.querySelector('.team-state.pending').textContent, /รออนุมัติ/);
    assert.match(ui.document.querySelector('.team-state.rejected').textContent, /ไม่อนุมัติ/);
});

test('ลบสมาชิกปัจจุบันใช้ remove route เดิมและถามยืนยันก่อน', async (t) => {
    const ui = await openWorkspace(t, {
        team: {collaborators: [{id: 1, name: 'สมชาย ใจดี', department: 'ไอที', status: 'accepted', is_active: true}]},
    });
    click(ui.manageTeam());

    const calls = [];
    globalThis.fetch = async (url, init) => {
        calls.push({url, method: init?.method});
        return {ok: true, json: async () => ({})};
    };

    let asked = 0;
    ui.window.confirm = () => { asked += 1; return true; };
    globalThis.window.confirm = ui.window.confirm;

    click(ui.document.querySelector('[data-remove-team-member="1"]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(asked, 1, 'ต้องถามยืนยันก่อนลบสมาชิกจริง');
    assert.deepEqual(calls, [{url: '/tasks/7/collaborators/1', method: 'DELETE'}]);
});

test('เปิดปิดรายละเอียดงานหลายครั้งไม่ทำให้ listener ซ้ำ', async (t) => {
    const ui = await openWorkspace(t);

    for (let round = 0; round < 3; round += 1) {
        click(ui.manageTeam());
        assert.equal(ui.teamModal.hidden, false);
        click(ui.document.querySelector('[data-close-team]'));
        assert.equal(ui.teamModal.hidden, true);

        click(ui.document.querySelector('[data-close-task]'));
        click(ui.openTask());
    }

    // ถ้ามี listener ซ้ำ chip จะถูกวาดซ้ำหลายชุด
    click(ui.manageTeam());
    const checkbox = ui.document.querySelector('[data-people-checkbox][value="1"]');
    checkbox.checked = true;
    checkbox.dispatchEvent(new ui.window.Event('change', {bubbles: true}));

    assert.equal(ui.document.querySelectorAll('[data-people-chip]').length, 1);
    assert.equal(ui.document.querySelectorAll('[data-team-modal]').length, 1, 'ต้องมี Team Modal ชุดเดียวในหน้า');
});

test('ผู้ที่ไม่มีสิทธิ์จัดการทีมเปิดดูได้แต่แก้ไม่ได้ และปุ่มไม่หลอกว่ากดได้', async (t) => {
    const ui = await openWorkspace(t, {
        team: {
            can_manage: false,
            collaborators: [{id: 1, name: 'สมชาย ใจดี', department: 'ไอที', status: 'accepted', is_active: true}],
        },
    });
    click(ui.manageTeam());

    const notice = ui.document.querySelector('[data-team-notice]');
    assert.equal(notice.hidden, false);
    assert.match(notice.textContent, /ไม่มีสิทธิ์แก้ไข/);
    assert.equal(ui.document.querySelector('[data-team-submit]').disabled, true);
    assert.equal(ui.document.querySelector('[data-people-selector]').dataset.readonly, 'true');
    assert.equal(ui.document.querySelector('[data-remove-team-member="1"]'), null, 'ไม่มีสิทธิ์จึงต้องไม่มีปุ่มลบให้กด');
});

test('งานที่ปิดแล้วถูกล็อกและบอกเหตุผลชัดเจน', async (t) => {
    const ui = await openWorkspace(t, {team: {locked: true}});
    click(ui.manageTeam());

    const notice = ui.document.querySelector('[data-team-notice]');
    assert.equal(notice.hidden, false);
    assert.match(notice.textContent, /งานที่เสร็จแล้วถูกล็อก/);
    assert.equal(ui.document.querySelector('[data-team-submit]').disabled, true);
});

test('Admin Member Workspace ใช้ component และ JavaScript ชุดเดียวกัน', async (t) => {
    const ui = await openWorkspace(t, {context: 'admin-member'});
    click(ui.manageTeam());

    assert.equal(ui.teamModal.hidden, false);
    assert.ok(ui.document.querySelector('[data-people-selector]'), 'ใช้ people-selector ตัวเดียวกับฝั่ง User');
    assert.equal(ui.document.querySelector('[data-people-checkbox]').name, 'collaborators[]', 'ต้องยิงไป route ของ Task ไม่ใช่ Meeting');
    assert.equal(ui.document.querySelectorAll('[data-team-modal]').length, 1);
});
