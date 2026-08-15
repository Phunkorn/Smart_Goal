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
        const query = search.value.trim().toLowerCase();
        const selectedStatus = filter.value;
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
        root.querySelector('[data-empty]').hidden = shown > 0;
    };

    const regroup = () => {
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
        try {
            if (field === 'status') {
                await request(url(root.dataset.statusTemplate, id), 'PATCH', {job_status: +event.target.value});
                row.dataset.status = event.target.value;
            } else if (field === 'priority') {
                await request(url(root.dataset.priorityTemplate, id), 'POST', {job_priority: +event.target.value});
                row.dataset.priority = event.target.value;
            } else if (field === 'due') {
                await request(url(root.dataset.dueTemplate, id), 'POST', {job_due_at: event.target.value});
                row.dataset.due = event.target.value;
            } else if (field === 'progress') {
                await request(url(root.dataset.progressTemplate, id), 'POST', {progress: +event.target.value, note: 'อัปเดตความคืบหน้าจากตารางงาน'});
                event.target.closest('.row-progress').querySelector('b').style.width = `${event.target.value}%`;
            }
            toast('บันทึกการเปลี่ยนแปลงแล้ว');
            if (groupSelect.value === field) regroup();
        } catch (error) {
            toast(error.message, false);
            location.reload();
        }
    });

    root.addEventListener('click', async (event) => {
        const collapse = event.target.closest('[data-collapse]');
        if (collapse) {
            const section = collapse.closest('[data-group-section]');
            const body = section.querySelector('[data-group-rows]');
            body.hidden = !body.hidden;
            collapse.querySelector('i').className = `bi bi-chevron-${body.hidden ? 'right' : 'down'}`;
        }

        const add = event.target.closest('[data-add-in-group]');
        if (add) {
            const result = await Swal.fire({title: 'เพิ่มรายการใหม่', input: 'text', inputPlaceholder: 'ระบุชื่องาน', inputAttributes: {maxlength: 255}, showCancelButton: true, confirmButtonText: 'เพิ่มรายการ', cancelButtonText: 'ยกเลิก', reverseButtons: true, inputValidator: (value) => value.trim() ? undefined : 'กรุณาระบุชื่องาน'});
            const title = result.value?.trim();
            if (!result.isConfirmed || !title) return;
            add.disabled = true;
            try {
                await request(root.dataset.quickUrl, 'POST', {job_topic: title, work_order_list_id: +add.dataset.listId});
                await Swal.fire({icon: 'success', title: 'เพิ่มรายการแล้ว', timer: 900, showConfirmButton: false});
                location.reload();
            } catch (error) {
                add.disabled = false;
                await Swal.fire({icon: 'error', title: 'เพิ่มรายการไม่สำเร็จ', text: error.message, confirmButtonText: 'ตกลง'});
            }
        }
    });

    search.addEventListener('input', apply);
    filter.addEventListener('change', apply);
    groupSelect.addEventListener('change', regroup);
    root.querySelector('[data-sort]').onclick = () => {
        ascending = !ascending;
        root.querySelectorAll('[data-group-rows]').forEach((group) => {
            [...group.querySelectorAll('[data-row]')]
                .sort((first, second) => ascending ? first.dataset.due.localeCompare(second.dataset.due) : second.dataset.due.localeCompare(first.dataset.due))
                .forEach((row) => group.appendChild(row));
        });
    };
    root.querySelectorAll('[data-summary-filter]').forEach((button) => button.onclick = () => {
        filter.value = button.dataset.summaryFilter;
        apply();
    });
    root.querySelectorAll('[data-view-placeholder]').forEach((button) => button.onclick = () => toast('มุมมองนี้จะเพิ่มในขั้นถัดไป'));

    const modal = document.querySelector('[data-create-modal]');
    document.querySelectorAll('[data-open-create]').forEach((button) => button.onclick = () => modal.hidden = false);
    document.querySelectorAll('[data-close-create]').forEach((button) => button.onclick = () => modal.hidden = true);
    modal.addEventListener('click', (event) => { if (event.target === modal) modal.hidden = true; });
    document.querySelector('[data-create-form]').onsubmit = async (event) => {
        event.preventDefault();
        const button = event.target.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await request(root.dataset.createUrl, 'POST', new FormData(event.target));
            toast('สร้างโปรเจกต์แล้ว');
            location.reload();
        } catch (error) {
            toast(error.message, false);
            button.disabled = false;
        }
    };
})();
