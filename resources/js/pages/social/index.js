import {initComments} from './comments.js';
import {createComposer} from './composer.js';
import {createFeed} from './feed.js';
import {createImageViewer} from './image-viewer.js';

/*
 * หน้าชุมชน (/social) — จุดเริ่มต้นเดียวของหน้า
 *
 * ประกอบโมดูลย่อยเข้าด้วยกัน: ฟีด (feed.js) modal โพสต์ (composer.js) ตัวดูรูป (image-viewer.js)
 * และความคิดเห็น (comments.js) ทุกโมดูลผูก listener ที่ราก [data-social-page] ครั้งเดียว
 * เรียกซ้ำได้โดยไม่ผูกซ้ำ (ธง data-social-ready)
 *
 * สิทธิ์ไม่ถูกตัดสินที่นี่ — ปุ่มที่มีในหน้ามาจาก policy ของ server และ server ตรวจซ้ำทุกคำขอ
 */
export function initSocialPage(root = globalThis.document, deps = {}) {
    const page = root?.querySelector?.('[data-social-page]');
    if (! page || page.dataset.socialReady === 'true') return null;
    page.dataset.socialReady = 'true';

    const env = {
        bootstrapApi: deps.bootstrapApi ?? globalThis.window?.bootstrap,
        swal: deps.swal ?? (() => globalThis.window?.Swal),
        fetchImpl: deps.fetchImpl ?? ((...args) => globalThis.fetch(...args)),
    };

    const viewer = createImageViewer(page.querySelector('[data-social-viewer]'), env);
    let feed = null;
    const composer = createComposer(page, {...env, onSaved: (payload, mode, card) => feed?.onSaved(payload, mode, card)});
    feed = createFeed(page, {...env, viewer, composer});
    const comments = initComments(page, env);

    return {feed, composer, viewer, comments};
}

if (typeof document !== 'undefined') initSocialPage(document);
