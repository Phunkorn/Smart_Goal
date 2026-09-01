/**
 * Audit Log — การยืนยันการกู้คืนข้อมูล
 *
 * หน้าถังขยะเดิมใช้ onclick="return confirm(...)" ซึ่งผิดข้อตกลงของโปรเจกต์
 * ที่ห้าม native alert/confirm/prompt และให้ใช้ SweetAlert เหมือนหน้าอื่นทั้งระบบ
 *
 * ใช้ event delegation ตัวเดียวที่ document เพื่อให้ทำงานกับทุกแถวโดยไม่ผูก listener ต่อแถว
 * และไม่ผูกซ้ำเมื่อเนื้อหาถูก render ใหม่
 */
(() => {
    /** ฟอร์มที่ผู้ใช้ยืนยันแล้ว ใช้ปล่อยให้ submit รอบสองผ่านไปโดยไม่ถามซ้ำ */
    const confirmed = new WeakSet();

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-audit-restore]');
        if (!form) return;

        if (confirmed.has(form)) {
            confirmed.delete(form);

            return;
        }

        event.preventDefault();

        const name = form.dataset.name || 'รายการนี้';
        const result = await window.Swal.fire({
            icon: 'question',
            title: 'ยืนยันการกู้คืน',
            text: `ต้องการกู้คืน "${name}" กลับเข้าระบบใช่หรือไม่?`,
            showCancelButton: true,
            confirmButtonText: 'กู้คืน',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#2563eb',
            reverseButtons: true,
        });

        if (!result.isConfirmed) return;

        confirmed.add(form);
        // กันการกดซ้ำระหว่างที่เบราว์เซอร์กำลังส่งฟอร์ม
        form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
        form.requestSubmit ? form.requestSubmit() : form.submit();
    });
})();
