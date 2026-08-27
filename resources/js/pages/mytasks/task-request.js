(() => {
    const modal = document.querySelector('[data-project-task-request-modal]');
    const form = modal?.querySelector('[data-project-task-request-form]');
    if (!modal || !form) return;

    const projectName = modal.querySelector('[data-project-task-request-name]');
    const generalError = modal.querySelector('[data-project-task-request-general-error]');
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

            const field = form.elements.namedItem(name);
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
        form.elements.job_start_at.value = today;
        form.elements.job_due_at.value = tomorrow;

        Object.entries(values).forEach(([name, value]) => {
            const field = form.elements.namedItem(name);
            if (field && value !== null && value !== undefined) field.value = value;
        });

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
