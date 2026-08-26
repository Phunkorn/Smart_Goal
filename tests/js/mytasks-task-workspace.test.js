import test from 'node:test';
import assert from 'node:assert/strict';
import {
    hasWorkspaceChanges,
    shouldSendUpdate,
    workspaceChanges,
    workspaceMenuPosition,
} from '../../resources/js/pages/mytasks/task-workspace-model.js';

const baseline = {
    job_topic: 'ทดสอบ 1',
    job_status: '2',
    job_priority: '4',
    job_start_at: '2026-08-24',
    job_due_at: '2026-08-25',
};

test('บันทึกส่งเฉพาะฟิลด์ที่ผู้ใช้เปลี่ยนจริง', () => {
    assert.deepEqual(workspaceChanges(baseline, {...baseline}), {});
    assert.deepEqual(workspaceChanges(baseline, {...baseline, job_topic: 'ทดสอบ 2'}), {job_topic: 'ทดสอบ 2'});
    assert.deepEqual(
        workspaceChanges(baseline, {...baseline, job_status: '3', job_due_at: '2026-09-01'}),
        {job_status: '3', job_due_at: '2026-09-01'},
    );
});

test('ฟิลด์ที่ไม่ได้อยู่ในรายการของ Workspace จะไม่ถูกส่งไปด้วย', () => {
    // job_details ถูกถอดออกจากหน้าแล้ว ต้องไม่หลุดเข้า payload ไม่ว่ากรณีใด
    const changes = workspaceChanges(baseline, {...baseline, job_details: 'ข้อความเก่า'});

    assert.deepEqual(changes, {});
    assert.ok(! ('job_details' in changes));
});

test('ค่าตัวเลขกับสตริงที่ความหมายเดียวกันไม่นับว่าเปลี่ยน', () => {
    assert.equal(hasWorkspaceChanges(baseline, {...baseline, job_status: 2}), false);
    assert.equal(hasWorkspaceChanges(baseline, {...baseline, job_status: 5}), true);
});

test('เตือน Unsaved Changes เมื่อมีการแก้ที่ยังไม่บันทึกเท่านั้น', () => {
    assert.equal(hasWorkspaceChanges(baseline, {...baseline}), false);
    assert.equal(hasWorkspaceChanges(baseline, {...baseline, job_priority: '3'}), true);
    assert.equal(hasWorkspaceChanges(baseline, {...baseline, job_topic: '  ทดสอบ 1  '.trim()}), false);
});

test('ส่งอัปเดตได้ครั้งเดียวแม้กดซ้ำระหว่างรอผลลัพธ์', () => {
    const payload = {taskId: '9', url: '/tasks/9/comments', message: 'อัปเดตความคืบหน้า'};

    assert.equal(shouldSendUpdate({...payload, pending: false}), true);
    assert.equal(shouldSendUpdate({...payload, pending: true}), false);
});

test('ข้อความว่างหรือขาดปลายทางจะไม่ถูกส่ง', () => {
    assert.equal(shouldSendUpdate({taskId: '9', url: '/tasks/9/comments', message: '   '}), false);
    assert.equal(shouldSendUpdate({taskId: '9', url: '', message: 'มีข้อความ'}), false);
    assert.equal(shouldSendUpdate({taskId: null, url: '/tasks/9/comments', message: 'มีข้อความ'}), false);
    assert.equal(shouldSendUpdate(), false);
});

// แถบสรุปสูงแถวเดียวและมี overflow:hidden เมนูจึงถูกวางแบบ fixed และต้องคำนวณขอบเอง
const desktop = {width: 1920, height: 1080};
const priorityPanel = {width: 150, height: 210};

test('เมนูเปิดใต้ปุ่มเมื่อพื้นที่ด้านล่างเพียงพอ', () => {
    const trigger = {left: 300, top: 180, bottom: 202};

    assert.deepEqual(workspaceMenuPosition(trigger, priorityPanel, desktop), {left: 300, top: 208});
});

test('เมนูพลิกขึ้นด้านบนเมื่อด้านล่างไม่พอแต่ด้านบนพอ', () => {
    // ปุ่มอยู่ล่างจอ เปิดลงจะตกขอบ (960 + 210 > 1080 - 8)
    const trigger = {left: 300, top: 940, bottom: 962};

    assert.deepEqual(workspaceMenuPosition(trigger, priorityPanel, desktop), {left: 300, top: 724});
});

test('จอเตี้ยที่ไม่พอทั้งบนและล่าง เมนูถูกหนีบให้อยู่ในจอเสมอ', () => {
    const shortViewport = {width: 1280, height: 260};
    const trigger = {left: 100, top: 120, bottom: 142};
    const position = workspaceMenuPosition(trigger, priorityPanel, shortViewport);

    assert.equal(position.top, 42);
    assert.ok(position.top >= 8, 'ต้องไม่ทะลุขอบบน');
    assert.ok(position.top + priorityPanel.height <= shortViewport.height, 'ต้องไม่ตกขอบล่าง');
});

test('เมนูใกล้ขอบขวาถูกดึงกลับเข้ามาในจอ', () => {
    const mobile = {width: 375, height: 812};
    const statusPanel = {width: 164, height: 240};

    assert.deepEqual(
        workspaceMenuPosition({left: 340, top: 200, bottom: 222}, statusPanel, mobile),
        {left: 203, top: 228},
    );
    // ขอบซ้ายก็ต้องไม่ติดลบ
    assert.equal(workspaceMenuPosition({left: -20, top: 200, bottom: 222}, statusPanel, mobile).left, 8);
});
