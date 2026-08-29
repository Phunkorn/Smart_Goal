document.querySelectorAll('[data-approval-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const approved = ['approved', 'accepted'].includes(form.dataset.decision);
        const collaborator = form.dataset.approvalKind === 'collaborator';
        const topic = form.dataset.topic || 'งานนี้';
        const result = await window.Swal.fire({
            icon: approved ? 'question' : 'warning',
            title: approved
                ? (collaborator ? 'อนุมัติผู้ร่วมงาน?' : 'อนุมัติการมอบหมายงาน?')
                : (collaborator ? 'ปฏิเสธผู้ร่วมงาน?' : 'ปฏิเสธการมอบหมายงาน?'),
            text: `${topic} — การตัดสินใจนี้จะเปลี่ยนสถานะคำขอทันที`,
            showCancelButton: true,
            confirmButtonText: approved ? 'อนุมัติ' : 'ปฏิเสธ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: approved ? '#198754' : '#dc3545',
            reverseButtons: true,
        });

        if (result.isConfirmed) {
            form.submit();
        }
    });
});

const requestedQueue = new URLSearchParams(window.location.search).get('approval_queue');
document.getElementById(
    requestedQueue === 'collaborator'
        ? 'collaborator-approval-queue'
        : requestedQueue === 'assignment'
            ? 'assignment-approval-queue'
            : ''
)?.scrollIntoView({behavior: 'smooth', block: 'start'});
