export function transitionKind(currentStatus, targetStatus, capabilities = {}) {
    if (currentStatus === targetStatus) return 'none';
    if (currentStatus === 4 && targetStatus === 2 && capabilities.can_reopen) return 'reopen';
    if (currentStatus === 3 && targetStatus === 2 && capabilities.can_review) return 'return';
    if (currentStatus === 3 && targetStatus === 4 && capabilities.can_review) return 'approve';
    if (targetStatus === 3 && capabilities.can_submit_review) return 'submit';
    if (targetStatus === 4 && capabilities.can_self_close) return 'self-close';
    return 'standard';
}

export function canTransitionTo(currentStatus, targetStatus, capabilities = {}) {
    const allowed = capabilities.allowed_statuses;
    if (!Array.isArray(allowed)) return Number(currentStatus) === Number(targetStatus) || capabilities.can_edit === true;

    return allowed.map(Number).includes(Number(targetStatus));
}

export function canDragTask(currentStatus, capabilities = {}) {
    return capabilities.can_edit === true
        && capabilities.is_final !== true
        && Array.isArray(capabilities.allowed_statuses)
        && capabilities.allowed_statuses.some((status) => Number(status) !== Number(currentStatus));
}

export function isModalStatusOptionDisabled(currentStatus, optionStatus, capabilities = {}) {
    if (capabilities.is_final === true) return true;
    if (Array.isArray(capabilities.allowed_statuses)) {
        return !canTransitionTo(currentStatus, optionStatus, capabilities);
    }

    if (Number(currentStatus) !== 6) return false;

    const allowed = [6];
    if (capabilities.can_submit_review) allowed.push(3);
    if (capabilities.can_self_close) allowed.push(4);

    return !allowed.includes(Number(optionStatus));
}

export async function confirmTaskTransition(currentStatus, targetStatus, capabilities = {}) {
    const kind = transitionKind(Number(currentStatus), Number(targetStatus), capabilities);
    if (kind === 'none') return {job_status: Number(targetStatus)};
    if (kind === 'standard') return {job_status: Number(targetStatus)};

    const config = {
        submit: {title: 'ส่งงานเพื่อตรวจสอบ?', text: 'คุณยืนยันว่าดำเนินการงานนี้เสร็จแล้ว และต้องการส่งให้ผู้มอบหมายตรวจสอบหรือไม่?', confirmButtonText: 'ส่งตรวจ'},
        approve: {title: 'ยืนยันปิดงาน?', text: 'คุณได้ตรวจสอบงานแล้วและยืนยันว่างานนี้เสร็จสมบูรณ์ใช่หรือไม่? หลังปิดงาน ผู้ใช้งานทั่วไปจะไม่สามารถแก้ไขงานนี้ได้', confirmButtonText: 'ยืนยันปิดงาน'},
        'self-close': {title: 'ยืนยันปิดงานนี้หรือไม่?', text: 'หลังปิดงาน งานนี้จะเป็นสถานะสุดท้ายและไม่สามารถแก้ไขได้', confirmButtonText: 'ยืนยันปิดงาน'},
        reopen: {title: 'ต้องการเปิดงานนี้อีกครั้งหรือไม่?', text: 'งานจะกลับเข้าสู่สถานะกำลังทำ', confirmButtonText: 'เปิดงานอีกครั้ง'},
    };

    if (kind === 'return') {
        const result = await window.Swal.fire({
            icon: 'warning', title: 'ส่งกลับแก้ไข', input: 'textarea', inputLabel: 'เหตุผลที่ต้องแก้ไข',
            inputPlaceholder: 'ระบุสิ่งที่ต้องการให้แก้ไข', inputAttributes: {maxlength: '1000'},
            inputValidator: (value) => value?.trim() ? undefined : 'กรุณาระบุเหตุผลที่ส่งงานกลับแก้ไข',
            showCancelButton: true, confirmButtonText: 'ส่งกลับแก้ไข', cancelButtonText: 'ยกเลิก', reverseButtons: true,
        });
        return result.isConfirmed ? {job_status: 2, reason: result.value.trim()} : null;
    }

    const result = await window.Swal.fire({icon: 'question', ...config[kind], showCancelButton: true, cancelButtonText: 'ยกเลิก', reverseButtons: true});
    return result.isConfirmed ? {job_status: Number(targetStatus), ...(kind === 'reopen' ? {action: 'reopen'} : {})} : null;
}
