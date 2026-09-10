import {updateNotificationCount} from './realtime-sync.js';

/*
 * ปุ่มจัดการในดร็อปดาวน์แจ้งเตือนบนแถบบน — อ่านทั้งหมด และล้างรายการที่อ่านแล้ว
 *
 * ใช้เส้นทางเดียวกับหน้าศูนย์การแจ้งเตือน (notifications.read-all และ
 * notifications.destroy-read) ไม่ได้สร้าง endpoint หรือกติกาชุดที่สอง
 * และเรียก updateNotificationCount() ตัวเดียวกับ realtime-sync เพื่อให้ตัวเลข
 * บนกระดิ่ง เมนูข้าง และป้ายสรุปในดร็อปดาวน์ถูกอัปเดตจากที่เดียว
 *
 * การลบเป็นการลบถาวร จึงต้องถามยืนยันด้วย SweetAlert เสมอ ห้ามใช้ confirm() ของเบราว์เซอร์
 */

const READ_ALL_URL = '/notifications/read-all';
const CLEAR_READ_URL = '/notifications/read';

function csrfToken(root) {
    return root.querySelector('meta[name="csrf-token"]')?.content || '';
}

/** รายการในดร็อปดาวน์ที่อ่านแล้ว — ใช้ทั้งตอนนับเพื่อถามยืนยันและตอนลบออกจากจอ */
export function readItems(root) {
    return [...root.querySelectorAll('[data-dropdown-notification-id]')]
        .filter((item) => ! item.classList.contains('is-new'));
}

/** ทำเครื่องหมายอ่านแล้วทุกใบในดร็อปดาวน์ โดยไม่ต้องโหลดหน้าใหม่ */
export function markAllReadInDom(root) {
    root.querySelectorAll('[data-dropdown-notification-id].is-new').forEach((item) => {
        item.classList.remove('is-new');
        item.querySelector('.notification-new')?.remove();
    });

    updateNotificationCount(root, 0);
    root.querySelector('[data-notification-read-all]')?.setAttribute('disabled', '');
}

/** เอารายการที่อ่านแล้วออกจากดร็อปดาวน์ และคืนสถานะว่างเมื่อไม่เหลืออะไรเลย */
export function removeReadFromDom(root) {
    readItems(root).forEach((item) => item.remove());

    const remaining = root.querySelectorAll('[data-dropdown-notification-id]').length;

    if (remaining === 0) {
        root.querySelector('[data-notification-dropdown-empty]')?.removeAttribute('hidden');
    }

    return remaining;
}

async function send(fetchImpl, url, method, root) {
    const response = await fetchImpl(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(root),
        },
    });

    if (! response.ok) {
        throw new Error('notification action failed');
    }

    return response;
}

/**
 * ผูกปุ่มจัดการเข้ากับดร็อปดาวน์ เรียกซ้ำได้โดยไม่เกิด listener ซ้อน
 *
 * ใช้ event delegation ที่ตัวเมนู เพราะรายการในดร็อปดาวน์ถูกเพิ่มเข้ามาใหม่
 * ได้ตลอดเวลาจาก realtime-sync
 */
export function initNotificationMenu(root = document, fetchImpl = globalThis.fetch) {
    const menu = root.querySelector('[data-notification-menu]');

    if (! menu || typeof fetchImpl !== 'function' || menu.dataset.notificationMenuReady === 'true') {
        return null;
    }

    menu.dataset.notificationMenuReady = 'true';

    // คลิกในเมนูต้องไม่ทำให้ Bootstrap ปิดดร็อปดาวน์ทิ้งกลางคัน
    menu.addEventListener('click', (event) => {
        if (event.target.closest('[data-notification-read-all], [data-notification-clear-read]')) {
            event.stopPropagation();
        }
    });

    const swal = () => root.defaultView?.Swal;

    const notifyFailure = async (title) => {
        await swal()?.fire({icon: 'error', title, text: 'กรุณาลองใหม่อีกครั้ง', confirmButtonText: 'ตกลง'});
    };

    menu.querySelector('[data-notification-read-all]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;

        if (button.disabled) {
            return;
        }

        button.disabled = true;

        try {
            await send(fetchImpl, READ_ALL_URL, 'POST', root);
            markAllReadInDom(root);
        } catch {
            button.disabled = false;
            await notifyFailure('ทำเครื่องหมายไม่สำเร็จ');
        }
    });

    menu.querySelector('[data-notification-clear-read]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const count = readItems(root).length;

        if (count === 0) {
            await swal()?.fire({
                icon: 'info',
                title: 'ไม่มีรายการที่อ่านแล้ว',
                text: 'รายการที่ยังไม่อ่านจะไม่ถูกลบ',
                confirmButtonText: 'ตกลง',
            });

            return;
        }

        const confirmation = await swal()?.fire({
            icon: 'warning',
            title: 'ล้างรายการที่อ่านแล้ว?',
            text: `ลบถาวร ${count.toLocaleString('th-TH')} รายการ โดยไม่กระทบรายการที่ยังไม่อ่าน`,
            showCancelButton: true,
            confirmButtonText: 'ลบเลย',
            cancelButtonText: 'ยกเลิก',
        });

        if (! confirmation?.isConfirmed) {
            return;
        }

        button.disabled = true;

        try {
            await send(fetchImpl, CLEAR_READ_URL, 'DELETE', root);
            removeReadFromDom(root);
        } catch {
            await notifyFailure('ลบไม่สำเร็จ');
        } finally {
            button.disabled = false;
        }
    });

    return {menu};
}

if (typeof document !== 'undefined') {
    initNotificationMenu(document);
}
