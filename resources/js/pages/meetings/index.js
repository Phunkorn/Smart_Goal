export function normalizeAttendeeKeyword(value) {
    return String(value ?? '').trim().toLocaleLowerCase('th');
}

export function deriveAttendeeState(options, keyword = '', departmentId = '') {
    const normalizedKeyword = normalizeAttendeeKeyword(keyword);
    const normalizedDepartment = String(departmentId ?? '');
    const selectedIds = [];
    const visibleIds = [];

    options.forEach((option) => {
        const id = String(option.id);
        const matchesDepartment = normalizedDepartment === '' || String(option.departmentId ?? '') === normalizedDepartment;
        const matchesSearch = normalizedKeyword === '' || normalizeAttendeeKeyword(option.search).includes(normalizedKeyword);

        if (option.checked) selectedIds.push(id);
        if (matchesDepartment && matchesSearch) visibleIds.push(id);
    });

    return {selectedIds, visibleIds};
}

export function updateAttendeeSelection(selectedIds, attendeeId, isSelected) {
    const nextIds = new Set(selectedIds.map(String));
    const normalizedId = String(attendeeId);

    if (isSelected) nextIds.add(normalizedId);
    else nextIds.delete(normalizedId);

    return Array.from(nextIds);
}

export function resolveMeetingModal(root, modalId) {
    if (! root?.getElementById || typeof modalId !== 'string' || modalId.trim() === '') return null;

    const modalElement = root.getElementById(modalId);
    const classList = modalElement?.classList;
    const HTMLElementConstructor = root.defaultView?.HTMLElement ?? modalElement?.ownerDocument?.defaultView?.HTMLElement;

    if (! modalElement || (HTMLElementConstructor && ! (modalElement instanceof HTMLElementConstructor))) return null;
    if (! classList?.contains('modal') || ! classList.contains('meeting-form-modal')) return null;

    return modalElement;
}

export function showMeetingModal(modalElement, bootstrapApi = globalThis.window?.bootstrap, relatedTarget = null) {
    const Modal = bootstrapApi?.Modal;

    if (! modalElement || typeof Modal?.getOrCreateInstance !== 'function') return false;

    Modal.getOrCreateInstance(modalElement).show(relatedTarget);

    return true;
}

export function initializeMeetingModals(root, bootstrapApi = globalThis.window?.bootstrap) {
    if (! root?.querySelectorAll) return;

    root.querySelectorAll('[data-meeting-modal-trigger]').forEach((trigger) => {
        if (trigger.dataset.meetingModalInitialized === 'true') return;

        const modalElement = resolveMeetingModal(root, trigger.dataset.meetingModalTrigger);

        if (! modalElement || typeof bootstrapApi?.Modal?.getOrCreateInstance !== 'function') return;

        trigger.dataset.meetingModalInitialized = 'true';
        trigger.addEventListener('click', () => showMeetingModal(modalElement, bootstrapApi, trigger));
    });
}

function readFeedback(root) {
    const feedbackElement = root.querySelector('[data-meeting-feedback]');

    try {
        return JSON.parse(feedbackElement?.textContent || '{}');
    } catch {
        return {};
    }
}

function showFeedback(root, bootstrapApi = globalThis.window?.bootstrap) {
    const feedback = readFeedback(root);

    if (feedback.success) {
        window.Swal.fire({icon: 'success', title: 'สำเร็จ', text: feedback.success, confirmButtonText: 'ตกลง'});
    } else if (feedback.error) {
        window.Swal.fire({icon: 'error', title: 'ไม่สำเร็จ', text: feedback.error, confirmButtonText: 'ตกลง'});
    }

    if (feedback.open_modal) {
        const modalElement = resolveMeetingModal(root, feedback.open_modal);
        showMeetingModal(modalElement, bootstrapApi);
    }
}

function initializeDeleteConfirmation(root) {
    root.querySelectorAll('[data-meeting-delete]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const result = await window.Swal.fire({
                icon: 'warning',
                title: 'ยืนยันการลบการประชุม',
                text: `ต้องการลบการประชุม “${form.dataset.meetingTitle || ''}” หรือไม่?`,
                showCancelButton: true,
                confirmButtonText: 'ยืนยันลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
            });

            if (result.isConfirmed) form.submit();
        });
    });
}

function initializeAttendeeSelector(form) {
    const search = form.querySelector('[data-meeting-attendee-search]');
    const departmentButtons = Array.from(form.querySelectorAll('[data-meeting-department-filter]'));
    const optionRows = Array.from(form.querySelectorAll('[data-meeting-attendee-option]'));
    const checkboxes = Array.from(form.querySelectorAll('[data-meeting-attendee-checkbox]'));
    const optionEmpty = form.querySelector('[data-meeting-attendee-empty]');
    const selectedList = form.querySelector('[data-meeting-selected-attendees]');
    const selectedCount = form.querySelector('[data-meeting-selected-count]');
    let activeDepartment = '';

    const optionData = () => optionRows.map((row) => {
        const checkbox = row.querySelector('[data-meeting-attendee-checkbox]');

        return {
            id: row.dataset.attendeeId,
            departmentId: row.dataset.departmentId,
            search: row.dataset.search,
            checked: checkbox?.checked === true,
        };
    });

    const renderSelected = () => {
        if (! selectedList || ! selectedCount) return;

        const selectedCheckboxes = checkboxes.filter((checkbox) => checkbox.checked);
        selectedCount.textContent = `เลือกแล้ว ${selectedCheckboxes.length} คน`;
        selectedList.replaceChildren();

        optionRows.forEach((row) => {
            const checkbox = row.querySelector('[data-meeting-attendee-checkbox]');
            row.classList.toggle('is-selected', checkbox?.checked === true);
        });

        if (selectedCheckboxes.length === 0) {
            const empty = selectedList.ownerDocument.createElement('p');
            empty.className = 'meeting-attendee-selected__empty';
            empty.dataset.meetingSelectedEmpty = '';
            empty.textContent = 'ยังไม่ได้เลือกผู้เข้าร่วม';
            selectedList.append(empty);

            return;
        }

        selectedCheckboxes.forEach((checkbox) => {
            const chip = selectedList.ownerDocument.createElement('span');
            const label = selectedList.ownerDocument.createElement('span');
            const removeButton = selectedList.ownerDocument.createElement('button');
            const icon = selectedList.ownerDocument.createElement('i');

            chip.className = 'meeting-attendee-chip';
            chip.dataset.meetingSelectedChip = '';
            chip.dataset.attendeeId = checkbox.value;
            label.textContent = checkbox.dataset.attendeeName || '';
            removeButton.type = 'button';
            removeButton.dataset.meetingRemoveAttendee = '';
            removeButton.dataset.attendeeId = checkbox.value;
            removeButton.setAttribute('aria-label', `นำ ${checkbox.dataset.attendeeName || ''} ออกจากผู้เข้าร่วม`);
            icon.className = 'bi bi-x';
            icon.setAttribute('aria-hidden', 'true');
            removeButton.append(icon);
            chip.append(label, removeButton);
            selectedList.append(chip);
        });
    };

    const applyFilters = () => {
        const state = deriveAttendeeState(optionData(), search?.value, activeDepartment);
        const visibleIds = new Set(state.visibleIds);

        optionRows.forEach((row) => {
            row.hidden = ! visibleIds.has(String(row.dataset.attendeeId));
        });
        if (optionEmpty) optionEmpty.hidden = visibleIds.size > 0;
    };

    search?.addEventListener('input', applyFilters);
    departmentButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activeDepartment = button.dataset.departmentId || '';
            departmentButtons.forEach((candidate) => {
                const isActive = candidate === button;
                candidate.classList.toggle('is-active', isActive);
                candidate.setAttribute('aria-pressed', String(isActive));
            });
            applyFilters();
        });
    });
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', renderSelected));
    selectedList?.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-meeting-remove-attendee]');
        if (! removeButton) return;

        const currentIds = deriveAttendeeState(optionData()).selectedIds;
        const nextIds = new Set(updateAttendeeSelection(currentIds, removeButton.dataset.attendeeId, false));
        checkboxes.forEach((checkbox) => {
            checkbox.checked = nextIds.has(String(checkbox.value));
        });
        renderSelected();
    });

    applyFilters();
    renderSelected();
}

export function initializeMeetingPage(root = document, bootstrapApi = globalThis.window?.bootstrap) {
    initializeMeetingModals(root, bootstrapApi);
    initializeDeleteConfirmation(root);
    root.querySelectorAll('[data-meeting-form]').forEach(initializeAttendeeSelector);
    showFeedback(root, bootstrapApi);
}

if (typeof document !== 'undefined') initializeMeetingPage(document);
