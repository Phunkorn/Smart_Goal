import {useDatePickers} from '../../components/date-picker.js';

/*
 * ศูนย์ประกาศของหัวหน้าแผนก
 *
 * modal ฟอร์มใบเดียวใช้ทั้ง "สร้าง" และ "แก้ไข" ไฟล์นี้สลับ action, _method และค่าในช่อง
 * สิทธิ์จริงอยู่ที่ AnnouncementPolicy ฝั่ง server ปุ่มในหน้านี้เป็นแค่ทางเข้า
 */

/** อ่านข้อมูลประกาศจากปุ่มแก้ไข — คืน null เมื่อข้อมูลเสียหาย แทนที่จะโยน error ใส่หน้า */
export function readAnnouncement(button) {
    try {
        const parsed = JSON.parse(button?.dataset.announcement || 'null');

        return parsed && typeof parsed === 'object' && parsed.update_url ? parsed : null;
    } catch {
        return null;
    }
}

/** วันเริ่มที่เลือกได้ต่ำสุด — ตอนแก้ไขคงวันเดิมที่ผ่านมาแล้วไว้ได้ (ตรงกับกติกาใน AnnouncementController) */
export function earliestStart(today, startsOn = null) {
    return startsOn && startsOn < today ? startsOn : today;
}

function field(form, name) {
    return form.querySelector(`[data-announcement-field="${name}"]`);
}

function setAudience(form, value) {
    form.querySelectorAll('[data-announcement-field="audience"]').forEach((radio) => {
        radio.checked = radio.value === value;
    });
}

function clearErrors(modal) {
    modal.querySelector('.announcement-form__errors')?.remove();
    modal.querySelectorAll('.is-invalid').forEach((input) => input.classList.remove('is-invalid'));
}

/**
 * เตรียมฟอร์มตามโหมด — announcement เป็น null คือโหมดสร้าง
 */
export function fillAnnouncementForm(modal, announcement = null) {
    const form = modal.querySelector('[data-announcement-form]');
    const today = modal.dataset.today;
    const isEdit = announcement !== null;
    const method = form.querySelector('[data-announcement-method]');
    const startsOn = field(form, 'starts_on');
    const endsOn = field(form, 'ends_on');

    clearErrors(modal);
    form.action = isEdit ? announcement.update_url : modal.dataset.storeUrl;
    // ช่อง _method ที่ disabled จะไม่ถูกส่ง โหมดสร้างจึงเป็น POST ธรรมดา
    method.disabled = ! isEdit;
    form.querySelector('[data-announcement-id]').value = isEdit ? String(announcement.id) : '';

    field(form, 'title').value = isEdit ? announcement.title : '';
    field(form, 'body').value = isEdit ? announcement.body : '';
    setAudience(form, isEdit ? announcement.audience : 'department');
    startsOn.min = earliestStart(today, isEdit ? announcement.starts_on : null);
    startsOn.value = isEdit ? announcement.starts_on : today;
    endsOn.min = startsOn.value;
    endsOn.value = isEdit ? (announcement.ends_on || '') : '';

    modal.querySelector('[data-announcement-modal-title]').textContent = isEdit ? 'แก้ไขประกาศ' : 'สร้างประกาศ';
    modal.querySelector('[data-announcement-submit-label]').textContent = isEdit ? 'บันทึกการแก้ไข' : 'สร้างประกาศ';

    return isEdit ? 'edit' : 'create';
}

function showModal(modal, bootstrapApi) {
    const Modal = bootstrapApi?.Modal;
    if (! modal || typeof Modal?.getOrCreateInstance !== 'function') return false;

    Modal.getOrCreateInstance(modal).show();

    return true;
}

function readFeedback(root) {
    try {
        return JSON.parse(root.querySelector('[data-announcement-feedback]')?.textContent || '{}') || {};
    } catch {
        return {};
    }
}

export function initAnnouncementPage(root = document, bootstrapApi = globalThis.window?.bootstrap, swal = () => globalThis.window?.Swal) {
    const page = root.querySelector('[data-announcements-page]');
    const modal = root.querySelector('[data-announcement-modal]');

    // เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ
    if (! page || ! modal || page.dataset.announcementsReady === 'true') return false;
    page.dataset.announcementsReady = 'true';

    const form = modal.querySelector('[data-announcement-form]');

    page.addEventListener('click', (event) => {
        if (event.target.closest('[data-announcement-create]')) {
            fillAnnouncementForm(modal, null);
            showModal(modal, bootstrapApi);

            return;
        }

        const editButton = event.target.closest('[data-announcement-edit]');
        if (editButton) {
            const announcement = readAnnouncement(editButton);
            if (! announcement) return;

            fillAnnouncementForm(modal, announcement);
            showModal(modal, bootstrapApi);

            return;
        }

        if (event.target.closest('[data-announcement-clear-end]')) {
            field(form, 'ends_on').value = '';
        }
    });

    // วันสิ้นสุดต้องไม่ก่อนวันเริ่ม — ขยับ min ตามวันเริ่มที่เลือก และล้างค่าที่กลายเป็นไม่ถูกต้อง
    field(form, 'starts_on').addEventListener('change', (event) => {
        const endsOn = field(form, 'ends_on');
        endsOn.min = event.target.value;
        if (endsOn.value && endsOn.value < event.target.value) endsOn.value = '';
    });

    page.addEventListener('submit', async (event) => {
        const deleteForm = event.target.closest('[data-announcement-delete]');
        if (! deleteForm) return;

        event.preventDefault();
        const result = await swal()?.fire({
            icon: 'warning',
            title: 'ยืนยันการลบประกาศ',
            text: `ต้องการลบประกาศ “${deleteForm.dataset.announcementTitle || ''}” หรือไม่? ผู้รับจะไม่เห็นประกาศนี้อีก`,
            showCancelButton: true,
            confirmButtonText: 'ยืนยันลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
            reverseButtons: true,
        });

        if (result?.isConfirmed) deleteForm.submit();
    });

    useDatePickers();

    const feedback = readFeedback(root);
    if (feedback.success) {
        swal()?.fire({icon: 'success', title: 'สำเร็จ', text: feedback.success, confirmButtonText: 'ตกลง'});
    } else if (feedback.error) {
        swal()?.fire({icon: 'error', title: 'ไม่สำเร็จ', text: feedback.error, confirmButtonText: 'ตกลง'});
    }

    // server ตีกลับด้วย validation error — เปิดฟอร์มเดิมพร้อมค่าที่กรอกไว้ ไม่ล้างค่า
    if (modal.dataset.openOnLoad === 'true') showModal(modal, bootstrapApi);

    return true;
}

if (typeof document !== 'undefined') initAnnouncementPage(document);
