const displayCount = (count) => count > 99 ? '99+' : String(count);

const updateUnreadIndicators = (count) => {
    document.querySelectorAll('[data-notification-count]').forEach((badge) => {
        if (count === 0) {
            badge.remove();
            return;
        }

        badge.textContent = displayCount(count);
    });

    document.querySelectorAll('[data-notification-summary]').forEach((summary) => {
        summary.textContent = `${count} รายการ`;
        summary.classList.toggle('amber', count > 0);
        summary.classList.toggle('gray', count === 0);
    });
};

const emptyStateMarkup = () => `
    <div class="notification-center__empty" data-notification-empty>
        <i class="bi bi-bell-slash" aria-hidden="true"></i>
        <strong>ไม่มีการแจ้งเตือน</strong>
        <span>ยังไม่มีรายการที่ตรงกับตัวกรองนี้</span>
        <small>ลองเปลี่ยนตัวกรองหรือเลือกหมวดหมู่อื่น</small>
    </div>`;

const submitAjax = async (form, fetchImpl) => {
    const response = await fetchImpl(form.action, {
        method: form.method || 'POST',
        body: new form.ownerDocument.defaultView.FormData(form),
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (! response.ok) {
        throw new Error(`Notification request failed (${response.status})`);
    }

    return response.json();
};

const confirmDelete = (swal, options) => swal.fire({
    icon: 'warning',
    title: options.title,
    text: options.text,
    showCancelButton: true,
    confirmButtonText: 'ลบ',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#dc3545',
    reverseButtons: true,
    focusCancel: true,
    customClass: {popup: 'notification-confirm'},
});

export function initNotificationCenter({
    root = document.querySelector('[data-notification-center]'),
    swal = globalThis.Swal,
    fetchImpl = globalThis.fetch,
    reload = () => window.location.reload(),
} = {}) {
    if (! root || ! swal || root.dataset.notificationInitialized === 'true') return;
    root.dataset.notificationInitialized = 'true';

    if (root.dataset.flashWarning) {
        swal.fire({
            icon: 'warning',
            text: root.dataset.flashWarning,
            confirmButtonText: 'ตกลง',
            customClass: {popup: 'notification-confirm'},
        });
    }

    root.querySelectorAll('[data-delete-notification-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const confirmation = await confirmDelete(swal, {
                title: 'ลบการแจ้งเตือนนี้?',
                text: 'รายการนี้จะถูกลบถาวร',
            });
            if (! confirmation.isConfirmed) return;

            try {
                const result = await submitAjax(form, fetchImpl);
                const item = form.closest('[data-notification-center-item]');
                const group = item?.closest('[data-notification-group]');
                const pageItems = Number(root.dataset.pageItems || 0);
                const totalItems = Number(root.dataset.totalItems || 0);

                updateUnreadIndicators(Number(result.unread_count || 0));
                if (item?.dataset.notificationId) {
                    document.querySelector(`[data-dropdown-notification-id="${item.dataset.notificationId}"]`)?.remove();
                }
                root.dataset.readCount = String(result.read_count || 0);
                const bulkForm = root.querySelector('[data-delete-read-form]');
                if (bulkForm) {
                    bulkForm.dataset.deleteCount = root.dataset.readCount;
                    bulkForm.querySelector('button').disabled = Number(root.dataset.readCount) === 0;
                }

                if (pageItems <= 1 && totalItems > 1) {
                    reload();
                    return;
                }

                item?.remove();
                if (group && ! group.querySelector('[data-notification-center-item]')) group.remove();

                root.dataset.pageItems = String(Math.max(0, pageItems - 1));
                root.dataset.totalItems = String(Math.max(0, totalItems - 1));
                if (! root.querySelector('[data-notification-center-item]')) {
                    root.querySelector('[data-notification-list]').innerHTML = emptyStateMarkup();
                    root.querySelector('.notification-center__pagination')?.remove();
                }
            } catch (error) {
                await swal.fire({
                    icon: 'error',
                    title: 'ลบไม่สำเร็จ',
                    text: 'กรุณาลองใหม่อีกครั้ง',
                    confirmButtonText: 'ตกลง',
                    customClass: {popup: 'notification-confirm'},
                });
            }
        });
    });

    const bulkForm = root.querySelector('[data-delete-read-form]');
    bulkForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const count = Number(bulkForm.dataset.deleteCount || 0);
        if (count === 0) return;

        const confirmation = await confirmDelete(swal, {
            title: 'ลบการแจ้งเตือนที่อ่านแล้ว?',
            text: `ลบถาวร ${count.toLocaleString('th-TH')} รายการ โดยไม่กระทบรายการที่ยังไม่อ่าน`,
        });
        if (! confirmation.isConfirmed) return;

        try {
            const result = await submitAjax(bulkForm, fetchImpl);
            updateUnreadIndicators(Number(result.unread_count || 0));
            root.dataset.readCount = '0';
            bulkForm.dataset.deleteCount = '0';
            bulkForm.querySelector('button').disabled = true;
            reload();
        } catch (error) {
            await swal.fire({
                icon: 'error',
                title: 'ลบไม่สำเร็จ',
                text: 'กรุณาลองใหม่อีกครั้ง',
                confirmButtonText: 'ตกลง',
                customClass: {popup: 'notification-confirm'},
            });
        }
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initNotificationCenter(), {once: true});
    } else {
        initNotificationCenter();
    }
}
