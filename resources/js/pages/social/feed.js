import {alertError, confirmDanger, formatCount, htmlToElements, request, setBusy, toast} from './client.js';
import {readImages} from './image-viewer.js';

/*
 * ฟีดโพสต์ — โหลดเพิ่ม ถูกใจ เปิดรูป และเมนูจัดการโพสต์
 *
 * listener ทั้งหมดผูกครั้งเดียวที่ราก [data-social-page] (event delegation)
 * การ์ดที่ถูกเพิ่มหรือแทนที่ภายหลังจึงใช้งานได้ทันทีโดยไม่ต้องผูกใหม่
 * ปุ่มจัดการโพสต์มีเฉพาะเมื่อ server (SocialPostPolicy) อนุญาต และ server ตรวจซ้ำทุกคำขอ
 */

export function readPostPayload(button) {
    try {
        const payload = JSON.parse(button?.dataset.post || 'null');

        return payload && typeof payload === 'object' ? payload : null;
    } catch {
        return null;
    }
}

/** วาดสถานะถูกใจของการ์ด — ใช้ทั้งตอนกด (ล่วงหน้า) ตอนได้ผลจริง และตอนย้อนกลับเมื่อผิดพลาด */
export function applyLikeState(card, liked, count) {
    const button = card.querySelector('[data-social-like]');
    const icon = card.querySelector('[data-social-like-icon]');
    const summary = card.querySelector('[data-social-like-summary]');
    const counter = card.querySelector('[data-social-like-count]');

    button?.setAttribute('aria-pressed', liked ? 'true' : 'false');
    button?.classList.toggle('is-active', liked);
    icon?.classList.toggle('bi-hand-thumbs-up-fill', liked);
    icon?.classList.toggle('bi-hand-thumbs-up', ! liked);
    if (counter) counter.textContent = formatCount(count);
    if (summary) summary.hidden = count <= 0;
    card.dataset.likesCount = String(count);
}

function currentLikeCount(card) {
    if (card.dataset.likesCount !== undefined) return Number(card.dataset.likesCount) || 0;

    return Number(String(card.querySelector('[data-social-like-count]')?.textContent || '0').replace(/[^\d]/g, '')) || 0;
}

export function createFeed(page, {swal, fetchImpl, viewer, composer} = {}) {
    const doc = page.ownerDocument;
    const list = page.querySelector('[data-social-feed]');
    const empty = page.querySelector('[data-social-empty]');
    const moreButton = page.querySelector('[data-social-load-more]');
    const end = page.querySelector('[data-social-feed-end]');
    let loadingMore = false;

    const syncEmpty = () => {
        const hasPosts = Boolean(list.querySelector('[data-social-post]'));
        empty.hidden = hasPosts;
        if (! hasPosts) end.hidden = true;
    };

    const insertCard = (html, {replace = null, prepend = false} = {}) => {
        const [card] = htmlToElements(doc, html);
        if (! card) return null;

        if (replace?.isConnected) {
            replace.replaceWith(card);
        } else {
            const existing = list.querySelector(`#${card.id}`);
            if (existing) existing.replaceWith(card);
            else if (prepend) list.prepend(card);
            else list.append(card);
        }
        syncEmpty();

        return card;
    };

    const loadMore = async () => {
        const cursor = moreButton.dataset.nextCursor;
        if (loadingMore || ! cursor) return;

        loadingMore = true;
        setBusy(moreButton, true, 'กำลังโหลด...');

        try {
            const url = new URL(page.dataset.feedUrl, doc.location?.href);
            url.searchParams.set('before', cursor);
            const payload = await request(url.toString(), {fetchImpl, doc});

            htmlToElements(doc, payload.html).forEach((card) => {
                // โพสต์ที่มีอยู่แล้ว (เช่น เพิ่งสร้างแล้ว prepend) ไม่ถูกเพิ่มซ้ำ
                if (! list.querySelector(`#${card.id}`)) list.append(card);
            });

            moreButton.dataset.nextCursor = payload.next_cursor ? String(payload.next_cursor) : '';
            moreButton.hidden = ! payload.next_cursor;
            end.hidden = Boolean(payload.next_cursor);
            syncEmpty();
        } catch (error) {
            alertError(swal, error.message);
        } finally {
            loadingMore = false;
            setBusy(moreButton, false, '');
        }
    };

    const toggleLike = async (button) => {
        const card = button.closest('[data-social-post]');
        if (! card || card.dataset.likeBusy === 'true') return;

        const wasLiked = button.getAttribute('aria-pressed') === 'true';
        const previousCount = currentLikeCount(card);

        card.dataset.likeBusy = 'true';
        button.setAttribute('aria-busy', 'true');
        applyLikeState(card, ! wasLiked, previousCount + (wasLiked ? -1 : 1));

        try {
            const payload = await request(card.dataset.likeUrl, {method: wasLiked ? 'DELETE' : 'PUT', fetchImpl, doc});
            applyLikeState(card, Boolean(payload.liked), Number(payload.likes_count) || 0);
        } catch (error) {
            applyLikeState(card, wasLiked, previousCount);
            if (error.status === 404) card.remove();
            toast(swal, 'error', error.message);
            syncEmpty();
        } finally {
            delete card.dataset.likeBusy;
            button.setAttribute('aria-busy', 'false');
        }
    };

    const deletePost = async (button) => {
        const card = button.closest('[data-social-post]');
        if (! card || card.dataset.deleting === 'true') return;

        const moderation = button.dataset.moderation === 'true';
        const confirmed = await confirmDanger(swal, {
            title: moderation ? 'ลบโพสต์นี้ในฐานะผู้ดูแลระบบ?' : 'ลบโพสต์นี้?',
            text: 'โพสต์ ความคิดเห็น และรูปภาพจะไม่แสดงในชุมชนอีก ผู้ดูแลระบบกู้คืนได้ภายใน 30 วัน',
            confirmButtonText: 'ลบโพสต์',
        });
        if (! confirmed) return;

        card.dataset.deleting = 'true';
        card.setAttribute('aria-busy', 'true');

        try {
            const payload = await request(button.dataset.destroyUrl, {method: 'DELETE', fetchImpl, doc});
            card.remove();
            syncEmpty();
            toast(swal, 'success', payload.message || 'ลบโพสต์แล้ว');
        } catch (error) {
            delete card.dataset.deleting;
            card.setAttribute('aria-busy', 'false');
            alertError(swal, error.message);
        }
    };

    page.addEventListener('click', (event) => {
        const like = event.target.closest('[data-social-like]');
        if (like && page.contains(like)) {
            toggleLike(like);
            return;
        }

        const imageButton = event.target.closest('[data-social-image-open]');
        if (imageButton) {
            const card = imageButton.closest('[data-social-post]');
            viewer?.open(readImages(card), Number(imageButton.dataset.socialImageOpen) || 0, imageButton);
            return;
        }

        const edit = event.target.closest('[data-social-post-edit]');
        if (edit) {
            const post = readPostPayload(edit);
            if (post) {
                const card = edit.closest('[data-social-post]');
                composer?.open({mode: 'edit', post, updateUrl: edit.dataset.updateUrl, card, trigger: card?.querySelector('[data-bs-toggle="dropdown"]')});
            }
            return;
        }

        const remove = event.target.closest('[data-social-post-delete]');
        if (remove) {
            deletePost(remove);
            return;
        }

        const compose = event.target.closest('[data-social-compose]');
        if (compose) {
            composer?.open({mode: 'create', intent: compose.dataset.socialCompose, trigger: compose});
            return;
        }

        if (event.target.closest('[data-social-load-more]')) loadMore();
    });

    // รูปในการ์ด: แสดงสถานะโหลดเสร็จ/โหลดไม่สำเร็จ — event load/error ไม่ bubble จึงฟังในเฟส capture
    const markImage = (event) => {
        const img = event.target;
        if (! (img instanceof doc.defaultView.HTMLImageElement)) return;
        const item = img.closest('.social-media-grid__item');
        if (! item) return;
        item.classList.add(event.type === 'load' ? 'is-loaded' : 'is-broken');
    };
    page.addEventListener('load', markImage, true);
    page.addEventListener('error', markImage, true);
    page.querySelectorAll('.social-media-grid__item img').forEach((img) => {
        if (img.complete && img.naturalWidth > 0) img.closest('.social-media-grid__item')?.classList.add('is-loaded');
    });

    return {
        insertCard,
        loadMore,
        toggleLike,
        /** คืนการ์ดที่ถูกวาดใหม่ ให้ composer คืนโฟกัสไปที่ปุ่มของการ์ดใบใหม่ (ใบเดิมถูกแทนที่แล้ว) */
        onSaved(payload, mode, card) {
            return insertCard(payload.html, mode === 'edit' ? {replace: card} : {prepend: true});
        },
    };
}
