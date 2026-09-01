import {canComposeComment, commentDeepLink, prependComment, shouldMarkCommentsRead, shouldSubmitOnEnter, unreadCountAfterRead, withoutTaskDeepLink} from './task-comments-model.js';
import {shouldSendUpdate} from './task-workspace-model.js';

(() => {
    const root = document.querySelector('[data-workspace]');
    const modal = document.querySelector('[data-task-modal]');
    const panel = modal?.querySelector('[data-task-timeline]');
    const timelineNode = document.querySelector('[data-timeline-data]');
    const managementNode = document.querySelector('[data-task-management-data]');
    if (!root || !modal || !panel || !timelineNode || !managementNode) return;

    const timeline = JSON.parse(timelineNode.textContent || '{}');
    const management = JSON.parse(managementNode.textContent || '{}');
    let taskId = null;
    let tab = 'updates';

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[character]));
    const entry = (item) => `<article class="task-timeline-entry"><span class="task-timeline-entry__avatar">${item.avatar_url ? `<img src="${escapeHtml(item.avatar_url)}" alt="">` : escapeHtml(Array.from(item.author || '?')[0] || '?')}</span><div><strong>${escapeHtml(item.author)}</strong><p>${escapeHtml(item.note)}</p><small>${escapeHtml(item.at)}</small></div></article>`;
    const compose = panel.querySelector('.task-timeline__compose');

    const emptyLabel = () => tab === 'activity' ? 'ยังไม่มีรายการกิจกรรม' : 'ยังไม่มีรายการอัปเดต';

    const render = () => {
        const entries = timeline[String(taskId)]?.[tab] || [];
        panel.hidden = false;
        panel.querySelectorAll('[data-timeline-tab]').forEach((button) => {
            const active = button.dataset.timelineTab === tab;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', String(active));
        });
        panel.querySelector('[data-timeline-items]').innerHTML = entries.map(entry).join('') || `<p class="task-timeline-empty">${emptyLabel()}</p>`;
        if (compose) compose.hidden = tab !== 'updates' || !canComposeComment(management[String(taskId)]);
    };

    const clearBadges = () => {
        document.querySelectorAll(`[data-unread-comments="${CSS.escape(String(taskId))}"]`).forEach((badge) => {
            // คอลัมน์คอมเมนต์บนบอร์ดเป็นช่องหนึ่งของกริด ถ้าลบทิ้งคอลัมน์ที่เหลือจะเลื่อนไปทั้งแถว
            // จึงล้างเฉพาะสถานะ unread และคงจำนวนคอมเมนต์รวมไว้เท่าเดิม
            if (badge.hasAttribute('data-unread-persistent')) {
                badge.classList.remove('has-unread');
                if (badge.dataset.commentLabel) badge.setAttribute('aria-label', badge.dataset.commentLabel);
                return;
            }
            badge.remove();
        });
        if (management[String(taskId)]) management[String(taskId)].unread_comments = unreadCountAfterRead();
    };

    const updateNotificationCount = (count) => {
        const value = Number(count || 0);
        document.querySelectorAll('[data-notification-count]').forEach((badge) => {
            badge.textContent = value > 99 ? '99+' : String(value);
            badge.hidden = value === 0;
        });
        document.querySelectorAll('[data-notification-summary]').forEach((summary) => {
            summary.textContent = `${value} รายการ`;
            summary.classList.toggle('amber', value > 0);
            summary.classList.toggle('gray', value === 0);
        });
    };

    const markRead = async () => {
        const url = management[String(taskId)]?.read_comments_url;
        if (!url) return;
        const response = await fetch(url, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''},
        });
        if (response.ok) {
            clearBadges();
            const payload = await response.json();
            updateNotificationCount(payload.unread_count);
        }
    };

    const selectUpdates = (source) => {
        tab = 'updates';
        render();
        if (shouldMarkCommentsRead(source, tab)) markRead();
    };

    const triggerTaskId = (trigger) => trigger?.dataset.taskId
        || trigger?.closest('[data-row]')?.dataset.id
        || trigger?.closest('[data-board-task]')?.dataset.taskId;

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-open-task-modal]');
        const openedTaskId = triggerTaskId(trigger);
        if (openedTaskId) {
            taskId = String(openedTaskId);
            // ปุ่มคอลัมน์คอมเมนต์ประกาศแท็บที่ต้องการไว้ที่ตัวมันเอง เปิดจากชื่องานยังเป็นการเปิดงานเฉย ๆ
            selectUpdates(trigger.dataset.taskTab === 'updates' ? 'comment-icon' : 'modal');
        }

        const button = event.target.closest('[data-timeline-tab]');
        if (!button || !taskId) return;
        tab = button.dataset.timelineTab;
        render();
        if (shouldMarkCommentsRead('tab', tab)) markRead();
    });

    /**
     * ปุ่มส่งและปุ่ม Enter ใช้เส้นทางเดียวกันทั้งหมด รวมถึงการกันกดซ้ำ
     * ต้องอ่าน button ใหม่ทุกครั้ง เพราะ button.disabled คือ state ที่ใช้กันการส่งซ้อน
     */
    const sendUpdate = async () => {
        const input = panel.querySelector('[data-task-update-note]');
        const button = panel.querySelector('[data-submit-task-update]');
        if (!input || !button) return;

        const message = input.value.trim();
        const url = management[String(taskId)]?.comment_url;
        // pending มาจากปุ่มที่ถูก disable ระหว่างรอ ทำให้กดรัว ๆ ไม่เกิดข้อความซ้ำ
        if (!shouldSendUpdate({taskId, url, message, pending: button.disabled})) return;
        button.disabled = true;
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''},
                body: JSON.stringify({message}),
            });
            if (!response.ok) throw new Error();
            const payload = await response.json();
            prependComment(timeline, taskId, payload.comment);
            input.value = '';
            selectUpdates('tab');
        } catch (_) {
            window.Swal?.fire({icon: 'error', title: 'ส่งความคิดเห็นไม่สำเร็จ', text: 'กรุณาลองใหม่อีกครั้ง'});
        } finally {
            button.disabled = false;
        }
    };

    panel.querySelector('[data-submit-task-update]')?.addEventListener('click', sendUpdate);

    // ผูกที่ textarea ตัวเดียว ไม่ดัก Enter ระดับ document เพื่อไม่ให้กระทบช่องกรอกอื่นในโมดัล
    panel.querySelector('[data-task-update-note]')?.addEventListener('keydown', (event) => {
        if (!shouldSubmitOnEnter(event)) return;
        // Shift+Enter ไม่เข้าเงื่อนไขนี้ จึงตกไปเป็นการขึ้นบรรทัดใหม่ตามปกติของเบราว์เซอร์
        event.preventDefault();
        sendUpdate();
    });

    const deepLink = commentDeepLink(window.location.search);
    const deepLinkedTaskId = deepLink.taskId;
    if (deepLinkedTaskId) {
        const trigger = document.querySelector(`[data-row][data-id="${CSS.escape(deepLinkedTaskId)}"] [data-open-task-modal], [data-open-task-modal][data-task-id="${CSS.escape(deepLinkedTaskId)}"]`);
        if (trigger) {
            trigger.click();
            // ล้าง query เฉพาะเมื่อ Workspace เปิดสำเร็จจริง เพื่อไม่ให้ invalid/inaccessible id
            // ไปเปลี่ยน state หรือเปิด Task อื่นโดยไม่ตั้งใจ
            if (!modal.hidden) {
                taskId = deepLinkedTaskId;
                if (shouldMarkCommentsRead('deep-link', deepLink.tab)) selectUpdates('deep-link');
                window.history.replaceState(window.history.state, '', withoutTaskDeepLink(window.location.href));
            }
        }
    }
})();
