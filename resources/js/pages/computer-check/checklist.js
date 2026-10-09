/*
 * แผงตรวจรายเครื่อง — สถานะของฟอร์มและการสลับโหมด
 *
 * แผงนี้มีมาร์กอัปชุดเดียวทั้งเดสก์ท็อปและมือถือ (ดู checklist-panel.blade.php)
 * ไฟล์นี้จึงไม่มีสาขา "ถ้าเป็นมือถือ" เลย การวางตำแหน่งเป็นเรื่องของ CSS
 *
 * แผงเป็น popover/bottom sheet ไม่ใช่ modal — ไม่ล็อกการเลื่อนของหน้า
 * ไม่ดัก Tab ทั้งหน้า และคืนโฟกัสให้โต๊ะที่กดมาเมื่อปิด ตามกติกาใน CLAUDE.md
 * ที่แยก modal กับ popover ออกจากกัน เจ้าของสถานะเปิด/ปิดคือไฟล์นี้ไฟล์เดียว
 */

/** ต้องกรอกรายละเอียดปัญหาเมื่อมีอุปกรณ์ใดถูกเลือกว่า "มีปัญหา" */
export function requiresNote(statuses) {
    return Object.values(statuses || {}).includes('issue');
}

/** สถานะรวมของเครื่องจากสถานะอุปกรณ์ — กติกาเดียวกับ ComputerCheckDesign::rollUp() ฝั่ง PHP */
export function rollUp(statuses, deviceKeys) {
    const values = deviceKeys.map((key) => statuses?.[key] || 'pending');

    if (values.includes('issue')) return 'issue';

    const checked = values.filter((value) => value !== 'pending').length;

    if (checked === 0) return 'pending';

    return checked === values.length ? 'normal' : 'partial';
}

export function initChecklistPanel({root, design = {}, onSave, onBulkNormal} = {}) {
    const panel = root?.querySelector('[data-cc-panel]');

    if (! panel) return null;

    const form = panel.querySelector('[data-cc-result-form]');
    const bulkBody = panel.querySelector('[data-cc-panel-mode="multi"]');
    const emptyBody = panel.querySelector('[data-cc-panel-mode="empty"]');
    const title = panel.querySelector('[data-cc-panel-title]');
    const subtitle = panel.querySelector('[data-cc-panel-subtitle]');
    const noteField = panel.querySelector('[data-cc-note-field]');
    const note = panel.querySelector('[data-cc-note]');
    const meta = panel.querySelector('[data-cc-panel-meta]');
    const error = panel.querySelector('[data-cc-panel-error]');
    const bulkError = panel.querySelector('[data-cc-bulk-error]');
    const bulkCount = panel.querySelector('[data-cc-bulk-count]');
    const bulkList = panel.querySelector('[data-cc-bulk-list]');
    const scrim = panel.querySelector('[data-cc-panel-scrim]');

    const deviceKeys = Object.keys(design.devices || {});
    let current = null;
    let opener = null;

    const show = (mode) => {
        panel.hidden = false;
        if (form) form.hidden = mode !== 'single';
        if (bulkBody) bulkBody.hidden = mode !== 'multi';
        if (emptyBody) emptyBody.hidden = mode !== 'empty';
    };

    const setError = (node, message) => {
        if (! node) return;

        node.textContent = message || '';
        node.hidden = ! message;
    };

    const readStatuses = () => Object.fromEntries(deviceKeys.map((key) => [
        key,
        form?.querySelector(`input[name="${key}"]:checked`)?.value || 'pending',
    ]));

    /* ช่องรายละเอียดปัญหาโผล่เฉพาะตอนที่จำเป็นจริง ๆ */
    const syncNoteVisibility = () => {
        const needed = requiresNote(readStatuses());

        if (noteField) noteField.hidden = ! needed;
        if (note) note.required = needed;
    };

    const close = () => {
        panel.hidden = true;
        current = null;
        setError(error, '');
        setError(bulkError, '');
        // คืนโฟกัสให้สิ่งที่เปิดแผงมา ไม่ปล่อยให้โฟกัสหล่นไปต้นหน้า
        if (opener?.isConnected) opener.focus?.();
        opener = null;
    };

    const openForStation = (station, trigger) => {
        if (! station) return;

        current = station;
        opener = trigger || null;

        if (title) title.textContent = `เครื่องที่ ${station.code}`;
        if (subtitle) subtitle.textContent = `Zone ${station.zone}`;

        deviceKeys.forEach((key) => {
            const value = station.devices?.[key] || 'pending';
            const input = form?.querySelector(`input[name="${key}"][value="${value}"]`);

            if (input) input.checked = true;
        });

        if (note) note.value = station.note || '';
        syncNoteVisibility();

        if (meta) {
            const parts = [station.checked_by, station.checked_at].filter(Boolean);
            meta.textContent = parts.length ? `ตรวจโดย ${parts.join(' · ')}` : '';
            meta.hidden = parts.length === 0;
        }

        setError(error, '');
        show('single');
    };

    const openForSelection = (codes) => {
        if (bulkCount) bulkCount.textContent = `เลือกไว้ ${codes.length} เครื่อง`;

        if (bulkList) {
            bulkList.replaceChildren(...codes.map((code) => {
                const item = bulkList.ownerDocument.createElement('li');
                item.textContent = code;

                return item;
            }));
        }

        setError(bulkError, '');
        show(codes.length ? 'multi' : 'empty');
    };

    form?.addEventListener('change', syncNoteVisibility);

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (! current) return;

        const statuses = readStatuses();
        setError(error, '');

        try {
            await onSave?.({
                stationId: current.id,
                statuses,
                note: note?.value || '',
            });
            close();
        } catch (failure) {
            setError(error, failure.message);
        }
    });

    panel.querySelector('[data-cc-mark-all-normal]')?.addEventListener('click', () => {
        deviceKeys.forEach((key) => {
            const input = form?.querySelector(`input[name="${key}"][value="normal"]`);

            if (input) input.checked = true;
        });

        syncNoteVisibility();
    });

    panel.querySelector('[data-cc-bulk-normal]')?.addEventListener('click', async () => {
        setError(bulkError, '');

        try {
            await onBulkNormal?.();
            close();
        } catch (failure) {
            setError(bulkError, failure.message);
        }
    });

    panel.querySelectorAll('[data-cc-panel-close], [data-cc-bulk-clear]').forEach((button) => {
        button.addEventListener('click', close);
    });

    // แตะนอกแผง (ฉากหลังของ bottom sheet) เพื่อปิด — พฤติกรรมของ popover ไม่ใช่ modal
    scrim?.addEventListener('click', close);

    panel.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            close();
        }
    });

    return {
        openForStation,
        openForSelection,
        close,
        currentId: () => current?.id ?? null,
        rollUpOf: (statuses) => rollUp(statuses, deviceKeys),
    };
}
