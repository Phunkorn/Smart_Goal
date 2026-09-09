import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {
    openSubtaskCount,
    openSubtasksFor,
    subtaskCounts,
    subtaskGateMessage,
    syncSubtaskGate,
} from '../../resources/js/pages/mytasks/subtask-gate.js';
import {confirmTaskTransition} from '../../resources/js/pages/mytasks/task-transitions.js';

/**
 * ด่าน "งานย่อยต้องเสร็จก่อน"
 *
 * กฎจริงบังคับที่ TaskStatusTransitionService ฝั่ง server (ดู WorkOrderSubtaskGateTest)
 * เทสต์ชุดนี้คุมสิ่งที่ฝั่งหน้าจอรับผิดชอบ คือ บอกเหตุผลให้ผู้ใช้ก่อนยิง request
 * และตัวนับต้องเดินตาม DOM จริงหลังผู้ใช้ทยอยปิดงานย่อย โดยไม่ต้องโหลดหน้าใหม่
 */

function subtaskRow(parentId, id, status) {
    return `<li class="board-task-detail" data-task-detail data-board-task data-board-subtask="1"
        data-task-id="${id}" data-work-order-id="${parentId}" data-status="${status}"></li>`;
}

function workspaceMarkup(parentId, statuses) {
    return `<!doctype html><html><body>
        <div class="project-board" data-project-board>
            <article class="board-reference-row" data-board-task data-task-id="${parentId}" data-status="2">
                <div class="board-task-details" data-task-details data-work-order-id="${parentId}">
                    <button type="button" data-task-details-toggle>
                        <small class="board-task-details__progress is-pending" data-task-details-progress>
                            งานย่อย <b data-task-details-count>0</b>/<span data-task-details-total>0</span>
                        </small>
                    </button>
                </div>
                <div class="board-task-details__panel" id="task-details-${parentId}" data-task-details-panel hidden>
                    <ol data-task-details-list>
                        ${statuses.map((status, index) => subtaskRow(parentId, 900 + index, status)).join('')}
                    </ol>
                </div>
            </article>
        </div>
        <div class="mytasks-kanban-view">
            <button type="button" class="mytasks-kanban__subtasks is-pending" data-kanban-subtasks="${parentId}">
                <b data-kanban-subtask-done>0</b>/<span data-kanban-subtask-total>0</span>
            </button>
        </div>
    </body></html>`;
}

test('ข้อความของด่านบล็อกเฉพาะการปิดงานและการส่งตรวจ', () => {
    assert.match(subtaskGateMessage(4, 3), /เหลืออีก 3 งาน/);
    assert.match(subtaskGateMessage(4, 1), /ปิดงานนี้ได้/);
    assert.match(subtaskGateMessage(3, 2), /ส่งตรวจได้/);

    // เคลียร์ครบแล้วต้องผ่าน ไม่ว่าจะไปสถานะไหน
    assert.equal(subtaskGateMessage(4, 0), null);
    assert.equal(subtaskGateMessage(3, 0), null);

    // พักงาน กลับมาทำ และเปิดงานอีกครั้ง ไม่เกี่ยวกับงานย่อย จึงต้องไม่ถูกบล็อก
    assert.equal(subtaskGateMessage(2, 5), null);
    assert.equal(subtaskGateMessage(5, 5), null);
});

test('นับงานย่อยจาก DOM โดยงานย่อยของงานอื่นต้องไม่ถูกนับปนมา', () => {
    const dom = mountDom(workspaceMarkup(41, [4, 2, 5]));

    try {
        // เคลียร์แล้วคือสถานะ 4 เท่านั้น — กำลังทำ (2) และพักงาน (5) ยังนับว่าค้าง
        assert.deepEqual(subtaskCounts(41), {total: 3, done: 1, open: 2});
        assert.equal(openSubtaskCount(41), 2);

        // งานที่หน้านี้ไม่ได้ render งานย่อยไว้เลยต้องคืน null ไม่ใช่ 0
        // ผู้เรียกจะได้แยกออกจาก "มีงานย่อยแต่เสร็จครบแล้ว" และตกไปใช้ค่าจาก server แทน
        assert.equal(openSubtaskCount(99), null);
    } finally {
        dom.cleanup();
    }
});

test('ค่าจาก DOM มาก่อนค่าที่ server ส่งมา แต่ตกกลับไปใช้ของ server เมื่อไม่มีงานย่อยในหน้า', () => {
    const dom = mountDom(workspaceMarkup(41, [4, 2]));

    try {
        // server ส่งมาว่า 0 แต่ DOM บอกว่ายังเหลือ 1 — DOM คือความจริงล่าสุด
        assert.equal(openSubtasksFor({task_id: 41, open_child_count: 0}), 1);
        assert.equal(openSubtasksFor({task_id: 99, open_child_count: 4}), 4);
        assert.equal(openSubtasksFor({open_child_count: 2}), 2);
        assert.equal(openSubtasksFor({}), 0);
    } finally {
        dom.cleanup();
    }
});

test('syncSubtaskGate อัปเดตตัวนับทั้งบอร์ดและชิปบนการ์ด พร้อมค่าใน management', () => {
    const dom = mountDom(workspaceMarkup(41, [4, 2]));

    try {
        const management = {41: {transitions: {child_count: 0, open_child_count: 0}}};
        syncSubtaskGate(41, management);

        const progress = document.querySelector('[data-task-details-progress]');
        assert.equal(document.querySelector('[data-task-details-count]').textContent, '1');
        assert.equal(document.querySelector('[data-task-details-total]').textContent, '2');
        assert.ok(progress.classList.contains('is-pending'));
        assert.ok(!progress.classList.contains('is-complete'));

        const chip = document.querySelector('[data-kanban-subtasks="41"]');
        assert.equal(chip.querySelector('[data-kanban-subtask-done]').textContent, '1');
        assert.equal(chip.querySelector('[data-kanban-subtask-total]').textContent, '2');
        assert.match(chip.getAttribute('aria-label'), /1 จาก 2/);

        assert.equal(management[41].transitions.child_count, 2);
        assert.equal(management[41].transitions.open_child_count, 1);

        // ปิดงานย่อยใบสุดท้ายแล้วตัวนับต้องพลิกเป็นเขียวทันที โดยไม่ต้องโหลดหน้าใหม่
        document.querySelector('[data-task-id="901"]').dataset.status = '4';
        syncSubtaskGate(41, management);

        assert.equal(document.querySelector('[data-task-details-count]').textContent, '2');
        assert.ok(progress.classList.contains('is-complete'));
        assert.ok(chip.classList.contains('is-complete'));
        assert.equal(management[41].transitions.open_child_count, 0);
    } finally {
        dom.cleanup();
    }
});

test('confirmTaskTransition เด้ง SweetAlert และไม่ยอมให้ปิดงานที่งานย่อยยังค้าง', async () => {
    const dom = mountDom(workspaceMarkup(41, [4, 2]));

    try {
        const dialogs = [];
        window.Swal = {
            fire: (options) => {
                dialogs.push(options);

                return Promise.resolve({isConfirmed: true});
            },
        };

        const capabilities = {task_id: 41, can_self_close: true, allowed_statuses: [2, 4, 5]};
        const blocked = await confirmTaskTransition(2, 4, capabilities);

        // คืน null = ทุกมุมมองยกเลิกโดยไม่ยิง request ตาม path เดิมของมัน
        assert.equal(blocked, null);
        assert.equal(dialogs.length, 1);
        assert.equal(dialogs[0].title, 'ยังเคลียร์งานย่อยไม่ครบ');
        // ต้องมี container นี้ ไม่งั้นกล่องจะไปอยู่หลังโมดัลรายละเอียดงานจนผู้ใช้มองไม่เห็น
        assert.equal(dialogs[0].customClass.container, 'task-transition-dialog');

        // ปิดงานย่อยครบแล้วต้องได้กล่องยืนยันปิดงานตามปกติ ไม่ใช่กล่องบล็อก
        document.querySelector('[data-task-id="901"]').dataset.status = '4';
        const allowed = await confirmTaskTransition(2, 4, capabilities);

        assert.deepEqual(allowed, {job_status: 4});
        assert.equal(dialogs.length, 2);
        assert.equal(dialogs[1].title, 'ยืนยันปิดงานนี้หรือไม่?');
    } finally {
        delete window.Swal;
        dom.cleanup();
    }
});

test('งานที่ไม่มีงานย่อยเลยยังปิดได้ทันทีเหมือนเดิม', async () => {
    const dom = mountDom(workspaceMarkup(41, []));

    try {
        const dialogs = [];
        window.Swal = {
            fire: (options) => {
                dialogs.push(options);

                return Promise.resolve({isConfirmed: true});
            },
        };

        const payload = await confirmTaskTransition(2, 4, {
            task_id: 41,
            can_self_close: true,
            allowed_statuses: [2, 4, 5],
        });

        assert.deepEqual(payload, {job_status: 4});
        assert.equal(dialogs[0].title, 'ยืนยันปิดงานนี้หรือไม่?');
    } finally {
        delete window.Swal;
        dom.cleanup();
    }
});
