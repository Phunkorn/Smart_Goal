import {initialTimeFor, joinDateTimeValue} from '../../components/date-picker.js';

/**
 * การแจ้งเตือน "มีคำขอเพิ่มงาน" พามาที่ ?task_request=<id> ซึ่ง server เปิดแผงคำขอ
 * และไฮไลต์การ์ดไว้แล้ว แต่ถ้าโปรเจกต์อยู่ท้ายบอร์ด ผู้ใช้ต้องเลื่อนหาเอง
 * จึงเลื่อนการ์ดที่ถูกขอมาให้อยู่กลางจอทันที ไม่ขึ้นกับว่าหน้านี้มีโมดัลขอเพิ่มงานหรือไม่
 */
export const revealRequestedTaskRequest = (root = document) => {
    const target = root.querySelector('[data-project-task-request-target]');
    if (!target) return false;

    const panel = target.closest('details');
    if (panel) panel.open = true;
    target.scrollIntoView?.({block: 'center'});
    target.focus({preventScroll: true});

    return true;
};

revealRequestedTaskRequest();

(() => {
    const modal = document.querySelector('[data-project-task-request-modal]');
    const form = modal?.querySelector('[data-project-task-request-form]');
    if (!modal || !form) return;

    const projectName = modal.querySelector('[data-project-task-request-name]');
    const generalError = modal.querySelector('[data-project-task-request-general-error]');
    const parentField = modal.querySelector('[data-project-task-request-parent]');
    const parentSelect = modal.querySelector('[data-project-task-request-parent-select]');
    const parentEmpty = modal.querySelector('[data-project-task-request-parent-empty]');
    const feedbackNode = document.querySelector('[data-project-task-request-feedback]');
    const bangkokDate = (offset = 0) => {
        const parts = new Intl.DateTimeFormat('en', {
            timeZone: 'Asia/Bangkok',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).formatToParts(new Date(Date.now() + offset));
        const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}`;
    };
    const today = bangkokDate();
    const tomorrow = bangkokDate(86400000);
    let opener = null;

    const readParentTasks = (button) => {
        try {
            const tasks = JSON.parse(button?.dataset.parentTasks || '[]');
            return Array.isArray(tasks) ? tasks : [];
        } catch {
            return [];
        }
    };

    const populateParentTasks = (button) => {
        if (!parentSelect) return;
        const tasks = readParentTasks(button);
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'เลือกงานหลักที่ต้องการเพิ่มงานย่อย';
        parentSelect.replaceChildren(placeholder);
        tasks.forEach((task) => {
            const option = document.createElement('option');
            option.value = String(task.id);
            option.textContent = task.name;
            parentSelect.append(option);
        });
        if (parentEmpty) parentEmpty.hidden = tasks.length > 0;
    };

    const syncRequestType = () => {
        const isSubtask = form.elements.request_type?.value === 'subtask';
        if (parentField) parentField.hidden = !isSubtask;
        if (parentSelect) {
            parentSelect.required = isSubtask;
            parentSelect.disabled = !isSubtask;
            if (!isSubtask) parentSelect.value = '';
        }
    };

    const readFeedback = () => {
        try {
            return JSON.parse(feedbackNode?.textContent || '{}');
        } catch {
            return {};
        }
    };

    const clearErrors = () => {
        form.querySelectorAll('.is-invalid').forEach((field) => {
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
        });
        form.querySelectorAll('[data-project-task-request-error]').forEach((node) => {
            node.textContent = '';
        });
        if (generalError) {
            generalError.textContent = '';
            generalError.hidden = true;
        }
    };

    const applyErrors = (errors = {}) => {
        Object.entries(errors).forEach(([name, messages]) => {
            const message = Array.isArray(messages) ? messages[0] : messages;
            if (!message) return;

            if (name === 'task_request') {
                if (generalError) {
                    generalError.textContent = message;
                    generalError.hidden = false;
                }
                return;
            }

            const control = form.elements.namedItem(name);
            const field = control?.length && !control?.tagName ? control[0] : control;
            const error = form.querySelector(`[data-project-task-request-error="${name}"]`);
            field?.classList.add('is-invalid');
            field?.setAttribute('aria-invalid', 'true');
            if (error) error.textContent = message;
        });
    };

    const open = (button, values = {}, errors = {}) => {
        if (!button) return false;

        opener = button;
        form.reset();
        clearErrors();
        form.action = button.dataset.action;
        projectName.textContent = button.dataset.projectName || '';
        populateParentTasks(button);
        // ช่องเป็น datetime-local ซึ่งเบราว์เซอร์ทิ้งค่าแบบวันที่ล้วน (YYYY-MM-DD) ทันที
        // ช่องจึงว่างแล้ว required บล็อกการส่งฟอร์มเงียบ ๆ ต้องใส่เวลาที่ช่องประกาศไว้ด้วย
        form.elements.job_start_at.value = joinDateTimeValue(today, initialTimeFor(form.elements.job_start_at));
        form.elements.job_due_at.value = joinDateTimeValue(tomorrow, initialTimeFor(form.elements.job_due_at));

        Object.entries(values).forEach(([name, value]) => {
            const field = form.elements.namedItem(name);
            if (field && value !== null && value !== undefined) field.value = value;
        });

        syncRequestType();
        applyErrors(errors);
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        const firstInvalid = form.querySelector('.is-invalid');
        (firstInvalid || form.elements.job_topic).focus();

        return true;
    };

    const close = () => {
        modal.hidden = true;
        document.body.style.overflow = '';
        opener?.focus();
        opener = null;
    };

    document.querySelectorAll('[data-open-project-task-request]').forEach((button) => {
        button.addEventListener('click', () => open(button));
    });
    form.querySelectorAll('[name="request_type"]').forEach((field) => {
        field.addEventListener('change', syncRequestType);
    });

    modal.querySelectorAll('[data-close-project-task-request]').forEach((button) => button.addEventListener('click', close));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) close();
    });

    const feedback = readFeedback();
    if (feedback.open_modal) {
        const button = [...document.querySelectorAll('[data-open-project-task-request]')]
            .find((candidate) => String(candidate.dataset.listId) === String(feedback.list_id));
        open(button, feedback.old, feedback.errors);
    }

    if (feedback.success && window.Swal) {
        window.Swal.fire({icon: 'success', title: 'สำเร็จ', text: feedback.success, confirmButtonText: 'ตกลง'});
    } else if (feedback.error && window.Swal) {
        window.Swal.fire({icon: 'error', title: 'ดำเนินการไม่สำเร็จ', text: feedback.error, confirmButtonText: 'ตกลง'});
    }
})();
