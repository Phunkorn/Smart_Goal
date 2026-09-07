/*
 * การแนบรูปภาพลงกระดาน
 *
 * ต่างจากเครื่องมืออื่นตรงที่ไม่ใช่ท่าลากบนผืนผ้าใบ แต่เป็นการเลือกไฟล์แล้ว
 * อัปโหลด จึงไม่ได้อยู่ใน tools/ แต่เป็นโมดูลของตัวเองที่ index.js เรียกใช้
 *
 * ลำดับที่สำคัญ: ต้องอัปโหลดให้เสร็จก่อนแล้วค่อยแทรกชิ้นงานลงฉาก เพราะชิ้นงาน
 * อ้างถึงรูปด้วย attachmentId ที่เซิร์ฟเวอร์เป็นคนออกให้ ถ้าแทรกก่อนแล้วค่อย
 * อัปโหลด การบันทึกอัตโนมัติอาจยิงไปในจังหวะที่ยังไม่มี id แล้วโดนปฏิเสธ
 * ด้วยข้อความที่ผู้ใช้อ่านไม่เข้าใจ
 */

const csrfToken = (doc) => doc.querySelector('meta[name="csrf-token"]')?.content || '';

/**
 * ขนาดตั้งต้นของรูปบนผืนผ้าใบ
 *
 * ย่อรูปใหญ่ให้พอดีสายตาโดยคงสัดส่วนเดิม รูปจากกล้องมือถือกว้างหลายพันพิกเซล
 * ถ้าวางตามขนาดจริงจะกลบทั้งกระดานจนผู้ใช้หาปุ่มย่อไม่เจอ
 */
export const fitInitialSize = (width, height, maxSide = 480) => {
    if (! width || ! height) {
        return {w: maxSide, h: maxSide * 0.75};
    }

    const scale = Math.min(1, maxSide / Math.max(width, height));

    return {w: Math.round(width * scale), h: Math.round(height * scale)};
};

/**
 * อัปโหลดไฟล์ที่ผู้ใช้เลือก
 *
 * @returns {Promise<Array<{id: number, src: string, width: ?number, height: ?number}>>}
 */
export const uploadImages = async (url, files, {fetchImpl = globalThis.fetch, doc = globalThis.document} = {}) => {
    const body = new (doc.defaultView || globalThis).FormData();

    Array.from(files).forEach((file) => body.append('images[]', file));

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
        throw new Error(firstErrorMessage(payload) || 'อัปโหลดรูปไม่สำเร็จ กรุณาลองใหม่');
    }

    return payload.attachments || [];
};

const firstErrorMessage = (payload) => {
    if (typeof payload?.message === 'string' && payload.message !== '') {
        return payload.message;
    }

    const first = Object.values(payload?.errors || {}).flat()[0];

    return typeof first === 'string' ? first : '';
};

/**
 * ผูกปุ่มแนบรูปเข้ากับช่องเลือกไฟล์ที่ซ่อนอยู่
 *
 * ใช้ input ที่ซ่อนไว้แทน dropzone เต็มหน้า เพราะผืนผ้าใบต้องรับ pointer event
 * ทั้งหมดไว้เอง การวาง overlay รับไฟล์ทับไว้จะไปขวางการวาด
 *
 * รองรับการวางรูปจากคลิปบอร์ดด้วย ซึ่งเป็นวิธีที่คนใช้บ่อยที่สุดตอนระดมสมอง
 * (แคปหน้าจอแล้ววางเลย)
 */
export const initAttachments = ({
    root,
    stage,
    doc = root?.ownerDocument || globalThis.document,
    uploadUrl,
    fetchImpl = globalThis.fetch,
    swal = globalThis.Swal,
    onInsert,
    canEdit = false,
}) => {
    if (! root || ! uploadUrl || ! canEdit) {
        return null;
    }

    const input = root.querySelector('[data-workspace-image-input]');

    const handleFiles = async (files) => {
        if (! files || files.length === 0) {
            return;
        }

        try {
            const attachments = await uploadImages(uploadUrl, files, {fetchImpl, doc});

            attachments.forEach((attachment) => onInsert?.(attachment));
        } catch (error) {
            await swal?.fire({icon: 'error', title: 'แนบรูปไม่สำเร็จ', text: error.message});
        }
    };

    if (input) {
        input.addEventListener('change', async () => {
            await handleFiles(input.files);

            // ล้างค่าเพื่อให้เลือกไฟล์เดิมซ้ำได้ ไม่งั้นเบราว์เซอร์จะไม่ยิง change
            // ครั้งที่สองเพราะค่าไม่เปลี่ยน
            input.value = '';
        });
    }

    // วางรูปจากคลิปบอร์ดลงบนผืนผ้าใบ
    stage?.addEventListener('paste', async (event) => {
        const files = Array.from(event.clipboardData?.files || []);

        if (files.length) {
            event.preventDefault();
            await handleFiles(files);
        }
    });

    return {
        /** เปิดหน้าต่างเลือกไฟล์ (เรียกจากปุ่มบนแถบเครื่องมือ) */
        open() {
            input?.click();
        },
    };
};
