/*
 * กล่องสร้าง/แก้ไขกระดาน และคำสั่งที่ใช้ร่วมกันระหว่างหน้ารายการกับหน้าวาด
 *
 * ทั้งสองหน้าต้องใช้กล่องใบเดียวกัน เพราะกรอกฟิลด์ชุดเดียวกันเป๊ะ ถ้าแยกเป็น
 * สองชุด จะกลายเป็น markup และตรรกะที่ต้องแก้พร้อมกันทุกครั้ง ซึ่งเป็นสิ่งที่
 * CLAUDE.md ระบุไว้ว่าห้ามทำ (พฤติกรรมร่วมต้องมีแหล่งความจริงเดียว)
 *
 * กล่องนี้เป็น modal จริง (มี backdrop และล็อกการเลื่อนหน้า) เจ้าของสถานะเปิด/ปิด
 * โฟกัส และปุ่ม Escape มีตัวเดียวคือไฟล์นี้
 */

const csrfToken = (doc) => doc.querySelector('meta[name="csrf-token"]')?.content || '';

/**
 * ส่งคำสั่งไปยังเซิร์ฟเวอร์และคืน payload
 *
 * endpoint ของฟีเจอร์นี้ตอบด้วยรูปแบบเดียวกับทั้งระบบผ่าน jsonOrBack()
 * คือ {ok, message, ...payload} จึงตีความที่นี่ที่เดียว
 */
export const sendAction = async (url, {method = 'POST', fields = {}, fetchImpl, doc} = {}) => {
    const body = new (doc.defaultView || globalThis).FormData();

    Object.entries(fields).forEach(([key, value]) => body.set(key, value));

    // PATCH/DELETE ส่งเป็น POST พร้อม _method ตามที่ Laravel รองรับ
    if (method.toUpperCase() !== 'POST') {
        body.set('_method', method.toUpperCase());
    }

    const response = await fetchImpl(url, {
        method: 'POST',
        body,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(doc),
        },
    });

    const payload = await response.json().catch(() => ({}));

    if (! response.ok) {
        throw new Error(firstErrorMessage(payload) || 'ทำรายการไม่สำเร็จ กรุณาลองใหม่');
    }

    return payload;
};

/** ข้อความผิดพลาดข้อแรกจาก response ของ Laravel (รองรับทั้ง message และ errors) */
export const firstErrorMessage = (payload) => {
    if (payload && typeof payload.message === 'string' && payload.message !== '') {
        return payload.message;
    }

    const errors = payload?.errors;

    if (errors && typeof errors === 'object') {
        const first = Object.values(errors).flat()[0];

        if (typeof first === 'string') {
            return first;
        }
    }

    return '';
};

/**
 * ตัวควบคุมกล่องสร้าง/แก้ไข
 *
 * แยกเป็นฟังก์ชันของตัวเองเพื่อให้สถานะ (กำลังสร้าง หรือกำลังแก้ใบไหน) อยู่ที่
 * เดียว และให้การเปิดซ้ำหลายรอบไม่ผูก event listener เพิ่ม
 */
export const createBoardModal = (root, {doc, fetchImpl, onSaved}) => {
    const modal = root.querySelector('[data-workspace-modal]');

    if (! modal) {
        return null;
    }

    const form = modal.querySelector('[data-workspace-form]');
    const titleInput = modal.querySelector('[data-workspace-title]');
    const heading = modal.querySelector('[data-workspace-modal-title]');
    const errorBox = modal.querySelector('[data-workspace-error]');
    const submitButton = modal.querySelector('[data-workspace-submit]');

    // จำ element ที่เปิดกล่องไว้ เพื่อคืนโฟกัสให้ตอนปิด ผู้ใช้คีย์บอร์ดจะได้ไม่
    // หลุดไปอยู่ต้นหน้า
    let trigger = null;
    let mode = 'create';
    let action = '';

    const showError = (message) => {
        errorBox.textContent = message;
        errorBox.hidden = message === '';
    };

    const close = () => {
        modal.hidden = true;
        doc.body.classList.remove('ws-modal-open');
        showError('');
        trigger?.focus();
        trigger = null;
    };

    const open = (options) => {
        mode = options.mode;
        action = options.action;
        trigger = options.trigger || null;

        heading.textContent = mode === 'create' ? 'สร้างกระดานใหม่' : 'ตั้งค่ากระดาน';
        submitButton.textContent = mode === 'create' ? 'สร้างกระดาน' : 'บันทึก';
        titleInput.value = options.title || '';

        const visibility = options.visibility || 'organization';
        form.querySelectorAll('input[name="visibility"]').forEach((input) => {
            input.checked = input.value === visibility;
        });

        form.dataset.departmentId = options.departmentId || '';

        showError('');
        modal.hidden = false;
        doc.body.classList.add('ws-modal-open');
        titleInput.focus();
    };

    modal.querySelectorAll('[data-workspace-modal-dismiss]').forEach((button) => {
        button.addEventListener('click', close);
    });

    // Escape ปิดกล่อง — ผูกที่ document เพราะโฟกัสอาจอยู่บน backdrop ที่ไม่รับ key
    doc.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && ! modal.hidden) {
            close();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const title = titleInput.value.trim();

        if (title === '') {
            showError('กรุณาตั้งชื่อกระดาน');
            titleInput.focus();

            return;
        }

        const fields = {
            title,
            visibility: form.querySelector('input[name="visibility"]:checked')?.value || 'organization',
        };

        if (mode === 'create') {
            fields.department_id = form.dataset.departmentId;
        }

        submitButton.disabled = true;
        showError('');

        try {
            const payload = await sendAction(action, {
                method: mode === 'create' ? 'POST' : 'PATCH',
                fields,
                fetchImpl,
                doc,
            });

            close();
            onSaved(mode, payload);
        } catch (error) {
            showError(error.message);
        } finally {
            submitButton.disabled = false;
        }
    });

    return {open, close};
};


/**
 * ถามยืนยันแล้วลบกระดาน
 *
 * ใช้ window.Swal ไม่ใช่ confirm() ของเบราว์เซอร์ ตามกติกาใน CLAUDE.md
 *
 * @param {object} board {id, title, deleteUrl}
 * @returns {Promise<boolean>} ลบสำเร็จหรือไม่ (false เมื่อผู้ใช้ยกเลิกด้วย)
 */
export const confirmDeleteBoard = async (board, {fetchImpl, doc, swal}) => {
    const result = await swal.fire({
        icon: 'warning',
        title: 'ลบกระดานนี้?',
        text: `"${board.title}" จะถูกนำออกจากรายการ`,
        showCancelButton: true,
        confirmButtonText: 'ลบกระดาน',
        cancelButtonText: 'ยกเลิก',
    });

    if (! result.isConfirmed) {
        return false;
    }

    try {
        await sendAction(board.deleteUrl, {method: 'DELETE', fetchImpl, doc});

        return true;
    } catch (error) {
        await swal.fire({icon: 'error', title: 'ลบไม่สำเร็จ', text: error.message});

        return false;
    }
};

/** อ่าน JSON island คืนอ็อบเจ็กต์ว่างเมื่อไม่มีหรืออ่านไม่ออก */
export const readJsonIsland = (doc, id) => {
    const node = doc.getElementById(id);

    if (! node) {
        return {};
    }

    try {
        return JSON.parse(node.textContent) || {};
    } catch {
        return {};
    }
};
