/*
 * ปุ่ม "แชร์งาน" ในเมนูจัดการของแถวงาน
 *
 * ใช้ตัวจัดการเหตุการณ์ชุดเดียวครอบคลุมทั้งแถวรายการงานหลักและแถวงานย่อย เพราะ
 * ทั้งสองแถวใช้ปุ่มจาก partial เดียวกัน (tasks/partials/share-task-menu-item)
 * การผูกแยกสองที่จะกลายเป็นสองแหล่งความจริงทันทีที่พฤติกรรมเปลี่ยน
 *
 * ผูกที่ document ด้วย delegation ไม่ใช่ผูกรายปุ่ม เพราะแถวงานถูกสร้างใหม่ได้
 * ตลอดเวลา (เพิ่มงานย่อย เปลี่ยนมุมมอง) การผูกรายปุ่มจะทำให้ปุ่มใหม่ตายและปุ่มเก่า
 * มี listener ซ้อนกันหลายชั้น
 */

const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const toast = document.querySelector('[data-toast]');

const notify = (message, ok = true) => {
    if (!toast) {
        return;
    }
    toast.textContent = message;
    toast.style.background = ok ? '#172033' : '#dc2626';
    toast.classList.add('show');
    window.setTimeout(() => toast.classList.remove('show'), 2600);
};

const request = async (url, method, payload = null) => {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: payload ? JSON.stringify(payload) : null,
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'ดำเนินการไม่สำเร็จ');
    }

    return data;
};

/* งานที่กำลังถูกแชร์อยู่แล้ว: ถามอย่างเดียวว่าจะปิดประกาศไหม */
const confirmClose = async (button) => {
    const result = await window.Swal.fire({
        title: 'ปิดประกาศแชร์งาน',
        text: `“${button.dataset.topic || 'งานนี้'}” กำลังถูกแชร์อยู่ การปิดประกาศจะยกเลิกคำขอที่ยังไม่ได้พิจารณาทั้งหมด`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ปิดประกาศ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
    });

    if (!result.isConfirmed) {
        return;
    }

    await request(button.dataset.closeUrl, 'DELETE');
    button.dataset.shared = '0';
    notify('ปิดประกาศแชร์งานแล้ว');
};

/*
 * ตัวเลือกขอบเขตเป็นการตัดสินใจหลักของกล่องนี้ จึงเป็น radio ที่เห็นทั้งสองทาง
 * พร้อมกัน ไม่ใช่ dropdown ที่ต้องกดเปิดก่อนจึงจะรู้ว่ามีอะไรให้เลือก
 */
const confirmShare = async (button) => {
    const result = await window.Swal.fire({
        title: 'แชร์งาน',
        html: `
            <p class="swal-share__lead">“${button.dataset.topic || 'งานนี้'}” จะถูกประกาศให้ผู้อื่นกดขอเข้าร่วมได้</p>
            <label class="swal-share__option">
                <input type="radio" name="share-scope" value="department" checked>
                <span><strong>เฉพาะแผนกของฉัน</strong><small>เพื่อนร่วมแผนกเห็นและขอเข้าร่วมได้ทันที</small></span>
            </label>
            <label class="swal-share__option">
                <input type="radio" name="share-scope" value="organization">
                <span><strong>ข้ามแผนก</strong><small>ทุกแผนกเห็น — ผู้ขอต่างแผนกต้องผ่านหัวหน้าแผนกอีกขั้น</small></span>
            </label>
            <textarea class="swal-share__note" maxlength="500" placeholder="ข้อความชวน (ไม่บังคับ)"></textarea>
        `,
        showCancelButton: true,
        confirmButtonText: 'แชร์งาน',
        cancelButtonText: 'ยกเลิก',
        focusConfirm: false,
        // ผูกกฎใน components/task-workspace/workspace-modal.css เข้ากับกล่องใบนี้ใบเดียว
        customClass: {popup: 'swal-share'},
        preConfirm: () => ({
            scope: document.querySelector('input[name="share-scope"]:checked')?.value || 'department',
            note: document.querySelector('.swal-share__note')?.value?.trim() || null,
        }),
    });

    if (!result.isConfirmed) {
        return;
    }

    await request(button.dataset.url, 'POST', result.value);
    button.dataset.shared = '1';
    notify('แชร์งานแล้ว — ดูคำขอได้ที่เมนูแชร์งาน');
};

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-share-task]');

    if (!button) {
        return;
    }

    event.preventDefault();

    try {
        if (button.dataset.shared === '1' && button.dataset.closeUrl) {
            await confirmClose(button);
        } else {
            await confirmShare(button);
        }
    } catch (error) {
        notify(error.message, false);
    }
});
