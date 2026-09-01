/**
 * ตรรกะของตัวเลือกบุคคลแบบใช้ร่วมกัน (ผู้เข้าร่วมประชุม / ผู้ร่วมงานของงาน)
 *
 * หลักการสำคัญที่ยกมาจากตัวเลือกผู้เข้าร่วมประชุมเดิมและต้องรักษาไว้:
 * checkbox คือแหล่งความจริงเพียงแหล่งเดียว การกรองแตะแค่ `hidden` ของแถว
 * และรายการที่เลือกถูกคำนวณจากตัวเลือกทั้งหมดโดยไม่สนตัวกรอง
 * นั่นคือเหตุผลที่คนที่เลือกไว้ไม่หายเมื่อผู้ใช้เปลี่ยนคำค้นหรือแผนก
 */

export function normalizeKeyword(value) {
    return String(value ?? '').trim().toLocaleLowerCase('th');
}

/**
 * @param {Array<{id: *, departmentId: *, search: string, checked: boolean}>} options
 * @returns {{selectedIds: string[], visibleIds: string[]}}
 */
export function derivePeopleState(options, keyword = '', departmentId = '') {
    const normalizedKeyword = normalizeKeyword(keyword);
    const normalizedDepartment = String(departmentId ?? '');
    const selectedIds = [];
    const visibleIds = [];

    options.forEach((option) => {
        const id = String(option.id);
        // คนที่ถูกกันออก (เช่น อยู่ในทีมแล้ว) ต้องไม่โผล่ในรายการและไม่ถูกนับเป็นที่เลือกไว้
        if (option.excluded) return;

        const matchesDepartment = normalizedDepartment === '' || String(option.departmentId ?? '') === normalizedDepartment;
        const matchesSearch = normalizedKeyword === '' || normalizeKeyword(option.search).includes(normalizedKeyword);

        // เลือกไว้แล้วต้องนับเสมอ ไม่ว่าตัวกรองปัจจุบันจะซ่อนแถวนั้นอยู่หรือไม่
        if (option.checked) selectedIds.push(id);
        if (matchesDepartment && matchesSearch) visibleIds.push(id);
    });

    return {selectedIds, visibleIds};
}

export function updateSelection(selectedIds, personId, isSelected) {
    const nextIds = new Set(selectedIds.map(String));
    const normalizedId = String(personId);

    if (isSelected) nextIds.add(normalizedId);
    else nextIds.delete(normalizedId);

    return Array.from(nextIds);
}

/**
 * คนที่ถูกกันออกจากรายการต้องไม่ถูกนับเป็นที่เลือกไว้
 * ต่างจาก readOnly ซึ่งแค่แก้ไม่ได้ แต่ของที่เลือกไว้ก่อนหน้ายังต้องแสดงตามปกติ
 */
function isExcluded(checkbox) {
    return checkbox.closest('[data-people-option]')?.hasAttribute('data-people-excluded') === true;
}

/** id ที่ถูกเลือกอยู่จริง ณ ขณะนั้น ใช้ประกอบ payload ที่จะส่ง backend */
export function selectedIdsOf(root) {
    return [...root.querySelectorAll('[data-people-checkbox]')]
        .filter((checkbox) => checkbox.checked && !isExcluded(checkbox))
        .map((checkbox) => Number(checkbox.value));
}

/** ล้างเฉพาะรายการที่กำลังเลือกในฝั่ง client โดยไม่แตะสมาชิกที่บันทึกแล้ว */
export function clearPeopleSelection(root) {
    root?.querySelectorAll?.('[data-people-checkbox]').forEach((checkbox) => {
        checkbox.checked = false;
    });
    refreshPeopleSelector(root);
}

/**
 * กำหนดว่าใครต้องหายไปจากรายการทั้งหมด ใช้ตอนเปิดงานแต่ละงาน
 * เลือกวิธีเอาออกจากรายการ ไม่ใช่แสดงเป็น disabled เพราะสมาชิกปัจจุบันมีที่แสดงของตัวเองอยู่แล้ว
 */
export function setExcludedIds(root, ids = []) {
    const excluded = new Set([...ids].map(String));

    root.querySelectorAll('[data-people-option]').forEach((row) => {
        const isExcluded = excluded.has(String(row.dataset.personId));
        const checkbox = row.querySelector('[data-people-checkbox]');

        row.toggleAttribute('data-people-excluded', isExcluded);
        if (checkbox) {
            checkbox.disabled = isExcluded || root.dataset.readonly === 'true';
            if (isExcluded) checkbox.checked = false;
        }
    });

    refreshPeopleSelector(root);
}

const refreshers = new WeakMap();

/** วาดชิปและตัวกรองใหม่ ใช้เมื่อโค้ดภายนอกเปลี่ยนสถานะ checkbox เอง */
export function refreshPeopleSelector(root) {
    refreshers.get(root)?.();
}

export function initializePeopleSelector(root) {
    if (!root || root.dataset.peopleInitialized === 'true') return;
    root.dataset.peopleInitialized = 'true';

    const search = root.querySelector('[data-people-search]');
    const departmentSelect = root.querySelector('[data-people-department-select]');
    const departmentButtons = [...root.querySelectorAll('[data-people-department]')];
    const optionRows = [...root.querySelectorAll('[data-people-option]')];
    const checkboxes = [...root.querySelectorAll('[data-people-checkbox]')];
    const optionsEmpty = root.querySelector('[data-people-empty]');
    const chips = root.querySelector('[data-people-chips]');
    const count = root.querySelector('[data-people-count]');
    const summaryCount = root.querySelector('[data-people-summary-count]');
    const clearButton = root.querySelector('[data-people-clear]');
    const stage = root.querySelector('[data-people-stage]');
    const chipsEmptyLabel = root.querySelector('[data-people-chips-empty]')?.textContent || 'ยังไม่ได้เลือก';
    const isTeamManager = root.dataset.peopleVariant === 'team-manager';
    const isReadOnly = () => root.dataset.readonly === 'true';
    let activeDepartment = '';

    const optionData = () => optionRows.map((row) => ({
        id: row.dataset.personId,
        departmentId: row.dataset.departmentId,
        search: row.dataset.search,
        excluded: row.hasAttribute('data-people-excluded'),
        checked: row.querySelector('[data-people-checkbox]')?.checked === true,
    }));

    const renderChips = () => {
        if (!chips || !count) return;

        const selected = checkboxes.filter((checkbox) => checkbox.checked && !isExcluded(checkbox));
        const template = count.dataset.countTemplate || 'เลือกแล้ว :count คน';
        count.textContent = template.replace(':count', String(selected.length));
        if (summaryCount) summaryCount.textContent = `เลือกแล้ว ${selected.length} คน`;
        if (clearButton) clearButton.disabled = isReadOnly() || selected.length === 0;
        if (stage) stage.hidden = selected.length === 0;
        optionRows.forEach((row) => {
            const checkbox = row.querySelector('[data-people-checkbox]');
            row.classList.toggle('is-selected', checkbox?.checked === true && !row.hasAttribute('data-people-excluded'));
        });
        chips.replaceChildren();
        // แจ้งผู้เรียกภายนอกเพื่ออัปเดตปุ่มหลัก โดยไม่ต้องให้ผู้เรียกไปผูก event กับ checkbox เอง
        root.dispatchEvent(new (root.ownerDocument.defaultView.CustomEvent)('peopleselector:change', {
            detail: {selectedIds: selected.map((checkbox) => Number(checkbox.value))},
        }));

        if (!selected.length) {
            const empty = chips.ownerDocument.createElement('p');
            empty.className = 'people-selector__empty';
            empty.dataset.peopleChipsEmpty = '';
            empty.textContent = chipsEmptyLabel;
            chips.append(empty);
            return;
        }

        selected.forEach((checkbox) => {
            const chip = chips.ownerDocument.createElement(isTeamManager ? 'article' : 'span');
            const remove = chips.ownerDocument.createElement('button');
            const icon = chips.ownerDocument.createElement('i');
            const name = checkbox.dataset.personName || '';

            chip.className = 'people-selector__chip';
            chip.dataset.peopleChip = '';
            chip.dataset.personId = checkbox.value;
            remove.type = 'button';
            remove.dataset.peopleRemove = '';
            remove.dataset.personId = checkbox.value;
            remove.disabled = isReadOnly() || checkbox.disabled;
            remove.setAttribute('aria-label', `ยกเลิกการเลือก ${name}`);
            icon.className = 'bi bi-x';
            icon.setAttribute('aria-hidden', 'true');
            remove.append(icon);

            if (isTeamManager) {
                const avatar = chips.ownerDocument.createElement('span');
                const copy = chips.ownerDocument.createElement('span');
                const label = chips.ownerDocument.createElement('strong');
                const detail = chips.ownerDocument.createElement('small');
                const department = chips.ownerDocument.createElement('span');
                const avatarUrl = checkbox.dataset.personAvatarUrl || '';
                const departmentName = checkbox.dataset.personDepartment || 'ไม่ระบุแผนก';

                avatar.className = 'people-selector__chip-avatar';
                copy.className = 'people-selector__chip-copy';
                department.className = 'people-selector__chip-department';
                label.textContent = name;
                detail.textContent = checkbox.dataset.personEmail || departmentName;
                department.textContent = departmentName;
                if (avatarUrl) {
                    const image = chips.ownerDocument.createElement('img');
                    image.src = avatarUrl;
                    image.alt = '';
                    avatar.append(image);
                } else {
                    avatar.textContent = Array.from(name || '?')[0] || '?';
                }
                copy.append(label, detail);
                chip.append(avatar, copy, department, remove);
            } else {
                const label = chips.ownerDocument.createElement('span');
                label.textContent = name;
                chip.append(label, remove);
            }
            chips.append(chip);
        });
    };

    const applyFilters = () => {
        const state = derivePeopleState(optionData(), search?.value, activeDepartment);
        const visible = new Set(state.visibleIds);

        // ซ่อนเท่านั้น ห้ามลบโหนดหรือแตะ checked มิฉะนั้นคนที่เลือกไว้จะหาย
        optionRows.forEach((row) => {
            row.hidden = !visible.has(String(row.dataset.personId));
        });
        if (optionsEmpty) optionsEmpty.hidden = visible.size > 0;
    };

    search?.addEventListener('input', applyFilters);

    const setActiveDepartment = (departmentId) => {
        activeDepartment = String(departmentId || '');
        departmentButtons.forEach((candidate) => {
            const isActive = String(candidate.dataset.departmentId || '') === activeDepartment;
            candidate.classList.toggle('is-active', isActive);
            candidate.setAttribute('aria-pressed', String(isActive));
        });
        if (departmentSelect && departmentSelect.value !== activeDepartment) {
            departmentSelect.value = activeDepartment;
        }
        applyFilters();
    };

    departmentButtons.forEach((button) => {
        button.addEventListener('click', () => setActiveDepartment(button.dataset.departmentId));
    });
    departmentSelect?.addEventListener('change', () => setActiveDepartment(departmentSelect.value));

    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', renderChips));
    clearButton?.addEventListener('click', () => clearPeopleSelection(root));

    chips?.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-people-remove]');
        if (!remove || remove.disabled) return;

        const current = derivePeopleState(optionData()).selectedIds;
        const next = new Set(updateSelection(current, remove.dataset.personId, false));
        checkboxes.forEach((checkbox) => {
            if (!checkbox.disabled) checkbox.checked = next.has(String(checkbox.value));
        });
        renderChips();
    });

    refreshers.set(root, () => {
        applyFilters();
        renderChips();
    });

    applyFilters();
    renderChips();
}

export function initializePeopleSelectors(root = globalThis.document) {
    root?.querySelectorAll?.('[data-people-selector]').forEach(initializePeopleSelector);
}
