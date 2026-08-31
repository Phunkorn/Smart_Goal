/**
 * โมดัล "สร้างโปรเจกต์และมอบหมายงาน" ของผู้ดูแลระบบ
 *
 * ใช้ร่วมกันระหว่าง Admin Board Overview และ Admin Member Workspace
 * ขั้นแรกสร้างโปรเจกต์ แล้วเพิ่มงานทีละรายการลงในโปรเจกต์ที่เลือก โดยใช้
 * event delegation ภายใน modal ชุดเดียว
 */

const MODAL_SELECTOR = '[data-admin-assignment-modal]';
const TASK_SELECTOR = '[data-admin-task]';
const ALLOWED_ATTACHMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
const MAX_ATTACHMENT_MB = 10;
const ASSIGNEE_PLACEHOLDER = 'เลือกผู้รับผิดชอบ...';
const COLLABORATOR_DEPARTMENT_HINT = 'กรุณาเลือกแผนกด้านบนก่อน จึงจะเลือกผู้ร่วมงานในแผนกนั้นได้';
const COLLABORATOR_SEARCH_HINT = 'ไม่พบพนักงานในแผนกนี้ที่ตรงกับคำค้นหา';

const hideDropdown = (toggle) => {
    if (toggle) {
        globalThis.bootstrap?.Dropdown?.getOrCreateInstance(toggle)?.hide();
    }
};

const warn = (message) => {
    // CLAUDE.md ห้ามใช้ alert()/confirm() ให้ใช้ SweetAlert2 ที่ layout โหลดไว้แล้ว
    if (globalThis.Swal?.fire) {
        globalThis.Swal.fire({icon: 'warning', title: message, confirmButtonText: 'ตกลง'});
    }
};

export function applyAssignee(task, option, {closeDropdown = true} = {}) {
    if (!task || !option) {
        return;
    }

    task.querySelectorAll('.assignee-option').forEach((item) => item.classList.toggle('active', item === option));

    const field = task.querySelector('[data-task-assignee]');
    if (field) {
        field.value = option.dataset.id || '';
    }

    const label = task.querySelector('.assignee-picker-label');
    if (label) {
        label.textContent = `${option.dataset.name} — ${option.dataset.dept}`;
        label.classList.remove('text-muted');
    }

    if (closeDropdown) {
        hideDropdown(task.querySelector('.assignee-picker-toggle'));
    }
}

export function applyAssigneeById(task, assigneeId) {
    if (!task || !assigneeId) {
        return false;
    }

    const option = Array.from(task.querySelectorAll('.assignee-option'))
        .find((item) => item.dataset.id === String(assigneeId));

    if (!option) {
        return false;
    }

    applyAssignee(task, option, {closeDropdown: false});

    return true;
}

function clearAssignee(task) {
    const label = task.querySelector('.assignee-picker-label');
    if (label) {
        label.textContent = ASSIGNEE_PLACEHOLDER;
        label.classList.add('text-muted');
    }

    const field = task.querySelector('[data-task-assignee]');
    if (field) {
        field.value = '';
    }

    task.querySelectorAll('.assignee-option').forEach((option) => option.classList.remove('active', 'd-none'));
    task.querySelector('[data-task-assignee-empty]')?.classList.add('d-none');
}

export function filterAssignees(task) {
    const search = task.querySelector('[data-task-assignee-search]');
    const keyword = (search?.value || '').trim().toLowerCase();
    let visibleCount = 0;

    task.querySelectorAll('.assignee-option').forEach((option) => {
        const matches = (option.dataset.search || '').includes(keyword);
        option.classList.toggle('d-none', !matches);
        if (matches) {
            visibleCount += 1;
        }
    });

    task.querySelector('[data-task-assignee-empty]')?.classList.toggle('d-none', visibleCount !== 0);
}

export function applyCollaboratorFilter(task) {
    const departmentSelect = task.querySelector('[data-task-collaborator-department]');
    const search = task.querySelector('[data-task-collaborator-search]');
    const hint = task.querySelector('[data-task-collaborator-hint]');
    const items = Array.from(task.querySelectorAll('.board-collab-item'));

    if (!departmentSelect) {
        return;
    }

    const departmentId = departmentSelect.value;

    if (!departmentId) {
        // ยังไม่ได้เลือกแผนก: ซ่อนรายชื่อทั้งหมด บังคับให้เลือกแผนกก่อน
        items.forEach((item) => item.classList.add('d-none'));
        if (search) {
            search.classList.add('d-none');
            search.disabled = true;
            search.value = '';
        }
        if (hint) {
            hint.classList.remove('d-none');
            hint.textContent = COLLABORATOR_DEPARTMENT_HINT;
        }

        return;
    }

    if (search) {
        search.classList.remove('d-none');
        search.disabled = false;
    }

    const keyword = (search?.value || '').trim().toLowerCase();
    let visibleCount = 0;

    items.forEach((item) => {
        const shouldShow = item.dataset.departmentId === departmentId
            && (!keyword || (item.dataset.search || '').includes(keyword));
        item.classList.toggle('d-none', !shouldShow);
        if (shouldShow) {
            visibleCount += 1;
        }
    });

    if (hint) {
        hint.classList.toggle('d-none', visibleCount !== 0);
        hint.textContent = COLLABORATOR_SEARCH_HINT;
    }
}

export function validateAttachments(input) {
    const errorBox = input.closest('[data-admin-task]')?.querySelector('[data-task-attachments-error]');
    const invalidFiles = [];

    Array.from(input.files || []).forEach((file) => {
        const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';

        if (!ALLOWED_ATTACHMENT_EXTENSIONS.includes(extension)) {
            invalidFiles.push(`${file.name} (ไม่ใช่ประเภทไฟล์ที่อนุญาต)`);
        } else if (file.size > MAX_ATTACHMENT_MB * 1024 * 1024) {
            invalidFiles.push(`${file.name} (ขนาดเกิน ${MAX_ATTACHMENT_MB} MB)`);
        }
    });

    if (invalidFiles.length > 0) {
        input.value = '';
        if (errorBox) {
            errorBox.textContent = `ไม่สามารถแนบไฟล์ต่อไปนี้ได้: ${invalidFiles.join(', ')}`;
            errorBox.classList.remove('d-none');
        }

        return false;
    }

    if (errorBox) {
        errorBox.classList.add('d-none');
        errorBox.textContent = '';
    }

    return true;
}

export function initializeAdminAssignment(root = document, options = {}) {
    const scope = root.querySelector?.(MODAL_SELECTOR) ?? (root.matches?.(MODAL_SELECTOR) ? root : null);
    const modal = scope;

    if (!modal || modal.dataset.adminAssignmentReady === 'true') {
        return null;
    }

    const projectForm = modal.querySelector('[data-admin-project-form]');
    const taskForm = modal.querySelector('[data-admin-task-form]');
    const task = taskForm?.querySelector(TASK_SELECTOR);
    const projectSelect = taskForm?.querySelector('[data-project-select]');
    const projectIdField = taskForm?.querySelector('[data-selected-project-id]');
    const errorBox = modal.querySelector('[data-admin-assignment-errors]');

    if (!projectForm || !taskForm || !task || !projectSelect || !projectIdField) {
        return null;
    }

    const documentRoot = modal.ownerDocument ?? document;
    const defaultAssigneeId = modal.dataset.defaultAssigneeId || '';
    const preselectAssigneeId = modal.dataset.preselectAssigneeId || '';
    const request = options.fetch ?? globalThis.fetch?.bind(globalThis);
    let currentStep = modal.dataset.initialStep === 'task' ? 'task' : 'project';

    const showError = (message = '') => {
        if (!errorBox) return;
        errorBox.textContent = message;
        errorBox.hidden = !message;
    };

    const notify = (message) => {
        if (options.notify) return options.notify(message);
        globalThis.Swal?.fire?.({toast: true, position: 'top-end', icon: 'success', title: message, showConfirmButton: false, timer: 2200});
    };

    const setStep = (step, {focus = true} = {}) => {
        currentStep = step === 'task' ? 'task' : 'project';
        projectForm.hidden = currentStep !== 'project';
        taskForm.hidden = currentStep !== 'task';
        modal.querySelectorAll('[data-step-indicator]').forEach((indicator) => {
            const active = indicator.dataset.stepIndicator === currentStep;
            indicator.classList.toggle('is-active', active);
            indicator.setAttribute('aria-current', active ? 'step' : 'false');
        });
        const title = modal.querySelector('[data-assignment-title]');
        const subtitle = modal.querySelector('[data-assignment-subtitle]');
        if (title) title.textContent = currentStep === 'project' ? 'สร้างโปรเจกต์' : 'เพิ่มและมอบหมายรายการงาน';
        if (subtitle) subtitle.textContent = currentStep === 'project'
            ? 'สร้างพื้นที่โปรเจกต์ก่อน แล้วจึงเพิ่มและมอบหมายรายการงาน'
            : 'เพิ่มงานทีละรายการ พร้อมกำหนดผู้รับผิดชอบและกำหนดส่ง';
        showError();
        if (focus) {
            (currentStep === 'project'
                ? projectForm.querySelector('[name="project_name"]')
                : taskForm.querySelector('[name="job_topic"]'))?.focus();
        }
    };

    const setProject = (id, name = '') => {
        const value = String(id || '');
        if (value && !Array.from(projectSelect.options).some((option) => option.value === value)) {
            const option = documentRoot.createElement('option');
            option.value = value;
            option.textContent = name || `โปรเจกต์ #${value}`;
            projectSelect.add(option);
        }
        projectSelect.value = value;
        projectIdField.value = value;
    };

    const resetTaskFields = () => {
        taskForm.querySelector('[name="job_topic"]').value = '';
        taskForm.querySelector('[name="job_details"]').value = '';
        taskForm.querySelector('[name="job_priority"]').value = '2';
        taskForm.querySelectorAll('[name="collaborators[]"]').forEach((field) => { field.checked = false; });
        const attachments = taskForm.querySelector('[data-task-attachments]');
        if (attachments) attachments.value = '';
        const department = taskForm.querySelector('[data-task-collaborator-department]');
        if (department) department.value = '';
        applyCollaboratorFilter(task);
        task.querySelector('[data-task-attachments-error]')?.classList.add('d-none');
        applyAssigneeById(task, defaultAssigneeId || preselectAssigneeId);
        taskForm.querySelector('[name="job_topic"]')?.focus();
    };

    const submit = async (form) => {
        if (!request) throw new Error('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้');
        const response = await request(form.action, {
            method: 'POST',
            headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: new form.ownerDocument.defaultView.FormData(form),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const firstValidationMessage = Object.values(payload.errors || {}).flat()[0];
            throw new Error(firstValidationMessage || payload.message || 'บันทึกข้อมูลไม่สำเร็จ');
        }
        return payload;
    };

    const withBusyButton = async (button, callback) => {
        if (!button || button.disabled) return;
        button.disabled = true;
        button.classList.add('is-loading');
        try { await callback(); } finally { button.disabled = false; button.classList.remove('is-loading'); }
    };

    const handleClick = (event) => {
        if (event.target.closest('[data-create-another-project], [data-back-to-project]')) {
            setStep('project');
            return;
        }

        const assigneeOption = event.target.closest('.assignee-option');
        if (assigneeOption) {
            applyAssignee(assigneeOption.closest(TASK_SELECTOR), assigneeOption);

            return;
        }

    };

    const handleInput = (event) => {
        if (event.target.matches('[data-task-assignee-search]')) {
            filterAssignees(event.target.closest(TASK_SELECTOR));
        }

        if (event.target.matches('[data-task-collaborator-search]')) {
            applyCollaboratorFilter(event.target.closest(TASK_SELECTOR));
        }
    };

    const handleChange = (event) => {
        if (event.target.matches('[data-task-collaborator-department]')) {
            const task = event.target.closest(TASK_SELECTOR);
            const search = task.querySelector('[data-task-collaborator-search]');
            if (search) {
                search.value = '';
            }
            applyCollaboratorFilter(task);

            return;
        }

        if (event.target.matches('[data-task-attachments]')) {
            validateAttachments(event.target);
        }

        if (event.target.matches('[data-project-select]')) {
            setProject(event.target.value);
        }
    };

    const handleProjectSubmit = (event) => {
        event.preventDefault();
        const button = event.submitter || projectForm.querySelector('[data-project-submit]');
        withBusyButton(button, async () => {
            try {
                showError();
                const projectName = projectForm.querySelector('[name="project_name"]')?.value.trim();
                const payload = await submit(projectForm);
                setProject(payload.list_id, projectName);
                notify(payload.message || 'สร้างโปรเจกต์แล้ว');
                setStep('task');
            } catch (error) { showError(error.message); }
        });
    };

    const handleTaskSubmit = (event) => {
        event.preventDefault();
        const button = event.submitter || taskForm.querySelector('[data-task-submit="done"]');
        if (!projectIdField.value) return warn('กรุณาเลือกโปรเจกต์');
        if (!taskForm.querySelector('[data-task-assignee]')?.value) return warn('กรุณาเลือกผู้รับผิดชอบ');

        withBusyButton(button, async () => {
            try {
                showError();
                const payload = await submit(taskForm);
                notify(payload.message || 'มอบหมายงานแล้ว');
                if (button?.dataset.taskSubmit === 'next') {
                    resetTaskFields();
                } else if (options.onDone) {
                    options.onDone(payload);
                } else {
                    globalThis.location?.reload?.();
                }
            } catch (error) { showError(error.message); }
        });
    };

    const handleTriggerClick = (event) => {
        if (event.target.closest('[data-open-admin-assignment]')) {
            setStep(modal.dataset.initialStep || currentStep, {focus: false});
            globalThis.bootstrap?.Modal?.getOrCreateInstance(modal)?.show();
        }
    };

    const handleShown = () => {
        (currentStep === 'project'
            ? projectForm.querySelector('[name="project_name"]')
            : taskForm.querySelector('[name="job_topic"]'))?.focus();
    };

    taskForm.addEventListener('click', handleClick);
    taskForm.addEventListener('input', handleInput);
    taskForm.addEventListener('change', handleChange);
    projectForm.addEventListener('submit', handleProjectSubmit);
    taskForm.addEventListener('submit', handleTaskSubmit);
    documentRoot.addEventListener('click', handleTriggerClick);
    modal.addEventListener('shown.bs.modal', handleShown);

    const assigneeId = task.querySelector('[data-task-assignee]')?.value || defaultAssigneeId || preselectAssigneeId;
    if (!applyAssigneeById(task, assigneeId)) clearAssignee(task);
    setProject(projectSelect.value || projectIdField.value);
    applyCollaboratorFilter(task);
    setStep(currentStep, {focus: false});

    modal.dataset.adminAssignmentReady = 'true';

    if (modal.dataset.openOnLoad === '1' || options.open) {
        globalThis.bootstrap?.Modal?.getOrCreateInstance(modal)?.show();
    }

    return {
        modal,
        projectForm,
        taskForm,
        setStep,
        destroy() {
            taskForm.removeEventListener('click', handleClick);
            taskForm.removeEventListener('input', handleInput);
            taskForm.removeEventListener('change', handleChange);
            projectForm.removeEventListener('submit', handleProjectSubmit);
            taskForm.removeEventListener('submit', handleTaskSubmit);
            documentRoot.removeEventListener('click', handleTriggerClick);
            modal.removeEventListener('shown.bs.modal', handleShown);
            delete modal.dataset.adminAssignmentReady;
        },
    };
}

const boot = () => {
    document.querySelectorAll(MODAL_SELECTOR).forEach((modal) => initializeAdminAssignment(modal));
};

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once: true});
    } else {
        boot();
    }
}
