import {alertError, confirmDanger, formatCount, htmlToElements, request, toast} from './client.js';

/*
 * ความคิดเห็นและการตอบกลับของโพสต์
 *
 * - ความคิดเห็นของโพสต์ถูกโหลดครั้งแรกเมื่อผู้ใช้เปิดส่วนความคิดเห็นของโพสต์นั้นเท่านั้น
 * - "ดูความคิดเห็นก่อนหน้า" โหลดหน้าที่เก่ากว่ามาต่อด้านบน
 * - ส่ง/แก้ไข/ลบ แล้วแทนที่เฉพาะส่วนนั้นด้วย HTML ที่ server เรนเดอร์ (partial เดียวกับตอนโหลด)
 * - ฟอร์มตอบกลับมีได้ครั้งละหนึ่งฟอร์มต่อโพสต์ คัดลอกจาก <template data-social-reply-template>
 *
 * listener ผูกครั้งเดียวที่ราก [data-social-page] (event delegation)
 */

export function setCommentCount(card, count) {
    const total = Math.max(0, Number(count) || 0);
    card.querySelectorAll('[data-social-comment-count]').forEach((node) => {
        node.textContent = formatCount(total);
    });
    const countButton = card.querySelector('.social-post__comment-count');
    if (countButton) countButton.hidden = total === 0;
    card.dataset.commentsCount = String(total);
}

/** Enter ส่ง · Shift+Enter ขึ้นบรรทัดใหม่ · ระหว่างพิมพ์ด้วย IME (isComposing) ไม่ส่ง */
export function shouldSubmitOnKey(event) {
    return event.key === 'Enter' && ! event.shiftKey && ! event.isComposing && event.keyCode !== 229;
}

export function initComments(page, {swal, fetchImpl} = {}) {
    const doc = page.ownerDocument;
    const replyTemplate = page.querySelector('[data-social-reply-template]');
    const editTemplate = page.querySelector('[data-social-edit-template]');
    let uid = 0;

    const parts = (card) => ({
        section: card.querySelector('[data-social-comments]'),
        list: card.querySelector('[data-social-comment-list]'),
        older: card.querySelector('[data-social-comments-older]'),
        status: card.querySelector('[data-social-comments-status]'),
        empty: card.querySelector('[data-social-comments-empty]'),
    });

    const syncEmpty = (card) => {
        const {list, empty} = parts(card);
        empty.hidden = card.dataset.commentsLoaded !== 'true' || Boolean(list.querySelector('[data-social-comment]'));
    };

    const showStatus = (card, message, {retry = false} = {}) => {
        const {status} = parts(card);
        status.replaceChildren();
        status.hidden = ! message;
        if (! message) return;

        status.append(doc.createTextNode(message));
        if (retry) {
            const button = doc.createElement('button');
            button.type = 'button';
            button.className = 'social-comment__link';
            button.dataset.socialCommentsRetry = '';
            button.textContent = 'ลองอีกครั้ง';
            status.append(' ', button);
        }
    };

    const load = async (card, {older = false} = {}) => {
        if (card.dataset.commentsLoading === 'true') return;
        const {list, older: olderButton} = parts(card);
        const url = new URL(card.dataset.commentsUrl, doc.location?.href);
        if (older && olderButton.dataset.before) url.searchParams.set('before', olderButton.dataset.before);

        card.dataset.commentsLoading = 'true';
        olderButton.disabled = true;
        showStatus(card, 'กำลังโหลดความคิดเห็น...');

        try {
            const payload = await request(url.toString(), {fetchImpl, doc});
            const items = htmlToElements(doc, payload.html).filter((item) => ! list.querySelector(`#${item.id}`));

            if (older) list.prepend(...items);
            else list.replaceChildren(...items);

            olderButton.dataset.before = payload.next_cursor ? String(payload.next_cursor) : '';
            olderButton.hidden = ! payload.next_cursor;
            card.dataset.commentsLoaded = 'true';
            setCommentCount(card, payload.comments_count);
            showStatus(card, null);
        } catch (error) {
            showStatus(card, error.message || 'โหลดความคิดเห็นไม่สำเร็จ', {retry: true});
        } finally {
            delete card.dataset.commentsLoading;
            olderButton.disabled = false;
            syncEmpty(card);
        }
    };

    const toggleSection = (card, {focusInput = false} = {}) => {
        const {section} = parts(card);
        const opening = section.hidden || focusInput;

        section.hidden = ! opening;
        card.querySelectorAll('[data-social-comments-toggle]').forEach((toggle) => toggle.setAttribute('aria-expanded', opening ? 'true' : 'false'));

        if (! opening) return;

        const hasComments = Number(card.dataset.commentsCount ?? card.querySelector('[data-social-comment-count]')?.textContent.replace(/[^\d]/g, '') ?? 0) > 0;
        if (card.dataset.commentsLoaded !== 'true') {
            if (hasComments) load(card);
            else {
                card.dataset.commentsLoaded = 'true';
                syncEmpty(card);
            }
        }

        if (focusInput) section.querySelector('[data-social-comment-form] [data-social-comment-input]')?.focus();
    };

    // box-sizing: border-box — ต้องบวกความหนาเส้นขอบ ไม่งั้นเนื้อหาล้น 2px แล้วแถบเลื่อนโผล่ที่ขอบขวา
    const autoGrow = (field) => {
        field.style.height = 'auto';
        field.style.height = `${field.scrollHeight + field.offsetHeight - field.clientHeight}px`;
    };

    const closeReplyForms = (card) => card.querySelectorAll('[data-social-reply-form]').forEach((form) => form.remove());

    const openReplyForm = (comment) => {
        const card = comment.closest('[data-social-post]');
        const root = card.querySelector(`[data-social-comment][data-comment-id="${comment.dataset.rootId}"]`);
        const slot = root?.querySelector('[data-social-reply-slot]');
        if (! slot || ! replyTemplate) return;

        closeReplyForms(card);
        const form = replyTemplate.content.firstElementChild.cloneNode(true);
        const field = form.querySelector('[data-social-comment-input]');
        const label = form.querySelector('[data-social-reply-label]');
        uid += 1;
        field.id = `social-reply-input-${uid}`;
        label.htmlFor = field.id;
        form.action = card.querySelector('[data-social-comment-form]').action;
        form.dataset.parentId = comment.dataset.commentId;
        form.querySelector('[data-social-reply-to]').textContent = comment.dataset.author || '';
        slot.append(form);
        field.focus();
    };

    const openEditForm = (button) => {
        const comment = button.closest('[data-social-comment]');
        const bubble = comment?.querySelector(':scope > .social-comment__main > .social-comment__bubble');
        const meta = comment?.querySelector(':scope > .social-comment__main > .social-comment__meta');
        if (! bubble || ! editTemplate || comment.querySelector(':scope > .social-comment__main > [data-social-comment-edit-form]')) return;

        const form = editTemplate.content.firstElementChild.cloneNode(true);
        const field = form.querySelector('[data-social-comment-input]');
        uid += 1;
        field.id = `social-edit-input-${uid}`;
        form.querySelector('[data-social-edit-label]').htmlFor = field.id;
        form.action = button.dataset.updateUrl;
        field.value = button.dataset.body || '';
        bubble.hidden = true;
        meta.hidden = true;
        bubble.after(form);
        autoGrow(field);
        field.focus();
        field.setSelectionRange?.(field.value.length, field.value.length);
    };

    const closeEditForm = (form, {restoreFocus = true} = {}) => {
        const main = form.parentElement;
        form.remove();
        main.querySelector(':scope > .social-comment__bubble').hidden = false;
        main.querySelector(':scope > .social-comment__meta').hidden = false;
        if (restoreFocus) main.querySelector(':scope > .social-comment__meta [data-social-comment-edit]')?.focus();
    };

    const submitForm = async (form) => {
        if (form.dataset.submitting === 'true') return;
        const field = form.querySelector('[data-social-comment-input]');
        const text = field.value.trim();
        const card = form.closest('[data-social-post]');

        if (text === '') {
            field.focus();
            return;
        }

        const data = new doc.defaultView.FormData();
        data.set('body', field.value);
        const isEdit = form.matches('[data-social-comment-edit-form]');
        if (form.dataset.parentId) data.set('parent_id', form.dataset.parentId);

        form.dataset.submitting = 'true';
        form.querySelectorAll('button, textarea').forEach((control) => { control.disabled = true; });

        /*
         * คืนโฟกัสหลังได้คำตอบเฉพาะเมื่อผู้ใช้ยังไม่ได้ย้ายไปทำอย่างอื่น — ระหว่างส่ง ช่องพิมพ์ถูก disabled
         * โฟกัสจึงตกไปที่ <body> ถ้าระหว่างนั้นผู้ใช้เปิดฟอร์มตอบกลับหรือกดที่อื่น ต้องไม่ดึงโฟกัสกลับมา
         */
        const focusIsFree = () => doc.activeElement === doc.body || doc.activeElement === null || form.contains(doc.activeElement);

        try {
            const payload = await request(form.action, {method: isEdit ? 'PATCH' : 'POST', body: data, fetchImpl, doc});
            const [element] = htmlToElements(doc, payload.html);

            if (isEdit) {
                const keepFocus = focusIsFree();
                form.closest('[data-social-comment]').replaceWith(element);
                if (keepFocus) element.querySelector('[data-social-comment-edit]')?.focus();
            } else if (payload.parent_id) {
                const root = card.querySelector(`[data-social-comment][data-comment-id="${payload.parent_id}"]`);
                root?.querySelector('[data-social-reply-list]')?.append(element);
                const keepFocus = focusIsFree();
                form.remove();
                if (keepFocus) root?.querySelector(':scope > .social-comment__main > .social-comment__meta [data-social-comment-reply]')?.focus();
            } else {
                parts(card).list.append(element);
                field.value = '';
                autoGrow(field);
            }

            if (payload.comments_count !== undefined) setCommentCount(card, payload.comments_count);
            card.dataset.commentsLoaded = 'true';
            syncEmpty(card);
        } catch (error) {
            if (error.status === 404) {
                alertError(swal, 'โพสต์หรือความคิดเห็นนี้ถูกลบไปแล้ว');
            } else {
                toast(swal, 'error', error.message);
            }
        } finally {
            if (form.isConnected) {
                delete form.dataset.submitting;
                form.querySelectorAll('button, textarea').forEach((control) => { control.disabled = false; });
                if (! isEdit && form.matches('[data-social-comment-form]') && focusIsFree()) field.focus();
            }
        }
    };

    const deleteComment = async (button) => {
        const comment = button.closest('[data-social-comment]');
        const card = comment?.closest('[data-social-post]');
        if (! comment || comment.dataset.deleting === 'true') return;

        const confirmed = await confirmDanger(swal, {
            title: 'ลบความคิดเห็นนี้?',
            text: button.dataset.hasReplies === 'true' ? 'การตอบกลับทั้งหมดของความคิดเห็นนี้จะถูกลบด้วย' : 'ความคิดเห็นจะไม่แสดงในโพสต์อีก',
            confirmButtonText: 'ลบความคิดเห็น',
        });
        if (! confirmed) return;

        comment.dataset.deleting = 'true';
        try {
            const payload = await request(button.dataset.destroyUrl, {method: 'DELETE', fetchImpl, doc});
            comment.remove();
            setCommentCount(card, payload.comments_count);
            syncEmpty(card);
            toast(swal, 'success', payload.message || 'ลบความคิดเห็นแล้ว');
        } catch (error) {
            delete comment.dataset.deleting;
            alertError(swal, error.message);
        }
    };

    page.addEventListener('click', (event) => {
        const target = event.target;
        const card = target.closest?.('[data-social-post]');
        if (! card) return;

        const toggle = target.closest('[data-social-comments-toggle]');
        if (toggle) {
            toggleSection(card, {focusInput: toggle.hasAttribute('data-focus-input')});
            return;
        }

        if (target.closest('[data-social-comments-older]')) {
            load(card, {older: true});
            return;
        }

        if (target.closest('[data-social-comments-retry]')) {
            load(card);
            return;
        }

        const reply = target.closest('[data-social-comment-reply]');
        if (reply) {
            openReplyForm(reply.closest('[data-social-comment]'));
            return;
        }

        const cancelReply = target.closest('[data-social-reply-cancel]');
        if (cancelReply) {
            const form = cancelReply.closest('[data-social-reply-form]');
            const root = form.closest('[data-social-comment]');
            form.remove();
            root?.querySelector(':scope > .social-comment__main > .social-comment__meta [data-social-comment-reply]')?.focus();
            return;
        }

        const edit = target.closest('[data-social-comment-edit]');
        if (edit) {
            openEditForm(edit);
            return;
        }

        const cancelEdit = target.closest('[data-social-edit-cancel]');
        if (cancelEdit) {
            closeEditForm(cancelEdit.closest('[data-social-comment-edit-form]'));
            return;
        }

        const remove = target.closest('[data-social-comment-delete]');
        if (remove) deleteComment(remove);
    });

    page.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-social-comment-form], [data-social-reply-form], [data-social-comment-edit-form]');
        if (! form) return;

        event.preventDefault();
        submitForm(form);
    });

    page.addEventListener('keydown', (event) => {
        const field = event.target.closest?.('[data-social-comment-input]');
        if (! field) return;

        const editForm = field.closest('[data-social-comment-edit-form]');
        if (event.key === 'Escape' && editForm) {
            event.preventDefault();
            closeEditForm(editForm);
            return;
        }

        if (shouldSubmitOnKey(event)) {
            event.preventDefault();
            field.form?.requestSubmit?.();
        }
    });

    page.addEventListener('input', (event) => {
        const field = event.target.closest?.('[data-social-comment-input]');
        if (field) autoGrow(field);
    });

    return {load, toggleSection, submitForm};
}
