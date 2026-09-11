const modal = document.querySelector('[data-user-task-create-modal]');
const form = modal?.querySelector('[data-user-task-create-form]');

if (modal && form) {
    const customSelects = [];

    const closeSelects = (except = null) => {
        customSelects.forEach(({root, button, menu}) => {
            if (root === except) return;
            root.classList.remove('is-open');
            button.setAttribute('aria-expanded', 'false');
            menu.hidden = true;
        });
    };

    const enhanceSelect = (select, index) => {
        const root = document.createElement('div');
        root.className = 'user-task-create__select';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'user-task-create__select-trigger';
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        button.innerHTML = '<span></span><i class="bi bi-chevron-down" aria-hidden="true"></i>';

        const menu = document.createElement('div');
        menu.className = 'user-task-create__select-menu';
        menu.id = `userTaskCreateSelect${index}`;
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;
        button.setAttribute('aria-controls', menu.id);

        const options = [...select.options].map((option) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'user-task-create__select-option';
            item.dataset.value = option.value;
            item.setAttribute('role', 'option');
            item.innerHTML = '<i class="bi bi-check-lg" aria-hidden="true"></i><span></span>';
            item.querySelector('span').textContent = option.textContent.trim();
            menu.append(item);
            return item;
        });

        const sync = () => {
            const selected = select.selectedOptions[0] || select.options[0];
            button.querySelector('span').textContent = selected?.textContent.trim() || 'เลือก';
            options.forEach((item) => {
                const active = item.dataset.value === select.value;
                item.classList.toggle('is-selected', active);
                item.setAttribute('aria-selected', String(active));
            });
        };

        const open = () => {
            const willOpen = menu.hidden;
            closeSelects(willOpen ? root : null);
            root.classList.toggle('is-open', willOpen);
            button.setAttribute('aria-expanded', String(willOpen));
            menu.hidden = !willOpen;
            if (willOpen) options.find((item) => item.classList.contains('is-selected'))?.focus();
        };

        button.addEventListener('click', open);
        button.addEventListener('keydown', (event) => {
            if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            event.preventDefault();
            open();
        });
        menu.addEventListener('click', (event) => {
            const item = event.target.closest('[data-value]');
            if (!item) return;
            select.value = item.dataset.value;
            select.dispatchEvent(new Event('change', {bubbles: true}));
            sync();
            closeSelects();
            button.focus();
        });
        menu.addEventListener('keydown', (event) => {
            const current = event.target.closest('[data-value]');
            if (!current) return;
            const currentIndex = options.indexOf(current);
            const targetIndex = event.key === 'ArrowDown'
                ? Math.min(options.length - 1, currentIndex + 1)
                : event.key === 'ArrowUp'
                    ? Math.max(0, currentIndex - 1)
                    : event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : null;
            if (targetIndex !== null) {
                event.preventDefault();
                options[targetIndex]?.focus();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeSelects();
                button.focus();
            }
        });

        select.classList.add('user-task-create__native-select');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        select.after(root);
        root.append(button, menu);
        customSelects.push({root, button, menu});
        select.addEventListener('change', sync);
        sync();
    };

    form.querySelectorAll('select').forEach(enhanceSelect);

    const project = form.querySelector('[data-user-task-project]');
    const newProject = form.querySelector('[data-user-task-new-project]');
    const projectName = form.elements.project_name;
    const errorBox = form.querySelector('[data-user-task-create-error]');
    const submit = form.querySelector('[type=submit]');
    const openButtons = document.querySelectorAll('[data-open-user-task-create]');
    const details = form.querySelector('[data-user-task-details]');
    const detailsList = form.querySelector('[data-user-task-details-list]');
    const detailTemplate = form.querySelector('[data-user-task-detail-template]');
    const startDate = form.querySelector('[data-user-task-start]');
    const dueDate = form.querySelector('[data-user-task-due]');

    const syncDateRange = () => {
        if (!startDate || !dueDate) return;
        dueDate.min = startDate.value;
        if (!startDate.value || !dueDate.value || dueDate.value >= startDate.value) return;

        /*
         * เลื่อนวันเริ่มข้ามกำหนดส่งแล้วต้องลากกำหนดส่งตามไปด้วย แต่ต้องรักษา "เวลา" ที่ผู้ใช้
         * ตั้งไว้ให้ได้ก่อน การคัดลอกค่าวันเริ่มมาทั้งก้อนจะทำให้เวลาเลิกงานที่ตั้งไว้หายไป
         * กลายเป็นเวลาเริ่มงาน ซึ่งไม่ใช่สิ่งที่ผู้ใช้สั่ง
         */
        const [startDay] = startDate.value.split('T');
        const [, dueClock = ''] = dueDate.value.split('T');
        const shifted = dueClock ? `${startDay}T${dueClock}` : startDay;
        dueDate.value = shifted >= startDate.value ? shifted : startDate.value;
    };

    const syncProjectMode = () => {
        const createsProject = !project.value;
        newProject.hidden = !createsProject;
        projectName.required = createsProject;
    };
    const close = () => {
        closeSelects();
        modal.hidden = true;
        errorBox.hidden = true;
    };
    const open = (listId = '') => {
        if (listId && [...project.options].some((option) => option.value === String(listId))) {
            project.value = String(listId);
        }
        modal.hidden = false;
        syncProjectMode();
        form.elements.job_topic.focus();
    };

    openButtons.forEach((button) => {
        button.innerHTML = '<i class=\'bi bi-plus-lg\' aria-hidden=\'true\'></i> สร้างงาน';
        button.onclick = () => open();
    });
    document.addEventListener('mytasks:create-task', (event) => open(event.detail?.listId));
    modal.querySelectorAll('[data-close-user-task-create]').forEach((button) => button.addEventListener('click', close));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) close();
    });
    document.addEventListener('pointerdown', (event) => {
        if (!event.target.closest('.user-task-create__select')) closeSelects();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) close();
    });
    project.addEventListener('change', syncProjectMode);
    startDate?.addEventListener('change', syncDateRange);
    dueDate?.addEventListener('change', syncDateRange);
    details?.addEventListener('click', (event) => {
        const add = event.target.closest('[data-add-user-task-detail]');
        if (add && detailTemplate && detailsList) {
            const row = detailTemplate.content.firstElementChild.cloneNode(true);
            detailsList.append(row);
            row.querySelector('input')?.focus();
            return;
        }

        const remove = event.target.closest('[data-remove-user-task-detail]');
        if (!remove) return;
        remove.closest('[data-user-task-detail-row]')?.remove();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorBox.hidden = true;
        submit.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                },
                body: new FormData(form),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || 'สร้างงานไม่สำเร็จ');
            }

            /*
             * งานที่เพิ่งสร้างจะเห็นได้ชัดที่สุดในมุมมองบอร์ด เพราะบอร์ดจัดกลุ่มตามโปรเจกต์
             * ผู้ใช้จึงเห็นรายการใหม่อยู่ใต้โปรเจกต์ที่เพิ่งเลือกไปทันที
             * ของเดิม reload() ทิ้งไว้ที่มุมมองเดิม ซึ่งถ้าเป็นมุมมองปฏิทินหรือประชุม
             * ผู้ใช้จะไม่เห็นอะไรเปลี่ยนเลยหลังกดสร้าง
             *
             * ใช้ query string ตัวเดิมของหน้าต่อ เพื่อไม่ให้ตัวกรองขอบเขตงานที่เลือกไว้หายไป
             */
            const destination = new URL(window.location.href);
            destination.searchParams.set('view', 'board');
            window.location.assign(destination);
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.hidden = false;
            submit.disabled = false;
        }
    });

    syncProjectMode();
    syncDateRange();
}
