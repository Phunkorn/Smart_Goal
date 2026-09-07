/**
 * Audit Log — การยืนยันการกู้คืนและการลบถาวร
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

    const send = (form) => {
        confirmed.add(form);
        // กันการกดซ้ำระหว่างที่เบราว์เซอร์กำลังส่งฟอร์ม
        form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
        form.requestSubmit ? form.requestSubmit() : form.submit();
    };

    /**
     * กู้คืน — ย้อนกลับได้ ถามยืนยันธรรมดาพอ
     */
    const confirmRestore = (form) => window.Swal.fire({
        icon: 'question',
        title: 'ยืนยันการกู้คืน',
        text: `ต้องการกู้คืน "${form.dataset.name || 'รายการนี้'}" กลับเข้าระบบใช่หรือไม่?`,
        showCancelButton: true,
        confirmButtonText: 'กู้คืน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#2563eb',
        reverseButtons: true,
    });

    /**
     * ลบถาวร — ทำลายข้อมูลและไฟล์อย่างถาวร กู้กลับไม่ได้
     *
     * บังคับให้พิมพ์ชื่อรายการให้ตรงก่อน ไม่ใช่แค่กดยืนยัน เพราะปุ่มนี้อยู่ในตาราง
     * ติดกับปุ่มกู้คืน การกดพลาดหนึ่งครั้งคือข้อมูลหายถาวรโดยไม่มีทางแก้
     */
    const confirmPurge = (form) => {
        const name = form.dataset.name || '';

        return window.Swal.fire({
            icon: 'warning',
            title: 'ลบถาวร กู้กลับไม่ได้',
            html: `ข้อมูลและไฟล์ของ <strong></strong> จะถูกลบออกจากระบบอย่างถาวร<br>พิมพ์ชื่อรายการให้ตรงเพื่อยืนยัน`,
            input: 'text',
            inputPlaceholder: name,
            inputAttributes: { autocomplete: 'off', 'aria-label': 'พิมพ์ชื่อรายการเพื่อยืนยันการลบถาวร' },
            showCancelButton: true,
            confirmButtonText: 'ลบถาวร',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
            reverseButtons: true,
            // ชื่อไฟล์ภาษาไทยมีช่องว่างท้ายได้ง่าย จึงตัดช่องว่างหัวท้ายก่อนเทียบ
            inputValidator: (value) => ((value || '').trim() === name.trim()
                ? undefined
                : 'ชื่อไม่ตรงกับรายการที่จะลบ'),
            didOpen: (popup) => {
                // ใส่ชื่อด้วย textContent ไม่ใช่ใน html เพราะชื่อมาจากข้อมูลของผู้ใช้
                // การต่อสตริงเข้า innerHTML ตรง ๆ คือช่องทาง XSS
                const slot = popup.querySelector('strong');
                if (slot) slot.textContent = name;
            },
        });
    };

    /**
     * ล้างของหมดอายุทั้งหมด — ไม่มีชื่อรายการเดียวให้พิมพ์ จึงยืนยันด้วยจำนวนแทน
     */
    const confirmPurgeExpired = (form) => window.Swal.fire({
        icon: 'warning',
        title: 'ล้างรายการที่หมดเวลากู้คืน',
        text: `รายการที่พ้น 30 วันแล้วจะถูกลบออกจากระบบถาวรทั้งหมด (${form.dataset.count || 0} รายการ)`,
        showCancelButton: true,
        confirmButtonText: 'ล้างทั้งหมด',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
        reverseButtons: true,
    });

    /**
     * ย้อนค่าเดิม — เขียนทับข้อมูลปัจจุบัน ต้องบอกให้ชัดว่าค่าที่ใช้อยู่จะหายไป
     */
    const confirmRevert = (form) => {
        const chosen = [...form.querySelectorAll('input[name="fields[]"]:checked')];

        if (chosen.length === 0) {
            return window.Swal.fire({
                icon: 'info',
                title: 'ยังไม่ได้เลือกค่าที่จะย้อน',
                text: 'ติ๊กอย่างน้อยหนึ่งรายการก่อนกดย้อนค่า',
                confirmButtonText: 'ตกลง',
            }).then(() => ({isConfirmed: false}));
        }

        return window.Swal.fire({
            icon: 'question',
            title: 'ย้อนค่าเดิม',
            text: `ค่าที่ใช้อยู่ตอนนี้ของ ${chosen.length} รายการจะถูกเขียนทับด้วยค่าเดิม การย้อนจะถูกบันทึกเป็นกิจกรรมด้วย`,
            showCancelButton: true,
            confirmButtonText: 'ย้อนค่า',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#2563eb',
            reverseButtons: true,
        });
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest(
            '[data-audit-restore], [data-audit-purge], [data-audit-purge-expired], [data-audit-revert]'
        );
        if (!form) return;

        if (confirmed.has(form)) {
            confirmed.delete(form);

            return;
        }

        event.preventDefault();

        let result;
        if (form.matches('[data-audit-purge]')) {
            result = await confirmPurge(form);
        } else if (form.matches('[data-audit-purge-expired]')) {
            result = await confirmPurgeExpired(form);
        } else if (form.matches('[data-audit-revert]')) {
            result = await confirmRevert(form);
        } else {
            result = await confirmRestore(form);
        }

        if (!result.isConfirmed) return;

        send(form);
    });

    /**
     * ตัวกรองส่งเองเมื่อหยุดพิมพ์ และเมื่อเปลี่ยนตัวเลือก
     *
     * เดิมทุกการกรองต้องกดปุ่ม "กรอง" ซึ่งเป็นขั้นตอนที่ไม่จำเป็นบนหน้าที่คนเข้ามา
     * เพื่อค้นหาเป็นหลัก ปุ่มยังอยู่ครบสำหรับผู้ใช้คีย์บอร์ดและกรณีที่ JavaScript ไม่ทำงาน
     */
    const filters = document.querySelector('[data-audit-filters]');

    if (filters) {
        let timer = null;

        filters.addEventListener('input', (event) => {
            if (!event.target.matches('input[type="search"]')) return;

            window.clearTimeout(timer);
            timer = window.setTimeout(() => filters.requestSubmit?.(), 450);
        });

        filters.addEventListener('change', (event) => {
            if (!event.target.matches('select, input[type="date"]')) return;

            filters.requestSubmit?.();
        });
    }
})();
