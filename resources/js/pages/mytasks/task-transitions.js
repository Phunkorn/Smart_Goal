/**
 * ป้ายบอก "ขั้นถัดไป" ของการ์ดบนบอร์ด
 *
 * ทั้งหมดคำนวณจาก allowed_statuses ที่ TaskStatusTransitionService ส่งมา
 * จึงเป็นความจริงชุดเดียวกับที่ server บังคับ ไม่มีการ hardcode สิทธิ์ซ้ำในฝั่งนี้
 */
export const STATUS_STEP_LABELS = {
    2: 'กลับมาทำ',
    3: 'ส่งตรวจสอบ',
    4: 'ปิดงาน',
    5: 'พักงาน',
};

/**
 * ลำดับ "ขั้นที่ควรแนะนำ" ของแต่ละสถานะ ตัวแรกที่ทำได้จริงคือคำแนะนำ
 * ไม่ใช่ลำดับตัวเลข เพราะจากพักงานควรชวนให้กลับมาทำก่อนส่งตรวจ
 */
const RECOMMENDED_NEXT = {
    2: [3, 4, 5],
    3: [4, 2],
    5: [2, 3, 4],
    6: [3, 4, 2],
    4: [2],
};

export function nextStepHint(currentStatus, capabilities = {}) {
    const current = Number(currentStatus);
    const allowed = Array.isArray(capabilities.allowed_statuses)
        ? capabilities.allowed_statuses.map(Number)
        : [];

    const target = (RECOMMENDED_NEXT[current] || []).find(
        (candidate) => candidate !== current && allowed.includes(candidate),
    );
    if (target === undefined) return null;

    // งานที่ปิดแล้วเปิดใหม่ได้เฉพาะผ่านคำสั่งในรายการงาน ไม่ใช่การลากการ์ด
    // จึงต้องบอกทั้งขั้นถัดไปและช่องทางที่ใช้จริง
    if (current === 4) return {status: target, label: 'เปิดงานอีกครั้ง', viaMenu: true};

    return {status: target, label: STATUS_STEP_LABELS[target] || 'เปลี่ยนสถานะ', viaMenu: false};
}

/**
 * เหตุผลที่การ์ดนี้ขยับไม่ได้ — คืน null เมื่อขยับได้
 * ต้องบอกให้ตรงเหตุ ไม่ใช่ปล่อยให้ผู้ใช้ลากแล้วเด้ง error เอาเอง
 */
export function lockReason(currentStatus, capabilities = {}) {
    if (nextStepHint(currentStatus, capabilities)) return null;

    if (capabilities.is_final === true) {
        return capabilities.can_reopen === true
            ? 'งานปิดแล้ว เปิดใหม่ได้จากเมนูในรายการงาน'
            : 'งานปิดแล้ว ผู้ดูแลระบบเท่านั้นที่แก้ไขได้';
    }
    if (capabilities.can_edit !== true) return 'คุณไม่มีสิทธิ์เปลี่ยนสถานะงานนี้';
    if (Number(currentStatus) === 3) return 'รอผู้มอบหมายตรวจสอบ';

    return 'ยังไม่มีขั้นตอนถัดไป';
}

export function transitionKind(currentStatus, targetStatus, capabilities = {}) {
    if (currentStatus === targetStatus) return 'none';
    if (currentStatus === 4 && targetStatus === 2 && capabilities.can_reopen) return 'reopen';
    if (currentStatus !== 4 && capabilities.can_admin_override) {
        return targetStatus === 4 ? 'admin-override-complete' : 'admin-override';
    }
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
        'admin-override': {title: 'ยืนยันการปรับสถานะโดยผู้ดูแล?', text: 'สถานะจะถูกปรับโดยตรงและบันทึกในประวัติงาน', confirmButtonText: 'ยืนยันการปรับสถานะ'},
        'admin-override-complete': {title: 'ยืนยันการปิดงานโดยผู้ดูแล?', text: 'งานจะถูกปิดโดยตรงและต้องใช้คำสั่งเปิดงานอีกครั้งหากต้องการแก้ไข', confirmButtonText: 'ยืนยันปิดงาน'},
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
