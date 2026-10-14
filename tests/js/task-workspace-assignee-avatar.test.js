import test from 'node:test';
import assert from 'node:assert/strict';
import {click} from './helpers/dom.js';
import {mountTaskWorkspace} from './helpers/task-workspace-fixture.js';

/*
 * ช่อง "ผู้รับผิดชอบ" ในแถบสรุปของ Task Workspace แสดงรูปโปรไฟล์ของผู้รับผิดชอบ
 *
 * ของเดิมเป็นไอคอนรูปคนตายตัวทุกงาน ทั้งที่ [data-owner-data] มีรูปของผู้รับผิดชอบอยู่แล้ว
 * และกล่องข้อมูลผู้รับผิดชอบกับปฏิทินก็ใช้รูปจากชุดเดียวกัน
 */

const avatarOf = (ui) => ui.document.querySelector('[data-task-form] [data-workspace-assignee-avatar]');

const openWorkspace = async (t, options = {}) => {
    const ui = await mountTaskWorkspace(options);
    t.after(ui.cleanup);
    click(ui.openTask());

    return ui;
};

test('ผู้รับผิดชอบที่มีรูปโปรไฟล์ได้รูปของตัวเองแทนไอคอน', async (t) => {
    const ui = await openWorkspace(t, {owners: {7: {name: 'สมชาย', avatar_url: '/media/profile/3', initial: 'ส'}}});
    const avatar = avatarOf(ui);

    assert.equal(avatar.querySelector('img')?.getAttribute('src'), '/media/profile/3');
    assert.equal(avatar.querySelector('.bi-person'), null, 'ไอคอนต้องถูกแทน ไม่ใช่ซ้อนอยู่ใต้รูป');
    assert.ok(avatar.classList.contains('has-avatar'));
});

test('ผู้รับผิดชอบที่ยังไม่มีรูปยังเห็นไอคอนเดิม', async (t) => {
    const ui = await openWorkspace(t, {owners: {7: {name: 'สมชาย', avatar_url: null, initial: 'ส'}}});
    const avatar = avatarOf(ui);

    assert.equal(avatar.querySelector('img'), null);
    assert.ok(avatar.querySelector('.bi-person'));
    assert.equal(avatar.classList.contains('has-avatar'), false);
});

test('เปิดงานที่ไม่มีรูปต่อจากงานที่มีรูป รูปของงานก่อนหน้าต้องไม่ค้าง', async (t) => {
    const ui = await openWorkspace(t, {owners: {7: {name: 'สมชาย', avatar_url: '/media/profile/3', initial: 'ส'}}});
    assert.ok(avatarOf(ui).querySelector('img'));

    // fixture มีแถวข้อมูลแถวเดียว จึงเปลี่ยนให้แถวนั้นเป็นงานอีกใบที่ไม่มีรูปแล้วเปิดใหม่
    click(ui.taskModal.querySelector('[data-close-task]'));
    ui.document.querySelector('[data-row]').dataset.id = '9';
    click(ui.openTask());
    const avatar = avatarOf(ui);

    assert.equal(avatar.querySelector('img'), null, 'งานที่สองไม่มีข้อมูลรูป ต้องกลับเป็นไอคอน');
    assert.equal(avatar.querySelectorAll('.bi-person').length, 1);
    assert.equal(avatar.classList.contains('has-avatar'), false);
});
