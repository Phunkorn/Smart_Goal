import {boardFilterStateFrom, boardTaskMatches} from './pages/mytasks/task-filter-state.js';

(() => {
    const root = document.querySelector('[data-workspace]');
    if (!root) return;

    const table = root.querySelector('[data-groups]');
    const search = root.querySelector('[data-search]');
    const filter = root.querySelector('[data-filter]');
    const groupSelect = root.querySelector('[data-group]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const url = (template, id) => template.replace('__ID__', id);
    /*
     * รูปแบบวันต้องตรงกับที่ Blade เขียนไว้ทุกตัวอักษร ("17 มิ.ย. 69")
     * ไม่งั้นแถวที่เพิ่งแก้จะดูต่างจากแถวที่ยังไม่ถูกแตะจนเหมือนคนละคอลัมน์
     *
     * timeZone: 'UTC' เพราะค่าที่ป้อนเข้ามาถูกประกอบเป็นเที่ยงคืนของวันนั้นอยู่แล้ว
     * ถ้าปล่อยให้ใช้โซนของเครื่อง วันจะขยับตามเครื่องของผู้ใช้แต่ละคน
     */
    const thaiShortDate = new Intl.DateTimeFormat('th-TH', {day: 'numeric', month: 'short', year: '2-digit', timeZone: 'UTC'});
    let ascending = true;
    let toastTimer;
    const initialFilterState = boardFilterStateFrom(new URLSearchParams(window.location.search));
    if (search) search.value = initialFilterState.search;
    if (filter) filter.value = initialFilterState.status;

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
            const matchesFilter = boardTaskMatches({
                searchable: '',
                status: row.dataset.status,
                canReview: row.dataset.canReview,
                reviewableSubtasks: row.dataset.reviewableSubtasks,
                late: row.dataset.late,
                crossDepartment: row.dataset.crossDepartment,
            }, {search: '', status: selectedStatus});
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
        const labels = {status: {2:'กำลังทำ',3:'รอตรวจสอบ',4:'เสร็จแล้ว',5:'พักงาน',6:'ล่าช้า'}, priority: {1:'routine',2:'สำคัญไม่ด่วน',3:'สำคัญด่วน',4:'ด่วนไม่ค่อยสำคัญ',5:'ไม่รีบ ไม่มีกำหนด'}};
        const groups = {};
        root.querySelectorAll('[data-row]').forEach((row) => {
            let key = row.dataset[field] || 'ไม่ระบุ';
            if (labels[field]) key = labels[field][key] || (field === 'status' ? 'สถานะไม่รองรับ' : key);
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

    /** ป้ายในเซลล์กำหนดส่งอ่านจาก dataset ชุดเดียว จึงตามการแก้ไขได้โดยไม่ต้องรีโหลดหน้า */
    const paintDueCell = (row) => {
        const dueLabel = row.querySelector('[data-due-label]');
        const date = row.dataset.due || '';
        if (dueLabel && date) {
            // ต่อท้ายด้วย Z ให้ตรงกับ timeZone:'UTC' ของตัวจัดรูปแบบ ไม่งั้นเครื่องที่ +07:00 จะเลื่อนวันย้อนไปหนึ่งวัน
            const parsed = new Date(`${date}T00:00:00Z`);
            if (!Number.isNaN(parsed.getTime())) {
                dueLabel.textContent = thaiShortDate.format(parsed);
            }
        }

        const timeLabel = row.querySelector('[data-due-time-label]');
        if (timeLabel) {
            const clock = row.dataset.dueTime || '';
            timeLabel.textContent = clock ? `ส่งภายใน ${clock} น.` : 'ไม่มีเวลากำหนดส่ง';
        }
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
                const data = await request(url(template, id), 'POST', {job_due_at: event.target.value});

                /*
                 * ช่องกำหนดส่งส่งค่ามาเป็น 'Y-m-dTH:i' แต่ data-due ต้องเป็นวันล้วนต่อไป
                 * เพราะการจัดกลุ่มและการเรียงของตารางเทียบสตริงวันตรง ๆ ถ้าเก็บเวลาไว้ด้วย
                 * งานวันเดียวกันแต่คนละเวลาจะถูกแยกเป็นคนละกลุ่ม เวลาจึงแยกไปอยู่ data-due-time
                 */
                const [dueDate = '', dueTime = ''] = String(event.target.value || '').split('T');
                row.dataset.due = data?.job_due_at ?? dueDate;
                row.dataset.dueTime = data?.job_due_time ?? dueTime;
                paintDueCell(row);
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
            if (root.dataset.context === 'user' && document.querySelector('[data-user-task-create-modal]')) {
                document.dispatchEvent(new CustomEvent('mytasks:create-task', {
                    detail: {listId: add.dataset.listId || ''},
                }));
                return;
            }
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
    apply();
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
