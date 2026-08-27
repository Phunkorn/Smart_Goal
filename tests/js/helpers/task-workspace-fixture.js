import {mountDom, peopleSelectorMarkup} from './dom.js';

/**
 * DOM จำลองของหน้า "งานของฉัน" เท่าที่ mytasks-task-modal.js ต้องใช้
 *
 * โครงสร้างต้องตรงกับ resources/views/tasks/partials/workspace-interactions.blade.php
 * และ tasks/index.blade.php ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย
 */
export function taskWorkspaceMarkup({
    context = 'user',
    taskId = 7,
    people = [],
    departments = [],
    team = {},
} = {}) {
    const teamData = {
        [String(taskId)]: {
            id: taskId,
            topic: 'งานทดสอบ',
            locked: false,
            can_manage: true,
            add_url: `/tasks/${taskId}/collaborators`,
            remove_url: `/tasks/${taskId}/collaborators/__USER__`,
            assignee: {id: 99, name: 'เจ้าของงาน', department: 'ไอที'},
            protected_ids: [99],
            collaborators: [],
            ...team,
        },
    };

    const managementData = {
        [String(taskId)]: {
            transitions: {},
            can_work: true,
            can_comment: true,
            can_manage_team: true,
            project: 'โปรเจกต์ทดสอบ',
            status: 2,
            comment_url: `/tasks/${taskId}/comments`,
            read_comments_url: `/tasks/${taskId}/comments/read`,
            unread_comments: 0,
            ...(team.management || {}),
        },
    };

    return `
<meta name="csrf-token" content="test-token">
<div class="notion-workspace my-tasks-page sg-task-theme" data-workspace data-context="${context}"
     data-details-template="/tasks/__ID__/details"
     data-status-template="/tasks/__ID__/status"
     data-priority-template="/my-tasks/__ID__/priority"
     data-due-template="/my-tasks/__ID__/due-date">
    <div data-workspace-task-source>
        <div class="notion-row" data-row data-id="${taskId}" data-topic="งานทดสอบ" data-project="โปรเจกต์ทดสอบ"
             data-status="2" data-priority="2" data-start="2026-08-01" data-due="2026-08-05" data-assignee="เจ้าของงาน">
            <button type="button" class="row-title" data-open-task-modal><strong>งานทดสอบ</strong></button>
        </div>
    </div>
    <div class="notion-toast" data-toast></div>
</div>

<script type="application/json" data-team-data>${JSON.stringify(teamData)}</script>
<script type="application/json" data-owner-data>{}</script>
<script type="application/json" data-attachment-data>{}</script>
<script type="application/json" data-timeline-data>{}</script>
<script type="application/json" data-task-management-data>${JSON.stringify(managementData)}</script>

<div class="team-modal notion-modal" data-team-modal hidden>
    <section class="team-modal-card" role="dialog" aria-modal="true" aria-labelledby="team-modal-title">
        <header>
            <strong id="team-modal-title">ทีมของงานนี้</strong><small data-team-topic></small>
            <button type="button" class="task-modal-close" data-close-team aria-label="ปิด">x</button>
        </header>
        <form class="team-manager" data-team-form>
            <div class="team-manager__body">
                <section class="team-owner-card"><div data-team-owner></div></section>
                ${peopleSelectorMarkup({
                    instanceId: 'task-collaborators',
                    inputName: 'collaborators[]',
                    people,
                    departments,
                }).replace('data-people-count', 'data-people-count data-count-template="เลือกเพิ่ม :count คน"')
                  .replace('<div class="people-selector__selected">', `<div class="people-selector__selected">
                    <section class="team-current" data-team-current>
                        <strong data-team-count>ทีมปัจจุบัน 0 คน</strong>
                        <div class="team-member-list" data-team-members></div>
                        <p data-team-empty hidden>ยังไม่มีผู้ร่วมงานในงานนี้</p>
                    </section>`)}
                <p class="team-manager__notice" data-team-notice hidden></p>
            </div>
            <footer class="team-manager__footer">
                <button type="button" class="task-secondary" data-close-team>ปิด</button>
                <button type="submit" class="notion-primary" data-team-submit disabled><span data-team-submit-label>เลือกผู้ร่วมงานก่อน</span></button>
            </footer>
        </form>
    </section>
</div>

<div class="notion-modal owner-modal" data-owner-modal hidden>
    <button type="button" data-close-owner>x</button>
    <div data-owner-avatar></div><strong data-owner-name></strong>
</div>

<div class="task-workspace-modal notion-modal sg-task-theme" data-task-modal hidden>
    <form class="task-workspace" data-task-form data-readonly="false">
        <header class="task-workspace__header">
            <h2 data-workspace-title-text></h2>
            <button type="button" class="task-workspace__rename" data-rename-task>แก้ชื่อ</button>
            <input name="job_topic" hidden>
            <a href="/my-tasks"><span data-workspace-project></span></a>
            <button type="button" class="task-workspace__close" data-close-task>x</button>
        </header>
        <section class="task-workspace__summary">
            <select name="job_status" hidden><option value="2">กำลังทำ</option><option value="5">พักงาน</option></select>
            <details class="board-status-menu modal-status-menu" data-modal-status-menu>
                <summary class="board-status-pill"><span data-modal-status-label></span></summary>
                <div><button type="button" data-modal-status-value="2">กำลังทำ</button></div>
            </details>
            <output data-static-status hidden></output>

            <select name="job_priority" hidden><option value="2">สำคัญไม่ด่วน</option></select>
            <details class="board-priority-menu modal-priority-menu" data-modal-priority-menu>
                <summary class="board-priority"><span data-modal-priority-label></span></summary>
                <div><button type="button" data-modal-priority-value="2">สำคัญไม่ด่วน</button></div>
            </details>
            <output data-static-priority hidden></output>

            <input type="date" name="job_start_at" class="task-workspace__date">
            <input type="date" name="job_due_at" class="task-workspace__date">
            <output data-workspace-assignee></output>
            <input name="assignee" type="hidden">

            <button type="button" class="task-workspace__cell-action" data-manage-team>เพิ่มผู้ร่วมงาน</button>
        </section>
        <div class="task-workspace__panels">
            <section data-task-attachments>
                <label data-task-inline-drop><input type="file" data-task-inline-file-input></label>
                <div class="task-inline-files" data-task-inline-files></div>
                <p data-attachment-status hidden></p>
            </section>
            <section class="task-timeline" data-task-timeline>
                <nav><button type="button" data-timeline-tab="updates">อัปเดต</button><button type="button" data-timeline-tab="activity">กิจกรรม</button></nav>
                <div data-timeline-items></div>
                <div class="task-timeline__compose"><textarea data-task-update-note></textarea><button type="button" data-submit-task-update>ส่ง</button></div>
            </section>
        </div>
        <footer class="task-workspace__footer">
            <button type="button" data-review-return hidden>ส่งกลับแก้ไข</button>
            <button type="button" data-review-approve hidden>อนุมัติ</button>
            <button type="button" data-reopen-task hidden>เปิดอีกครั้ง</button>
            <button type="button" class="task-secondary" data-close-task>ยกเลิก</button>
            <button type="submit" class="notion-primary" data-save-task>บันทึกการแก้ไข</button>
        </footer>
    </form>
</div>`;
}

let importCounter = 0;

/**
 * โหลด mytasks-task-modal.js ใหม่ทุกครั้ง เพราะ IIFE ในไฟล์ผูกกับ document ตอน evaluate
 * ใช้ query string กัน ESM cache
 */
export async function mountTaskWorkspace(options = {}) {
    const env = mountDom();
    env.document.body.innerHTML = taskWorkspaceMarkup(options);
    globalThis.fetch = async () => ({ok: true, json: async () => ({})});

    importCounter += 1;
    await import(`../../../resources/js/mytasks-task-modal.js?fixture=${importCounter}`);

    return {
        ...env,
        taskModal: env.document.querySelector('[data-task-modal]'),
        teamModal: env.document.querySelector('[data-team-modal]'),
        openTask: () => env.document.querySelector('[data-open-task-modal]'),
        manageTeam: () => env.document.querySelector('.task-workspace__cell-action[data-manage-team]'),
    };
}
