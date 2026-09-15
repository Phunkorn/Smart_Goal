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

/*
 * ?approval_queue= ถูกกรองที่ server แล้ว (AdminApprovalController) หน้าจอแสดงคิวเดียวอยู่แล้ว
 * สิ่งที่เหลือให้ฝั่ง client คือเลื่อนแถบแท็บให้แท็บที่เลือกอยู่ในสายตาบนจอแคบ
 */
document.querySelector('.admin-approvals-tabs [aria-current="page"]')
    ?.scrollIntoView({block: 'nearest', inline: 'center'});
