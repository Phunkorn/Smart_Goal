/**
 * ฟอร์มแจ้งปัญหา Smart Ticket — ใช้ทั้งหน้าภายใน (tickets/create) และหน้าสาธารณะ (/support)
 *
 * หน้าที่:
 *   1. สาขา "อื่น ๆ" → เปิดช่องพิมพ์ชื่อสาขาเองและทำให้เป็นช่องบังคับ
 *   2. รูปภาพ → เลือกได้หลายครั้งรวมไม่เกินเพดาน แสดงตัวอย่าง และลบทีละรูปก่อนส่ง
 *      ปุ่ม "ถ่ายรูป" ([data-ticket-photos-camera] — input ที่เปิดกล้อง ไม่มี name) เพิ่มรูปเข้าชุดเดียวกัน
 *      ฟอร์มถูก reset (เช่น ปิด modal บันทึก) → ล้างรูปที่เลือกไว้
 *   3. ปุ่มส่ง → สถานะกำลังส่ง และกันการกดซ้ำ (server กันซ้ำอีกชั้นด้วย submission_key)
 *
 * ใช้กับฟอร์มแจ้งปัญหา (ภายใน/สาธารณะ) ฟอร์มบันทึกการดำเนินงาน และฟอร์มปิดงาน (รูปหลังแก้ไข) บนหน้ารายละเอียด
 * ผลสำเร็จแสดงเป็นแถบในหน้าจาก server (tickets/components/flash.blade.php) ไม่ใช่ที่นี่
 *
 * เพดานรูปอ่านจาก data-attribute ที่ server ใส่จาก AttachmentPolicy ไม่ฮาร์ดโค้ดที่นี่
 * การตรวจฝั่ง client มีไว้ให้ผู้ใช้รู้เร็ว — server ตรวจซ้ำทุกครั้ง
 */

const OTHER_LOCATION = 'other';

/** อ่านเพดานรูปจาก [data-ticket-photos] */
export function readPhotoLimits(container) {
    return {
        maxFiles: Number(container?.dataset.maxFiles) || 0,
        maxBytes: Number(container?.dataset.maxBytes) || 0,
        accept: String(container?.dataset.accept || '').split(',').map((type) => type.trim()).filter(Boolean),
    };
}

/**
 * รวมรูปที่เลือกไว้กับรูปที่เพิ่งเลือก คืนรายการใหม่และข้อความผิดพลาด (ไม่แตะ DOM)
 *
 * @returns {{files: File[], errors: string[]}}
 */
export function selectPhotos(current, incoming, {maxFiles, maxBytes, accept}) {
    const files = [...current];
    const errors = [];
    const megabytes = Math.round(maxBytes / (1024 * 1024));

    for (const file of incoming) {
        if (accept.length > 0 && ! accept.includes(file.type)) {
            errors.push(`ไฟล์ "${file.name}" ไม่ใช่รูป JPG หรือ PNG`);
            continue;
        }

        if (maxBytes > 0 && file.size > maxBytes) {
            errors.push(`รูป "${file.name}" มีขนาดเกิน ${megabytes} MB`);
            continue;
        }

        if (files.length >= maxFiles) {
            errors.push(`แนบรูปได้ไม่เกิน ${maxFiles} รูป`);
            break;
        }

        files.push(file);
    }

    return {files, errors};
}

/** สร้าง FileList ใหม่จากรายการรูป — คืน null เมื่อเบราว์เซอร์ไม่รองรับ DataTransfer */
function browserFileList(files) {
    if (typeof DataTransfer !== 'function') return null;

    try {
        const transfer = new DataTransfer();
        files.forEach((file) => transfer.items.add(file));

        return transfer.files;
    } catch {
        return null;
    }
}

function bindLocation(form) {
    const select = form.querySelector('[data-ticket-location]');
    const other = form.querySelector('[data-ticket-location-other]');
    const otherInput = other?.querySelector('input');

    // ไม่มีรายการสาขา = มีแต่ช่องพิมพ์เอง ซึ่งเป็นช่องบังคับอยู่แล้วจาก server
    if (! select || ! other || ! otherInput) return;

    const sync = () => {
        const isOther = select.value === OTHER_LOCATION;
        other.hidden = ! isOther;
        otherInput.required = isOther;
    };

    select.addEventListener('change', () => {
        sync();
        if (select.value === OTHER_LOCATION) otherInput.focus();
    });
    sync();
}

function bindPhotos(form, {createFileList, objectUrls}) {
    const container = form.querySelector('[data-ticket-photos]');
    const input = container?.querySelector('[data-ticket-photos-input]');
    const camera = container?.querySelector('[data-ticket-photos-camera]');
    const list = container?.querySelector('[data-ticket-photos-list]');
    const errorBox = container?.querySelector('[data-ticket-photos-error]');

    if (! container || ! input || ! list || ! errorBox) return;

    const limits = readPhotoLimits(container);
    let selected = [];
    let previewUrls = [];

    const showErrors = (errors) => {
        errorBox.textContent = errors.join(' · ');
        errorBox.hidden = errors.length === 0;
    };

    const render = () => {
        previewUrls.forEach((url) => objectUrls?.revokeObjectURL?.(url));
        previewUrls = [];
        list.replaceChildren();

        selected.forEach((file, index) => {
            const item = document.createElement('li');
            item.className = 'ticket-photos__item';

            const image = document.createElement('img');
            const url = objectUrls?.createObjectURL?.(file) || '';
            if (url) previewUrls.push(url);
            image.src = url;
            image.alt = `ตัวอย่างรูปที่ ${index + 1}`;

            const name = document.createElement('span');
            name.className = 'ticket-photos__name';
            name.textContent = file.name;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ticket-photos__remove';
            remove.dataset.ticketPhotoRemove = String(index);
            remove.setAttribute('aria-label', `ลบรูปที่ ${index + 1} (${file.name})`);
            remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

            item.append(image, remove, name);
            list.append(item);
        });

        container.classList.toggle('is-full', selected.length >= limits.maxFiles);
    };

    /** ให้ input ถือรูปชุดเดียวกับที่แสดง — คืน false เมื่อเบราว์เซอร์แก้ไฟล์ใน input ไม่ได้ */
    const syncInput = () => {
        const fileList = createFileList(selected);
        if (! fileList) return false;
        input.files = fileList;

        return true;
    };

    // รูปจากกล้อง: รวมเข้าชุดเดียวกับ input หลัก (ซึ่งเป็นตัวที่ส่งไป server) แล้วล้าง input ของกล้อง
    camera?.addEventListener('change', () => {
        const incoming = Array.from(camera.files || []);
        camera.value = '';
        if (incoming.length === 0) return;
        const previous = selected;
        const result = selectPhotos(selected, incoming, limits);
        selected = result.files;

        if (! syncInput()) {
            selected = previous;
            showErrors(['เบราว์เซอร์นี้เพิ่มรูปจากกล้องไม่ได้ กรุณาใช้ "เลือกจากคลังรูป"']);
            render();

            return;
        }

        showErrors(result.errors);
        render();
    });

    form.addEventListener('reset', () => {
        selected = [];
        input.value = '';
        showErrors([]);
        render();
    });

    input.addEventListener('change', () => {
        const incoming = Array.from(input.files || []);
        const result = selectPhotos(selected, incoming, limits);
        selected = result.files;

        if (! syncInput()) {
            // เบราว์เซอร์เก่าที่ไม่รองรับ DataTransfer: input ถือได้เฉพาะชุดที่เลือกล่าสุด
            // จึงใช้ชุดล่าสุดอย่างเดียว และล้างทั้งหมดเมื่อชุดนั้นมีรูปที่ใช้ไม่ได้
            const latest = selectPhotos([], incoming, limits);
            selected = latest.errors.length > 0 ? [] : latest.files;
            if (latest.errors.length > 0) input.value = '';
            showErrors(latest.errors);
            render();

            return;
        }

        showErrors(result.errors);
        render();
    });

    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-ticket-photo-remove]');
        if (! button) return;

        selected = selected.filter((_, index) => index !== Number(button.dataset.ticketPhotoRemove));
        if (! syncInput()) {
            selected = [];
            input.value = '';
        }
        showErrors([]);
        render();
        input.focus();
    });
}

function bindSubmit(form) {
    const button = form.querySelector('[data-ticket-submit]');
    const label = button?.querySelector('[data-ticket-submit-label]');
    const spinner = button?.querySelector('[data-ticket-submit-spinner]');
    const idleText = label?.textContent || '';
    // ข้อความระหว่างส่งตั้งได้ต่อปุ่ม (data-busy-label — components/submit-button) ค่าเริ่มต้นใช้ร่วมกับฟอร์มสาธารณะและบันทึก
    const busyText = button?.dataset.busyLabel || 'กำลังส่ง…';

    const setBusy = (busy) => {
        form.dataset.submitting = busy ? 'true' : 'false';
        if (! button) return;
        button.disabled = busy;
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (spinner) spinner.hidden = ! busy;
        if (label) label.textContent = busy ? busyText : idleText;
    };

    form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();

            return;
        }

        setBusy(true);
    });

    // กลับมาหน้านี้ด้วยปุ่มย้อนกลับ (bfcache) ปุ่มต้องกลับมากดได้
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) setBusy(false);
    });
}

/**
 * ช่องตัวเลขล้วน ([data-ticket-digits="จำนวนหลักสูงสุด"] เช่น เบอร์โทร) — ตัดขีด ช่องว่าง และอักขระอื่นทิ้งระหว่างพิมพ์และวาง
 * จำกัดความยาวหลังตัดแล้ว (ไม่ใช้ maxlength ที่ตัดข้อความที่วางก่อน)
 */
export function digitsOnly(value, maxLength = Infinity) {
    return String(value ?? '').replace(/\D+/g, '').slice(0, maxLength);
}

function bindDigits(form) {
    // delegation ที่ฟอร์ม — listener เดียวต่อฟอร์มไม่ว่าจะมีกี่ช่อง
    form.addEventListener('input', (event) => {
        const input = event.target.closest?.('[data-ticket-digits]');
        if (! input) return;
        const limit = Number(input.dataset.ticketDigits) || Infinity;
        const cleaned = digitsOnly(input.value, limit);
        if (cleaned !== input.value) input.value = cleaned;
    });
}

/**
 * ผูกทุกฟอร์ม [data-ticket-form] ในหน้า (เช่น หน้ารายละเอียดมีทั้งฟอร์มบันทึกและฟอร์มปิดงานที่แนบรูปได้)
 * เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ — คืน true เมื่อมีฟอร์มที่เพิ่งผูก
 *
 * @param {ParentNode} root
 * @param {{createFileList?: (files: File[]) => FileList|null, objectUrls?: {createObjectURL: Function, revokeObjectURL: Function}}} options
 */
export function initTicketForm(root = document, {
    createFileList = browserFileList,
    objectUrls = globalThis.URL,
} = {}) {
    const forms = Array.from(root.querySelectorAll('[data-ticket-form]')).filter((form) => form.dataset.ticketFormReady !== 'true');

    forms.forEach((form) => {
        form.dataset.ticketFormReady = 'true';
        bindLocation(form);
        bindDigits(form);
        bindPhotos(form, {createFileList, objectUrls});
        bindSubmit(form);
    });

    return forms.length > 0;
}

if (typeof document !== 'undefined') initTicketForm(document);
