import {canComposeComment, commentDeepLink, prependComment, shouldMarkCommentsRead, shouldSubmitOnEnter, unreadCountAfterRead, withoutTaskDeepLink} from './task-comments-model.js';
import {modalStack} from '../../components/modal-stack.js';
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
    /**
     * รูปในฟองแชท
     *
     * url มาจาก TaskCommentPresenter ซึ่งสร้างจาก route ที่ตรวจสิทธิ์แล้ว
     * ไม่ใช่ path ในดิสก์ แต่ยัง escape ทุกค่าเพราะชื่อไฟล์มาจากผู้ใช้
     */
    const commentImages = (item) => {
        const images = Array.isArray(item.images) ? item.images : [];
        if (!images.length) return '';

        return `<div class="task-timeline-entry__images" data-comment-images>${images.map((image) => `<button type="button" class="task-timeline-entry__image" data-open-comment-image="${escapeHtml(image.url)}" data-image-name="${escapeHtml(image.name)}" title="ดูรูป ${escapeHtml(image.name)}"><img src="${escapeHtml(image.url)}" alt="${escapeHtml(image.name)}" loading="lazy"></button>`).join('')}</div>`;
    };

    // ฟองที่มีแต่รูปไม่ต้องมีย่อหน้าข้อความว่างมาดันความสูง
    const entry = (item) => `<article class="task-timeline-entry${item.is_comment === true && item.is_mine ? ' is-mine' : ''}" data-comment-id="${escapeHtml(item.id)}"><span class="task-timeline-entry__avatar">${item.avatar_url ? `<img src="${escapeHtml(item.avatar_url)}" alt="">` : escapeHtml(Array.from(item.author || '?')[0] || '?')}</span><div class="task-timeline-entry__content"><strong>${escapeHtml(item.author)}</strong><div class="task-timeline-entry__bubble">${String(item.note ?? '').trim() ? `<p>${escapeHtml(item.note)}</p>` : ''}${commentImages(item)}</div><small>${escapeHtml(item.at)}</small>${readReceipts(item)}</div></article>`;
    const compose = panel.querySelector('.task-timeline__compose');
    const lockedNotice = panel.querySelector('[data-comment-locked]');

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
        const taskManagement = management[String(taskId)];
        const canCompose = canComposeComment(taskManagement);
        if (compose) compose.hidden = tab !== 'updates' || !canCompose;
        if (lockedNotice) {
            lockedNotice.hidden = tab !== 'updates'
                || canCompose
                || taskManagement?.transitions?.is_final !== true;
        }
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
    /*
     * รูปที่เลือกไว้แต่ยังไม่ได้ส่ง
     *
     * เก็บเป็นอาเรย์ของตัวเอง ไม่อ่านจาก input.files ตรง ๆ เพราะ FileList แก้ไขไม่ได้
     * ผู้ใช้จึงเอารูปทีละใบออกจากที่เลือกไว้ไม่ได้เลยถ้าพึ่ง input อย่างเดียว
     */
    let pendingImages = [];
    const MAX_IMAGES = 4;

    /*
     * ใช้ URL ของหน้าต่างที่เอกสารนี้อยู่ ไม่ใช่ global
     *
     * object URL ผูกกับ document ที่สร้างมัน การหยิบจาก view เดียวกันจึงถูกต้องกว่า
     * และทำให้โมดูลนี้ทำงานได้ในสภาพแวดล้อมที่ global URL เป็นคนละตัวกับของหน้าต่าง
     */
    const view = () => panel.ownerDocument?.defaultView ?? globalThis;

    const imageInput = () => panel.querySelector('[data-comment-image-input]');
    const previewBox = () => panel.querySelector('[data-comment-image-preview]');

    const renderPreviews = () => {
        const box = previewBox();
        if (!box) return;

        // ปล่อย object URL ของรอบก่อนก่อนสร้างชุดใหม่ ไม่งั้นหน่วยความจำรั่วทุกครั้งที่เลือกรูป
        box.querySelectorAll('img[src^="blob:"]').forEach((img) => view().URL.revokeObjectURL(img.src));
        box.innerHTML = '';
        box.hidden = pendingImages.length === 0;

        pendingImages.forEach((file, index) => {
            const item = document.createElement('span');
            item.className = 'task-timeline__preview';

            const image = document.createElement('img');
            image.src = view().URL.createObjectURL(file);
            image.alt = file.name;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'task-timeline__preview-remove';
            remove.dataset.removeCommentImage = String(index);
            remove.setAttribute('aria-label', `เอารูป ${file.name} ออก`);
            remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

            item.append(image, remove);
            box.append(item);
        });
    };

    const clearImages = () => {
        pendingImages = [];
        const input = imageInput();
        if (input) input.value = '';
        renderPreviews();
    };

    /*
     * รูปเข้ามาได้สองทาง คือเลือกจากไอคอนไฟล์ และวางด้วย Ctrl + V
     * ทั้งสองทางต้องผ่านเพดานจำนวนและการแจ้งเตือนชุดเดียวกัน จึงรวมไว้ที่ฟังก์ชันนี้ที่เดียว
     */
    const addImages = (incoming) => {
        if (!incoming.length) return false;

        const room = MAX_IMAGES - pendingImages.length;

        if (incoming.length > room) {
            window.Swal?.fire({icon: 'info', title: `แนบได้สูงสุด ${MAX_IMAGES} รูปต่อหนึ่งข้อความ`});
        }

        pendingImages = [...pendingImages, ...incoming.slice(0, Math.max(0, room))];
        renderPreviews();

        return true;
    };

    panel.addEventListener('change', (event) => {
        if (!event.target.matches('[data-comment-image-input]')) return;

        addImages([...event.target.files]);
        // ล้างค่า input เสมอ เพื่อให้เลือกไฟล์เดิมซ้ำแล้วยัง fire change อีกครั้ง
        event.target.value = '';
    });

    /*
     * วางรูปจากคลิปบอร์ดลงในช่องพิมพ์ได้เลย (Ctrl + V) เช่นภาพหน้าจอที่เพิ่งกดมา
     *
     * รับเฉพาะชนิดที่ฟองแชทเรนเดอร์ได้จริงและ TaskCommentController ยอมรับ
     * ส่วนการวางข้อความต้องทำงานตามปกติ จึงกัน default เฉพาะตอนที่มีรูปติดมาด้วยเท่านั้น
     */
    const PASTEABLE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    panel.addEventListener('paste', (event) => {
        if (!event.target.matches('[data-task-update-note]')) return;

        const files = [...(event.clipboardData?.files || [])]
            .filter((file) => PASTEABLE_IMAGE_TYPES.includes(file.type));

        if (addImages(files)) event.preventDefault();
    });

    panel.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-comment-image]');
        if (!remove) return;

        pendingImages.splice(Number(remove.dataset.removeCommentImage), 1);
        renderPreviews();
    });

    /*
     * ดูรูปในคอมเมนต์เป็นชั้นทับ ไม่ใช่การพาออกไปเปิด URL ของไฟล์ในแท็บใหม่
     *
     * ใช้ modalStack ตัวเดียวกับกล่องอื่นใน Workspace เพื่อให้การนับชั้น การล็อก body
     * ปุ่ม Escape และการคืนโฟกัสกลับไปที่รูปที่กด เป็นของเจ้าของเดียวกันทั้งหน้า
     */
    const imageModal = () => panel.ownerDocument.querySelector('[data-comment-image-modal]');
    const layers = () => modalStack(panel.ownerDocument);

    const closeImageModal = () => {
        const modal = imageModal();
        if (!modal) return;

        layers().close(modal);
        // ปล่อยรูปทิ้งเมื่อปิด ไม่ให้รูปเดิมค้างอยู่ตอนเปิดรูปใบถัดไป
        modal.querySelector('[data-comment-image-view]')?.setAttribute('src', '');
    };

    panel.ownerDocument.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-open-comment-image]');
        const modal = imageModal();
        if (!modal) return;

        if (trigger) {
            event.preventDefault();
            const url = trigger.dataset.openCommentImage;
            const name = trigger.dataset.imageName || 'รูปในคอมเมนต์';
            const view = modal.querySelector('[data-comment-image-view]');
            if (view) {
                view.src = url;
                view.alt = name;
            }
            modal.querySelector('[data-comment-image-name]').textContent = name;
            modal.querySelector('[data-comment-image-source]')?.setAttribute('href', url);
            layers().open(modal, trigger);

            return;
        }

        // คลิกพื้นหลังของกล่อง (ไม่ใช่ตัวการ์ด) ถือเป็นการปิด เหมือนกล่องอื่นในหน้านี้
        if ((event.target === modal && layers().isTop(modal)) || event.target.closest('[data-close-comment-image]')) {
            closeImageModal();
        }
    });

    imageModal()?.addEventListener('modalstack:dismiss', closeImageModal);

    const sendUpdate = async () => {
        const input = panel.querySelector('[data-task-update-note]');
        const button = panel.querySelector('[data-submit-task-update]');
        if (!input || !button) return;

        const message = input.value.trim();
        const url = management[String(taskId)]?.comment_url;
        // pending มาจากปุ่มที่ถูก disable ระหว่างรอ ทำให้กดรัว ๆ ไม่เกิดข้อความซ้ำ
        if (!shouldSendUpdate({taskId, url, message, pending: button.disabled, imageCount: pendingImages.length})) return;
        button.disabled = true;
        try {
            // ต้องเป็น FormData เมื่อมีไฟล์ และห้ามตั้ง Content-Type เอง
            // เบราว์เซอร์เป็นผู้ใส่ boundary ของ multipart ให้ ถ้าเขียนทับจะพังทั้งคำขอ
            const body = new FormData();
            body.append('message', message);
            pendingImages.forEach((file) => body.append('images[]', file));

            const response = await fetch(url, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''},
                body,
            });
            if (!response.ok) throw new Error();
            const payload = await response.json();
            prependComment(timeline, taskId, payload.comment);
            input.value = '';
            clearImages();
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

    document.addEventListener('mytasks:access-changed', (event) => {
        const id = String(event.detail?.id || '');
        const meta = management[id];
        if (!id || !meta) return;

        if (event.detail.transitions) meta.transitions = event.detail.transitions;
        if (event.detail.interactions) {
            meta.can_comment = event.detail.interactions.can_comment === true;
            meta.comment_url = event.detail.interactions.comment_url || null;
        }
        if (String(taskId) === id) render();
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
