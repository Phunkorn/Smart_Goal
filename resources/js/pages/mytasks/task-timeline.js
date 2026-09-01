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
    const readerAvatar = (reader) => `<span class="task-timeline-reader" title="${escapeHtml(`${reader.name} อ่านแล้ว${reader.read_at ? ` · ${reader.read_at}` : ''}`)}">${reader.avatar_url ? `<img src="${escapeHtml(reader.avatar_url)}" alt="">` : escapeHtml(Array.from(reader.name || '?')[0] || '?')}</span>`;
    const readReceipts = (item) => {
        const readers = Array.isArray(item.readers) ? item.readers : [];
        if (!readers.length) return '';
        const visible = readers.slice(0, 4);
        const names = readers.map((reader) => reader.name).join(', ');
        const remainder = readers.length - visible.length;
        return `<div class="task-timeline-entry__readers" aria-label="อ่านแล้วโดย ${escapeHtml(names)}">${visible.map(readerAvatar).join('')}${remainder > 0 ? `<span class="task-timeline-reader task-timeline-reader--more">+${remainder}</span>` : ''}</div>`;
    };
    const entry = (item) => `<article class="task-timeline-entry${item.is_comment === true && item.is_mine ? ' is-mine' : ''}" data-comment-id="${escapeHtml(item.id)}"><span class="task-timeline-entry__avatar">${item.avatar_url ? `<img src="${escapeHtml(item.avatar_url)}" alt="">` : escapeHtml(Array.from(item.author || '?')[0] || '?')}</span><div class="task-timeline-entry__content"><strong>${escapeHtml(item.author)}</strong><div class="task-timeline-entry__bubble"><p>${escapeHtml(item.note)}</p></div><small>${escapeHtml(item.at)}</small>${readReceipts(item)}</div></article>`;
    const compose = panel.querySelector('.task-timeline__compose');

    const emptyLabel = () => tab === 'activity' ? 'ยังไม่มีรายการกิจกรรม' : 'ยังไม่มีรายการอัปเดต';

    const isNearBottom = (items) => items.scrollHeight - items.scrollTop - items.clientHeight <= 48;

    const render = ({scroll = 'preserve'} = {}) => {
        const entries = timeline[String(taskId)]?.[tab] || [];
        const visibleEntries = tab === 'updates' ? [...entries].reverse() : entries;
        const items = panel.querySelector('[data-timeline-items]');
        const previousScrollTop = items.scrollTop;
        panel.hidden = false;
        panel.querySelectorAll('[data-timeline-tab]').forEach((button) => {
            const active = button.dataset.timelineTab === tab;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', String(active));
        });
        items.innerHTML = visibleEntries.map(entry).join('') || `<p class="task-timeline-empty">${emptyLabel()}</p>`;
        if (tab === 'updates') {
            if (scroll === 'bottom') {
                items.scrollTop = items.scrollHeight;
            } else {
                items.scrollTop = previousScrollTop;
            }
        }
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
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''},
            });
            if (response.ok) {
                clearBadges();
                const payload = await response.json();
                updateNotificationCount(payload.unread_count);
                mergeReceipts(taskId, payload.receipts);
            }
        } catch (_) {
            // Keep the comment visible; a later tab selection or incoming event retries the read receipt.
        }
    };

    const selectUpdates = (source) => {
        tab = 'updates';
        document.body.dataset.realtimeTaskId = String(taskId);
        render({scroll: 'bottom'});
        document.dispatchEvent(new CustomEvent('smartgoal:realtime-refresh'));
        if (shouldMarkCommentsRead(source, tab)) markRead();
    };

    const mergeReceipts = (receiptTaskId, receipts) => {
        const updates = timeline[String(receiptTaskId)]?.updates;
        if (!updates || !receipts) return;
        updates.forEach((item) => {
            if (item.is_comment === false) return;
            item.readers = receipts[String(item.id)] || [];
        });
        if (String(taskId) === String(receiptTaskId) && tab === 'updates' && !modal.hidden) render();
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
        render({scroll: tab === 'updates' ? 'bottom' : 'preserve'});
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
            tab = 'updates';
            render({scroll: 'bottom'});
            markRead();
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

    document.addEventListener('smartgoal:realtime-notification', (event) => {
        const incoming = event.detail;
        if (incoming?.category !== 'comment' || ! incoming.task_id || ! incoming.comment) return;

        const incomingTaskId = String(incoming.task_id);
        const updates = timeline[incomingTaskId]?.updates || [];
        if (updates.some((item) => String(item.id) === String(incoming.comment.id))) return;
        prependComment(timeline, incomingTaskId, incoming.comment);
        const activelyViewing = taskId === incomingTaskId && tab === 'updates' && !modal.hidden;
        const items = panel.querySelector('[data-timeline-items]');
        const followIncoming = activelyViewing && isNearBottom(items);

        if (management[incomingTaskId]) {
            management[incomingTaskId].unread_comments = activelyViewing
                ? 0
                : Number(management[incomingTaskId].unread_comments || 0) + 1;
        }

        document.querySelectorAll(`[data-unread-comments="${CSS.escape(incomingTaskId)}"]`).forEach((badge) => {
            if (activelyViewing && !badge.hasAttribute('data-unread-persistent')) {
                badge.remove();
                return;
            }

            if (activelyViewing) {
                const total = badge.querySelector('strong');
                const nextTotal = Number(total?.textContent === '-' ? 0 : total?.textContent || 0) + 1;
                if (total) total.textContent = String(nextTotal);
                badge.classList.add('has-comments');
                badge.classList.remove('has-unread');
                const label = `ดูคอมเมนต์ ${nextTotal} รายการ`;
                badge.dataset.commentLabel = label;
                badge.title = label;
                badge.setAttribute('aria-label', label);
                return;
            }

            badge.classList.add('has-unread');
            if (! badge.hasAttribute('data-unread-persistent')) {
                const count = badge.querySelector('b');
                if (count) count.textContent = String(Number(count.textContent || 0) + 1);
                return;
            }

            const total = badge.querySelector('strong');
            const nextTotal = Number(total?.textContent === '-' ? 0 : total?.textContent || 0) + 1;
            if (total) total.textContent = String(nextTotal);
            badge.classList.add('has-comments');
            const label = `ดูคอมเมนต์ ${nextTotal} รายการ`;
            badge.dataset.commentLabel = label;
            badge.title = label;
            badge.setAttribute('aria-label', `${label} และมีคอมเมนต์ใหม่ที่ยังไม่ได้อ่าน`);
        });

        if (activelyViewing) {
            render({scroll: followIncoming ? 'bottom' : 'preserve'});
            markRead();
        }
    });

    document.addEventListener('smartgoal:comment-receipts', (event) => {
        const payload = event.detail;
        if (!payload?.task_id) return;
        mergeReceipts(payload.task_id, payload.receipts);
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
