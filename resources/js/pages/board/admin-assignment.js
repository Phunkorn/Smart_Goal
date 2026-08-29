/**
 * โมดัล "สร้างโปรเจกต์และมอบหมายงาน" ของผู้ดูแลระบบ
 *
 * ใช้ร่วมกันระหว่าง Admin Board Overview และ Admin Member Workspace
 * โดยผูกพฤติกรรมทั้งหมดแบบ event delegation ที่ฟอร์มเดียว จึงรองรับทั้งงานที่
 * เซิร์ฟเวอร์ render มา (รวมกรณี old input) และงานที่ผู้ใช้กดเพิ่มใน Modal
 */

const MODAL_SELECTOR = '[data-admin-assignment-modal]';
const TASK_SELECTOR = '[data-admin-task]';
const ALLOWED_ATTACHMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
const MAX_ATTACHMENT_MB = 10;
const ASSIGNEE_PLACEHOLDER = 'เลือกผู้รับผิดชอบ...';
const COLLABORATOR_DEPARTMENT_HINT = 'กรุณาเลือกแผนกด้านบนก่อน จึงจะเลือกผู้ร่วมงานในแผนกนั้นได้';
const COLLABORATOR_SEARCH_HINT = 'ไม่พบพนักงานในแผนกนี้ที่ตรงกับคำค้นหา';

const PRIORITY_TONES = {
    red: {text: 'var(--board-red)', soft: 'var(--board-red-soft)'},
    amber: {text: 'var(--board-amber)', soft: 'var(--board-amber-soft)'},
    gray: {text: '#475467', soft: 'var(--board-gray-soft)'},
};

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

export function setTaskPriority(task, option) {
    if (!task || !option) {
        return;
    }

    const value = option.dataset.value || '2';
    const tone = option.dataset.tone || 'amber';
    const colors = PRIORITY_TONES[tone] || PRIORITY_TONES.amber;
    const toggle = task.querySelector('.priority-picker-toggle');
    const label = task.querySelector('.priority-picker-label');
    const field = task.querySelector('[data-task-priority]');

    if (field) {
        field.value = value;
    }

    task.querySelectorAll('.priority-option').forEach((item) => item.classList.toggle('active', item === option));

    if (label) {
        label.classList.remove('text-muted');
        label.innerHTML = `<span class="priority-dot tone-dot-${tone}"></span><span>${option.dataset.label || ''}</span>`;
    }

    if (toggle) {
        toggle.style.borderColor = colors.text;
        toggle.style.background = colors.soft;
        toggle.style.color = colors.text;
        toggle.style.fontWeight = '600';
    }
}

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

/** old input เก็บเฉพาะ id ผู้ร่วมงาน จึงต้องย้อนหาแผนกเพื่อให้รายชื่อที่ติ๊กไว้มองเห็นได้อีกครั้ง */
function restoreCollaboratorDepartment(task) {
    const departmentSelect = task.querySelector('[data-task-collaborator-department]');
    if (!departmentSelect || departmentSelect.value) {
        return;
    }

    const checked = task.querySelector('.board-collab-item input[type="checkbox"]:checked');
    const departmentId = checked?.closest('.board-collab-item')?.dataset.departmentId;

    if (departmentId) {
        departmentSelect.value = departmentId;
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

    const form = modal.querySelector('[data-admin-project-form]');
    const taskList = form?.querySelector('[data-admin-task-list]');
    const addTaskButton = form?.querySelector('[data-add-admin-task]');

    if (!form || !taskList || !addTaskButton) {
        return null;
    }

    const taskTemplate = taskList.querySelector(TASK_SELECTOR)?.cloneNode(true);

    if (!taskTemplate) {
        return null;
    }

    const documentRoot = modal.ownerDocument ?? document;
    const defaultAssigneeId = modal.dataset.defaultAssigneeId || '';
    const preselectAssigneeId = modal.dataset.preselectAssigneeId || '';

    const reindexSubtasks = (task) => {
        task.querySelectorAll('.board-project-subtask').forEach((row, subtaskIndex) => {
            row.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace(/\[subtasks\]\[\d+\]/, `[subtasks][${subtaskIndex}]`);
            });
        });
    };

    const reindexTasks = () => {
        const tasks = Array.from(taskList.querySelectorAll(TASK_SELECTOR));
        tasks.forEach((task, taskIndex) => {
            task.dataset.taskIndex = taskIndex;
            const title = task.querySelector('[data-task-title]');
            if (title) {
                title.textContent = `งานที่ ${taskIndex + 1}`;
            }
            task.querySelector('[data-remove-admin-task]')?.classList.toggle('d-none', tasks.length === 1);
            task.querySelectorAll('[name]').forEach((field) => {
                field.name = field.name.replace(/tasks\[\d+\]/, `tasks[${taskIndex}]`);
            });
            reindexSubtasks(task);
        });
    };

    const resetTask = (task) => {
        task.querySelectorAll('input, textarea, select').forEach((field) => {
            if (field.type === 'checkbox' || field.type === 'radio') {
                field.checked = false;
            } else if (field.type === 'file') {
                field.value = '';
            } else if (field.matches('[data-task-priority]')) {
                field.value = '2';
            } else {
                field.value = '';
            }
        });

        clearAssignee(task);
        setTaskPriority(task, task.querySelector('.priority-option[data-value="2"]'));
        applyCollaboratorFilter(task);
        task.querySelector('[data-task-attachments-error]')?.classList.add('d-none');

        const subtaskList = task.querySelector('[data-admin-subtask-list]');
        if (subtaskList) {
            subtaskList.innerHTML = '';
        }
    };

    const addTask = () => {
        const task = taskTemplate.cloneNode(true);
        resetTask(task);
        taskList.appendChild(task);
        reindexTasks();

        // งานที่เพิ่มใหม่จาก Member Workspace ต้องตั้งต้นที่สมาชิกคนเดิม แต่ Admin ยังเปลี่ยนได้
        applyAssigneeById(task, defaultAssigneeId);

        return task;
    };

    const handleClick = (event) => {
        const removeTask = event.target.closest('[data-remove-admin-task]');
        if (removeTask && taskList.querySelectorAll(TASK_SELECTOR).length > 1) {
            removeTask.closest(TASK_SELECTOR).remove();
            reindexTasks();

            return;
        }

        const assigneeOption = event.target.closest('.assignee-option');
        if (assigneeOption) {
            applyAssignee(assigneeOption.closest(TASK_SELECTOR), assigneeOption);

            return;
        }

        const priorityOption = event.target.closest('.priority-option');
        if (priorityOption) {
            setTaskPriority(priorityOption.closest(TASK_SELECTOR), priorityOption);
            hideDropdown(priorityOption.closest(TASK_SELECTOR)?.querySelector('.priority-picker-toggle'));
        }

        const addSubtask = event.target.closest('[data-add-admin-subtask]');
        if (addSubtask) {
            const task = addSubtask.closest(TASK_SELECTOR);
            const container = task.querySelector('[data-admin-subtask-list]');
            const taskIndex = Number(task.dataset.taskIndex);
            const subtaskIndex = container.children.length;
            const row = documentRoot.createElement('div');
            row.className = 'board-project-subtask row g-2 mb-2';
            row.innerHTML = `<div class="col-md-5"><input class="form-control" name="tasks[${taskIndex}][subtasks][${subtaskIndex}][title]" maxlength="255" placeholder="ชื่องานย่อย"></div><div class="col-md-6"><input class="form-control" name="tasks[${taskIndex}][subtasks][${subtaskIndex}][details]" maxlength="2000" placeholder="รายละเอียด"></div><div class="col-md-1 d-grid"><button type="button" class="btn btn-outline-danger" data-remove-admin-subtask aria-label="ลบงานย่อย"><i class="bi bi-x-lg"></i></button></div>`;
            container.appendChild(row);
            reindexSubtasks(task);

            return;
        }

        const removeSubtask = event.target.closest('[data-remove-admin-subtask]');
        if (removeSubtask) {
            const task = removeSubtask.closest(TASK_SELECTOR);
            removeSubtask.closest('.board-project-subtask').remove();
            reindexSubtasks(task);
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
    };

    const handleSubmit = (event) => {
        const missingAssignee = Array.from(form.querySelectorAll('[data-task-assignee]')).some((field) => !field.value);

        if (missingAssignee) {
            event.preventDefault();
            warn('กรุณาเลือกผู้รับผิดชอบให้ครบทุกงาน');
        }
    };

    const handleTriggerClick = (event) => {
        if (event.target.closest('[data-open-admin-assignment]')) {
            globalThis.bootstrap?.Modal?.getOrCreateInstance(modal)?.show();
        }
    };

    addTaskButton.addEventListener('click', addTask);
    form.addEventListener('click', handleClick);
    form.addEventListener('input', handleInput);
    form.addEventListener('change', handleChange);
    form.addEventListener('submit', handleSubmit);
    documentRoot.addEventListener('click', handleTriggerClick);

    // สถานะเริ่มต้นของทุกงานที่เซิร์ฟเวอร์ render มา รวมกรณีที่ validation ไม่ผ่านแล้วมี old input
    taskList.querySelectorAll(TASK_SELECTOR).forEach((task, taskIndex) => {
        const priorityValue = task.querySelector('[data-task-priority]')?.value || '2';
        setTaskPriority(task, task.querySelector(`.priority-option[data-value="${priorityValue}"]`));

        const assigneeId = task.querySelector('[data-task-assignee]')?.value
            || defaultAssigneeId
            || (taskIndex === 0 ? preselectAssigneeId : '');
        if (!applyAssigneeById(task, assigneeId)) {
            clearAssignee(task);
        }

        restoreCollaboratorDepartment(task);
        applyCollaboratorFilter(task);
    });
    reindexTasks();

    modal.dataset.adminAssignmentReady = 'true';

    if (modal.dataset.openOnLoad === '1' || options.open) {
        globalThis.bootstrap?.Modal?.getOrCreateInstance(modal)?.show();
    }

    return {
        modal,
        form,
        addTask,
        destroy() {
            addTaskButton.removeEventListener('click', addTask);
            form.removeEventListener('click', handleClick);
            form.removeEventListener('input', handleInput);
            form.removeEventListener('change', handleChange);
            form.removeEventListener('submit', handleSubmit);
            documentRoot.removeEventListener('click', handleTriggerClick);
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
