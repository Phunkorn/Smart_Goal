export const normalizeEmployeeSearch = (value) => String(value ?? '').trim().toLocaleLowerCase('th');

export const employeeMatchesSearch = (searchText, query) => (
    normalizeEmployeeSearch(searchText).includes(normalizeEmployeeSearch(query))
);

export const formatTemporaryPassword = (word, number) => (
    `${word}!${String(number).padStart(5, '0').slice(-5)}`
);

const randomTemporaryPassword = () => {
    const words = ['SmartGoal', 'PremiumCare', 'SecureTeam'];
    const randomValues = crypto.getRandomValues(new Uint32Array(2));
    const word = words[randomValues[0] % words.length];

    return formatTemporaryPassword(word, randomValues[1] % 100000);
};

const filterEmployees = (page, query) => {
    const cards = [...page.querySelectorAll('[data-employee-card]')];
    let visibleCount = 0;
    const roleCounts = {admin: 0, viewer: 0, user: 0};

    cards.forEach((card) => {
        const isVisible = employeeMatchesSearch(card.dataset.search, query);
        card.hidden = ! isVisible;
        if (isVisible) {
            visibleCount += 1;
            if (card.dataset.employeeRole in roleCounts) roleCounts[card.dataset.employeeRole] += 1;
        }
    });

    const count = page.querySelector('[data-employee-visible-count]');
    const empty = page.querySelector('[data-employee-search-empty]');

    if (count) count.textContent = String(visibleCount);
    if (empty) empty.hidden = visibleCount > 0 || cards.length === 0;

    page.querySelectorAll('[data-employee-summary-count]').forEach((summary) => {
        const role = summary.dataset.employeeSummaryCount;
        summary.textContent = String(role === 'all' ? visibleCount : (roleCounts[role] ?? 0));
    });
};

const syncRoleFields = (form) => {
    const role = form.querySelector('[data-user-role]');
    const department = form.querySelector('[data-user-department]');
    if (! role || ! department) return;

    const needsDepartment = role.value === 'user';
    department.disabled = ! needsDepartment;
    department.required = needsDepartment;
    if (! needsDepartment) department.value = '';
};

const initializeEmployeeForms = (page) => {
    document.querySelectorAll('[data-employee-form]').forEach((form) => {
        syncRoleFields(form);
        form.querySelector('[data-user-role]')?.addEventListener('change', () => syncRoleFields(form));

        form.querySelector('[data-profile-input]')?.addEventListener('change', (event) => {
            const preview = form.querySelector('[data-profile-preview]');
            const file = event.currentTarget.files?.[0];
            if (! preview || ! file) {
                preview?.classList.remove('is-visible');
                return;
            }

            const objectUrl = URL.createObjectURL(file);
            preview.addEventListener('load', () => URL.revokeObjectURL(objectUrl), {once: true});
            preview.src = objectUrl;
            preview.classList.add('is-visible');
        });
    });

    page.querySelector('[data-employee-search]')?.addEventListener('input', (event) => {
        filterEmployees(page, event.currentTarget.value);
    });
};

const initializePasswordGenerators = () => {
    document.querySelectorAll('[data-generate-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(`resetPasswordInput${button.dataset.generatePassword}`);
            if (input) input.value = randomTemporaryPassword();
        });
    });
};

const initializeDeleteConfirmation = (page) => {
    page.querySelectorAll('.employee-delete-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (! window.Swal) return;

            const result = await window.Swal.fire({
                icon: 'warning',
                title: 'ลบพนักงานคนนี้หรือไม่?',
                text: `บัญชี “${form.dataset.employeeName}” จะถูกนำออกจากระบบ`,
                showCancelButton: true,
                confirmButtonText: 'ลบพนักงาน',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
            });

            if (result.isConfirmed) form.submit();
        });
    });
};

const showFeedback = (page) => {
    if (! window.Swal) return;

    if (page.dataset.successMessage) {
        window.Swal.fire({
            icon: 'success',
            title: 'สำเร็จ',
            text: page.dataset.successMessage,
            confirmButtonText: 'ตกลง',
        });
        return;
    }

    if (page.dataset.errorMessage) {
        window.Swal.fire({
            icon: 'error',
            title: 'ไม่สามารถบันทึกได้',
            text: page.dataset.errorMessage,
            confirmButtonText: 'ตรวจสอบอีกครั้ง',
        });
    }
};

const reopenInvalidForm = (page) => {
    const modalId = page.dataset.openModal;
    if (! modalId) return;

    const modalElement = document.getElementById(modalId);
    if (! modalElement || ! window.bootstrap?.Modal) return;

    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
};

export const initializeEmployeePage = () => {
    const page = document.querySelector('[data-employee-page]');
    if (! page) return;

    initializeEmployeeForms(page);
    initializePasswordGenerators();
    initializeDeleteConfirmation(page);
    showFeedback(page);
    reopenInvalidForm(page);
};

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeEmployeePage, {once: true});
    } else {
        initializeEmployeePage();
    }
}
