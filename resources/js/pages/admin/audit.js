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

    /**
     * ส่งฟอร์มหลังผู้ใช้ยืนยันแล้ว
     *
     * ต้องส่ง submitter กลับเข้าไปด้วย เพราะแถบจัดการหลายรายการใช้ปุ่มสองตัวที่พา
     * formaction กับ name="_method" ของตัวเองไป ถ้าเรียก requestSubmit() เปล่า ๆ
     * ค่าเหล่านั้นจะหายไปทั้งหมด ฟอร์มจะยิงผิดปลายทางและผิดเมธอด
     */
    const send = (form, submitter) => {
        confirmed.add(form);
        // กันการกดซ้ำระหว่างที่เบราว์เซอร์กำลังส่งฟอร์ม
        form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });

        if (form.requestSubmit) {
            form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);

            return;
        }

        form.submit();
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

    /**
     * ล้างบันทึกกิจกรรมเก่า — บอกให้ชัดว่าอะไรหายและอะไรยังอยู่
     *
     * ผู้ดูแลระบบต้องไม่เข้าใจว่านี่คือการล้างบันทึกทั้งหมด หลักฐานการเข้าออกระบบและ
     * การลบข้อมูลยังอยู่ตามอายุของมันเอง
     */
    const confirmPruneActivity = (form) => window.Swal.fire({
        icon: 'warning',
        title: 'ล้างบันทึกกิจกรรมเก่า',
        html: 'บันทึกที่พ้นอายุ <strong></strong> รายการจะถูกลบถาวร'
            + '<br>หลักฐานสำคัญ (เข้าออกระบบ การลบ การกู้คืน) ที่ยังไม่ครบ 365 วัน จะไม่ถูกแตะ',
        showCancelButton: true,
        confirmButtonText: 'ล้างบันทึกเก่า',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
        reverseButtons: true,
        didOpen: (popup) => {
            const slot = popup.querySelector('strong');
            if (slot) slot.textContent = form.dataset.count || '0';
        },
    });

    /**
     * จำนวนรายการที่คำสั่งแบบชุดจะทำงานด้วย
     *
     * ถ้าติ๊ก "เลือกทั้งหมดที่กรองอยู่" จำนวนจริงคือจำนวนตามตัวกรองทั้งหมด ไม่ใช่
     * เฉพาะช่องที่ติ๊กอยู่ในหน้านี้ ตัวเลขที่ให้ผู้ใช้ยืนยันต้องตรงกับสิ่งที่จะถูกลบจริง
     */
    const bulkCount = (form) => {
        const scope = form.querySelector('[data-audit-scope-toggle]');

        if (scope && scope.checked) {
            return Number(scope.dataset.count || 0);
        }

        return [...document.querySelectorAll('[data-audit-select]')].filter((box) => box.checked).length;
    };

    const confirmBulkRestore = (form) => window.Swal.fire({
        icon: 'question',
        title: 'ยืนยันการกู้คืน',
        text: `ต้องการกู้คืน ${bulkCount(form)} รายการกลับเข้าระบบใช่หรือไม่? `
            + 'รายการที่พ้นกำหนดกู้คืนแล้วจะถูกข้าม',
        showCancelButton: true,
        confirmButtonText: 'กู้คืน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#2563eb',
        reverseButtons: true,
    });

    /**
     * ลบถาวรหลายรายการ — ยืนยันด้วยการพิมพ์ "จำนวน" ให้ตรง
     *
     * การลบเป็นชุดไม่มีชื่อรายการเดียวให้พิมพ์เหมือนการลบรายแถว จำนวนจึงเป็นสิ่งเดียว
     * ที่ยืนยันได้ และการบังคับพิมพ์ทำให้ผู้ใช้ต้องอ่านตัวเลขก่อน ไม่ใช่กดยืนยันผ่าน ๆ
     */
    const confirmBulkPurge = (form) => {
        const count = String(bulkCount(form));

        return window.Swal.fire({
            icon: 'warning',
            title: 'ลบถาวร กู้กลับไม่ได้',
            html: `ข้อมูลและไฟล์ของ <strong>${count}</strong> รายการจะถูกลบออกจากระบบอย่างถาวร`
                + '<br>พิมพ์จำนวนรายการเพื่อยืนยัน',
            input: 'text',
            inputPlaceholder: count,
            inputAttributes: {
                autocomplete: 'off',
                inputmode: 'numeric',
                'aria-label': 'พิมพ์จำนวนรายการเพื่อยืนยันการลบถาวร',
            },
            showCancelButton: true,
            confirmButtonText: 'ลบถาวร',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626',
            reverseButtons: true,
            inputValidator: (value) => ((value || '').trim() === count
                ? undefined
                : 'จำนวนไม่ตรงกับรายการที่จะลบ'),
        });
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest(
            '[data-audit-restore], [data-audit-purge], [data-audit-purge-expired],'
            + ' [data-audit-revert], [data-audit-prune-activity], [data-audit-bulk]'
        );
        if (!form) return;

        if (confirmed.has(form)) {
            confirmed.delete(form);

            return;
        }

        event.preventDefault();

        // แถบจัดการหลายรายการมีปุ่มสองตัวในฟอร์มเดียว ปุ่มที่กดคือสิ่งที่บอกว่าจะทำอะไร
        const submitter = event.submitter;

        let result;
        if (form.matches('[data-audit-bulk]')) {
            if (bulkCount(form) === 0) {
                await window.Swal.fire({
                    icon: 'info',
                    title: 'ยังไม่ได้เลือกรายการ',
                    text: 'ติ๊กอย่างน้อยหนึ่งรายการก่อนสั่งกู้คืนหรือลบถาวร',
                    confirmButtonText: 'ตกลง',
                });

                return;
            }

            result = submitter && submitter.matches('[data-audit-bulk-purge]')
                ? await confirmBulkPurge(form)
                : await confirmBulkRestore(form);
        } else if (form.matches('[data-audit-prune-activity]')) {
            result = await confirmPruneActivity(form);
        } else if (form.matches('[data-audit-purge]')) {
            result = await confirmPurge(form);
        } else if (form.matches('[data-audit-purge-expired]')) {
            result = await confirmPurgeExpired(form);
        } else if (form.matches('[data-audit-revert]')) {
            result = await confirmRevert(form);
        } else {
            result = await confirmRestore(form);
        }

        if (!result.isConfirmed) return;

        send(form, submitter);
    });

    /**
     * การเลือกหลายรายการในถังขยะ
     *
     * ช่องติ๊กอยู่ในตาราง แต่เป็นของฟอร์ม auditBulkForm ผ่านแอตทริบิวต์ form= เพราะใน
     * ตารางมีฟอร์มกู้คืนและลบรายแถวอยู่แล้ว การซ้อนฟอร์มในฟอร์มไม่ถูกต้องตาม HTML
     *
     * ใช้ event delegation ที่ document ตัวเดียวเหมือนส่วนบน จึงไม่ผูก listener ซ้ำ
     * เมื่อเปลี่ยนหน้าแล้วเนื้อหาถูก render ใหม่
     */
    const bulkForm = () => document.getElementById('auditBulkForm');

    const boxes = () => [...document.querySelectorAll('[data-audit-select]')];

    const syncBulkBar = () => {
        const form = bulkForm();
        if (!form) return;

        const scope = form.querySelector('[data-audit-scope-toggle]');
        const scopeOn = !!(scope && scope.checked);
        const checked = boxes().filter((box) => box.checked);
        const counter = form.querySelector('[data-audit-selected-count]');

        if (counter) {
            counter.textContent = scopeOn ? (scope.dataset.count || '0') : String(checked.length);
        }

        // ช่องซ่อนบอกฝั่งเซิร์ฟเวอร์ว่าให้ทำงานกับทุกแถวตามตัวกรอง ไม่ใช่เฉพาะที่ติ๊ก
        const scopeField = form.querySelector('[data-audit-scope]');
        if (scopeField) scopeField.value = scopeOn ? 'filtered' : '';

        form.hidden = !scopeOn && checked.length === 0;

        const all = document.querySelector('[data-audit-select-all]');
        if (all) {
            all.checked = boxes().length > 0 && checked.length === boxes().length;
            all.indeterminate = checked.length > 0 && checked.length < boxes().length;
        }
    };

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-audit-select-all]')) {
            boxes().forEach((box) => { box.checked = event.target.checked; });
            syncBulkBar();

            return;
        }

        if (event.target.matches('[data-audit-scope-toggle]')) {
            // เลือกทั้งหมดตามตัวกรองแล้ว การติ๊กรายแถวไม่มีความหมายอีก จึงติ๊กให้ครบ
            if (event.target.checked) {
                boxes().forEach((box) => { box.checked = true; });
            }

            syncBulkBar();

            return;
        }

        if (event.target.matches('[data-audit-select]')) {
            // ยกเลิกการติ๊กรายแถว = ไม่ได้หมายถึงทั้งหมดตามตัวกรองอีกต่อไป
            const scope = bulkForm()?.querySelector('[data-audit-scope-toggle]');
            if (scope && scope.checked && !event.target.checked) scope.checked = false;

            syncBulkBar();
        }
    });

    syncBulkBar();

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
