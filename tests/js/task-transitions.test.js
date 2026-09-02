import test from 'node:test';
import assert from 'node:assert/strict';
import {canDragTask, canTransitionTo, isModalStatusOptionDisabled, lockReason, nextStepHint} from '../../resources/js/pages/mytasks/task-transitions.js';

test('server allowed_statuses controls every status surface', () => {
    const worker = {can_edit: true, is_final: false, allowed_statuses: [2, 5]};

    assert.equal(canTransitionTo(2, 5, worker), true);
    assert.equal(canTransitionTo(2, 1, worker), false);
    assert.equal(isModalStatusOptionDisabled(2, 5, worker), false);
    assert.equal(isModalStatusOptionDisabled(2, 1, worker), true);
});

test('kanban only drags a worker that has a real destination', () => {
    assert.equal(canDragTask(2, {can_edit: true, is_final: false, allowed_statuses: [2, 5]}), true);
    assert.equal(canDragTask(2, {can_edit: false, is_final: false, allowed_statuses: [2]}), false);
    assert.equal(canDragTask(4, {can_edit: true, is_final: true, allowed_statuses: [4]}), false);
    assert.equal(canDragTask(3, {can_edit: true, is_final: false, allowed_statuses: [3]}), false);
});

/*
 * ป้าย "ขั้นถัดไป" บนการ์ดต้องมาจาก allowed_statuses ที่ server ส่งมาเท่านั้น
 * ผู้ใช้จะได้รู้ตั้งแต่ก่อนลากว่าไปต่อได้ที่ไหน ไม่ใช่ลากแล้วเด้ง error
 */
test('the next-step hint recommends the move that carries the workflow forward', () => {
    const worker = (status, allowed) => nextStepHint(status, {can_edit: true, allowed_statuses: allowed});

    // กำลังทำ → ส่งตรวจ ไม่ใช่พักงาน แม้พักงานจะทำได้เหมือนกัน
    assert.deepEqual(worker(2, [2, 5, 3]), {status: 3, label: 'ส่งตรวจสอบ', viaMenu: false});
    // จากพักงานต้องชวนกลับมาทำก่อน ไม่ใช่ข้ามไปส่งตรวจทันที
    assert.deepEqual(worker(5, [5, 2, 3]), {status: 2, label: 'กลับมาทำ', viaMenu: false});
    // ล่าช้าถอยกลับไม่ได้ ทางเดียวคือส่งตรวจ
    assert.deepEqual(worker(6, [6, 3]), {status: 3, label: 'ส่งตรวจสอบ', viaMenu: false});
    // ผู้ตรวจที่สถานะรอตรวจ งานหลักคือปิดงาน
    assert.deepEqual(worker(3, [3, 2, 4]), {status: 4, label: 'ปิดงาน', viaMenu: false});
});

test('a card with nowhere to go explains why instead of looking draggable', () => {
    assert.equal(nextStepHint(3, {can_edit: true, allowed_statuses: [3]}), null);
    assert.equal(lockReason(3, {can_edit: true, allowed_statuses: [3]}), 'รอผู้มอบหมายตรวจสอบ');

    assert.equal(
        lockReason(4, {can_edit: false, is_final: true, can_reopen: false, allowed_statuses: [4]}),
        'งานปิดแล้ว ผู้ดูแลระบบเท่านั้นที่แก้ไขได้',
    );
    // admin ยังมีขั้นถัดไปคือเปิดงานใหม่ แต่ต้องบอกว่าทำผ่านเมนู ไม่ใช่ลากการ์ด
    assert.deepEqual(
        nextStepHint(4, {can_edit: false, is_final: true, can_reopen: true, allowed_statuses: [4, 2]}),
        {status: 2, label: 'เปิดงานอีกครั้ง', viaMenu: true},
    );
    assert.equal(
        lockReason(2, {can_edit: false, allowed_statuses: [2]}),
        'คุณไม่มีสิทธิ์เปลี่ยนสถานะงานนี้',
    );

    // มีทางไปต่อเมื่อไหร่ ต้องไม่มีเหตุผลล็อกค้างอยู่
    assert.equal(lockReason(2, {can_edit: true, allowed_statuses: [2, 3]}), null);
});

test('the hint never advertises a move the server did not allow', () => {
    // capabilities ว่างเปล่า (เช่นบริบทอ่านอย่างเดียว) ต้องไม่เดาให้เอง
    assert.equal(nextStepHint(2, {}), null);
    assert.equal(nextStepHint(2, {can_edit: true, allowed_statuses: [2]}), null);
    // สถานะปัจจุบันไม่นับเป็นขั้นถัดไป
    assert.equal(nextStepHint(5, {can_edit: true, allowed_statuses: [5]}), null);
});
