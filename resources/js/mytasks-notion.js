(() => {
    const root = document.querySelector('[data-workspace]');
    if (!root) return;

    const table = root.querySelector('[data-groups]');
    const search = root.querySelector('[data-search]');
    const filter = root.querySelector('[data-filter]');
    const groupSelect = root.querySelector('[data-group]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const url = (template, id) => template.replace('__ID__', id);
    let ascending = true;
    let toastTimer;

    const toast = (message, ok = true) => {
        const element = document.querySelector('[data-toast]');
        if (!element) return;
        element.textContent = message;
        element.style.background = ok ? '#111827' : '#dc2626';
        element.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => element.classList.remove('show'), 2200);
    };

    const request = async (endpoint, method, body) => {
        const options = {method, headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
        if (body instanceof FormData) {
            options.body = body;
        } else {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        const response = await fetch(endpoint, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'บันทึกไม่สำเร็จ');
        return data;
    };

    const apply = () => {
        const query = search?.value.trim().toLowerCase() || '';
        const selectedStatus = filter?.value || '';
        let shown = 0;
        root.querySelectorAll('[data-row]').forEach((row) => {
            const matchesText = !query || row.textContent.toLowerCase().includes(query);
            const matchesFilter = !selectedStatus || (selectedStatus === 'late' ? row.dataset.late === '1' : row.dataset.status === selectedStatus);
            row.hidden = !(matchesText && matchesFilter);
            if (!row.hidden) shown++;
        });
        root.querySelectorAll('[data-group-section]').forEach((group) => {
            group.hidden = ![...group.querySelectorAll('[data-row]')].some((row) => !row.hidden);
        });
        const empty = root.querySelector('[data-empty]');
        if (empty) empty.hidden = shown > 0;
    };

    const regroup = () => {
        if (!table || !groupSelect) return;
        const field = groupSelect.value;
        const labels = {status: {1:'ยังไม่เริ่ม',2:'กำลังทำ',3:'รอตรวจสอบ',4:'เสร็จแล้ว',5:'พักงาน'}, priority: {1:'routine',2:'สำคัญไม่ด่วน',3:'สำคัญด่วน',4:'ด่วนไม่ค่อยสำคัญ',5:'ไม่รีบ ไม่มีกำหนด'}};
        const groups = {};
        root.querySelectorAll('[data-row]').forEach((row) => {
            let key = row.dataset[field] || 'ไม่ระบุ';
            if (labels[field]) key = labels[field][key] || key;
            (groups[key] ??= []).push(row);
        });
        table.innerHTML = '';
        Object.entries(groups).sort(([first], [second]) => first.localeCompare(second, 'th')).forEach(([name, items]) => {
            const section = document.createElement('section');
            section.className = 'notion-group-section';
            section.dataset.groupSection = '';
            section.dataset.groupKey = name;
            section.innerHTML = `<header><button type="button" data-collapse><i class="bi bi-chevron-down"></i></button><span class="project-pill">${name.replace(/[&<>]/g, '')}</span><small>${items.length} งาน</small></header><div data-group-rows></div>`;
            items.forEach((row) => section.querySelector('[data-group-rows]').appendChild(row));
            table.appendChild(section);
        });
        apply();
    };

    root.addEventListener('change', async (event) => {
        const row = event.target.closest('[data-row]');
        const field = event.target.dataset.field;
        if (!row || !field) return;
        const id = row.dataset.id;
        const template = {
            status: root.dataset.statusTemplate,
            priority: root.dataset.priorityTemplate,
            due: root.dataset.dueTemplate,
        }[field];
        if (!template) return;
        try {
            if (field === 'status') {
                await request(url(template, id), 'PATCH', {job_status: +event.target.value});
                row.dataset.status = event.target.value;
            } else if (field === 'priority') {
                await request(url(template, id), 'POST', {job_priority: +event.target.value});
                row.dataset.priority = event.target.value;
            } else if (field === 'due') {
                await request(url(template, id), 'POST', {job_due_at: event.target.value});
                row.dataset.due = event.target.value;
            }
            toast('บันทึกการเปลี่ยนแปลงแล้ว');
            if (groupSelect?.value === field) regroup();
        } catch (error) {
            toast(error.message, false);
            location.reload();
        }
    });

    root.addEventListener('click', async (event) => {
        const collapse = event.target.closest('[data-collapse]');
        if (collapse) {
            const section = collapse.closest('[data-group-section]');
            if (!section) return;
            const body = section.querySelector('[data-group-rows]');
            if (!body) return;
            body.hidden = !body.hidden;
            if (!collapse.querySelector('i')) return;
            collapse.querySelector('i').className = `bi bi-chevron-${body.hidden ? 'right' : 'down'}`;
        }

        const add = event.target.closest('[data-add-in-group]');
        if (add && (root.dataset.quickUrl || root.dataset.quickTemplate)) {
            const result = await Swal.fire({title: 'เพิ่มรายการใหม่', input: 'text', inputPlaceholder: 'ระบุชื่องาน', inputAttributes: {maxlength: 255}, showCancelButton: true, confirmButtonText: 'เพิ่มรายการ', cancelButtonText: 'ยกเลิก', reverseButtons: true, inputValidator: (value) => value.trim() ? undefined : 'กรุณาระบุชื่องาน'});
            const title = result.value?.trim();
            if (!result.isConfirmed || !title) return;
            add.disabled = true;
            try {
                const quickUrl = root.dataset.quickTemplate
                    ? root.dataset.quickTemplate.replace('__LIST__', add.dataset.listId)
                    : root.dataset.quickUrl;
                const payload = root.dataset.quickTemplate
                    ? {job_topic: title}
                    : {job_topic: title, work_order_list_id: +add.dataset.listId};
                await request(quickUrl, 'POST', payload);
                await Swal.fire({icon: 'success', title: 'เพิ่มรายการแล้ว', timer: 900, showConfirmButton: false});
                location.reload();
            } catch (error) {
                add.disabled = false;
                await Swal.fire({icon: 'error', title: 'เพิ่มรายการไม่สำเร็จ', text: error.message, confirmButtonText: 'ตกลง'});
            }
        }
    });

    search?.addEventListener('input', apply);
    filter?.addEventListener('change', apply);
    groupSelect?.addEventListener('change', regroup);
    const sort = root.querySelector('[data-sort]');
    sort?.addEventListener('click', () => {
        ascending = !ascending;
        root.querySelectorAll('[data-group-rows]').forEach((group) => {
            [...group.querySelectorAll('[data-row]')]
                .sort((first, second) => {
                    const firstDue = first.dataset.due || '';
                    const secondDue = second.dataset.due || '';
                    return ascending ? firstDue.localeCompare(secondDue) : secondDue.localeCompare(firstDue);
                })
                .forEach((row) => group.appendChild(row));
        });
    });
    root.querySelectorAll('[data-summary-filter]').forEach((button) => button.onclick = () => {
        if (!filter) return;
        filter.value = button.dataset.summaryFilter;
        apply();
    });
    root.querySelectorAll('[data-view-placeholder]').forEach((button) => button.onclick = () => toast('มุมมองนี้จะเพิ่มในขั้นถัดไป'));

    const modal = document.querySelector('[data-create-modal]');
    const createForm = document.querySelector('[data-create-form]');
    const openCreateButtons = [...document.querySelectorAll('[data-open-create]')];
    const closeCreateButtons = [...document.querySelectorAll('[data-close-create]')];
    if (modal && createForm && openCreateButtons.length && closeCreateButtons.length && root.dataset.createUrl) {
        openCreateButtons.forEach((button) => button.onclick = () => modal.hidden = false);
        closeCreateButtons.forEach((button) => button.onclick = () => modal.hidden = true);
        modal.addEventListener('click', (event) => { if (event.target === modal) modal.hidden = true; });
        createForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = event.target.querySelector('[type="submit"]');
            if (!button) return;
            button.disabled = true;
            try {
                await request(root.dataset.createUrl, 'POST', new FormData(event.target));
                toast('สร้างโปรเจกต์แล้ว');
                location.reload();
            } catch (error) {
                toast(error.message, false);
                button.disabled = false;
            }
        });
    }
})();
