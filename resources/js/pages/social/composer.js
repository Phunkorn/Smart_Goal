import {createAudienceSelector, EVERYONE} from './audience.js';
import {alertError, request, setBusy, toast} from './client.js';
import {createEmojiPicker} from './emoji-picker.js';

/*
 * modal สร้าง/แก้ไขโพสต์ — ใบเดียวใช้ทั้งสองโหมด
 *
 * Bootstrap Modal เป็นเจ้าของการเปิด/ปิด backdrop การล็อกการเลื่อน focus trap และ Escape
 * ไฟล์นี้ดูแลแค่สถานะของฟอร์ม: ข้อความ รูปใหม่ (พร้อม preview) รูปเดิมที่ถูกเอาออก และการส่ง
 *
 * การตรวจฝั่งหน้าเว็บมีไว้บอกผู้ใช้เร็วขึ้นเท่านั้น server ตรวจชนิดไฟล์จากเนื้อไฟล์จริงเสมอ
 */

export const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * คัดไฟล์ที่เลือกมาเทียบกับเพดาน — คืนไฟล์ที่รับได้และข้อความของไฟล์ที่ถูกปฏิเสธ
 * ฟังก์ชันบริสุทธิ์ ทดสอบได้โดยไม่ต้องมี DOM
 */
export function screenFiles(files, {currentCount = 0, maxImages = 10, maxKilobytes = 10240} = {}) {
    const accepted = [];
    const errors = [];
    let room = Math.max(0, maxImages - currentCount);

    for (const file of files) {
        if (! ACCEPTED_TYPES.includes(file.type)) {
            errors.push(`"${file.name}" ไม่ใช่รูป JPG, PNG หรือ WebP`);
            continue;
        }
        if (file.size > maxKilobytes * 1024) {
            errors.push(`"${file.name}" มีขนาดเกิน ${Math.round((maxKilobytes / 1024) * 10) / 10} MB`);
            continue;
        }
        if (room === 0) {
            errors.push(`แนบรูปได้ไม่เกิน ${maxImages} รูปต่อโพสต์`);
            break;
        }
        accepted.push(file);
        room -= 1;
    }

    return {accepted, errors};
}

export function createComposer(page, {bootstrapApi, swal, fetchImpl, onSaved = () => {}} = {}) {
    const modalElement = page.querySelector('[data-social-post-modal]');
    if (! modalElement) return null;

    const doc = page.ownerDocument;
    const win = doc.defaultView;
    const form = modalElement.querySelector('[data-social-post-form]');
    const body = form.querySelector('[data-social-post-body]');
    const counter = form.querySelector('[data-social-post-counter]');
    const fileInput = form.querySelector('[data-social-post-file]');
    const mediaBox = form.querySelector('[data-social-post-media]');
    const previews = form.querySelector('[data-social-post-previews]');
    const errorBox = form.querySelector('[data-social-post-error]');
    const submit = form.querySelector('[data-social-post-submit]');
    const title = form.querySelector('[data-social-post-modal-title]');
    const limits = {
        maxImages: Number(page.dataset.maxImages) || 10,
        maxKilobytes: Number(page.dataset.maxImageKb) || 10240,
        maxLength: Number(page.dataset.postMaxLength) || Number(body.getAttribute('maxlength')) || 5000,
    };

    const state = {
        mode: 'create',
        updateUrl: null,
        card: null,
        files: [],        // [{file, url}]
        existing: [],     // [{id, url, alt}]
        removed: new Set(),
        submitting: false,
        trigger: null,
        intent: 'text',
    };

    const modal = () => bootstrapApi?.Modal?.getOrCreateInstance?.(modalElement) ?? null;
    const imageCount = () => state.files.length + state.existing.filter((item) => ! state.removed.has(item.id)).length;

    const showError = (message) => {
        errorBox.textContent = message || '';
        errorBox.hidden = ! message;
    };

    const updateCounter = () => {
        counter.textContent = `${body.value.length.toLocaleString('en-US')} / ${limits.maxLength.toLocaleString('en-US')}`;
        counter.classList.toggle('is-near-limit', body.value.length > limits.maxLength * 0.9);
    };

    // ช่องข้อความสูงตามเนื้อหา แต่ไม่เกินที่ CSS กำหนด (max-height) แล้วจึงเลื่อนภายใน
    const autoGrow = () => {
        body.style.height = 'auto';
        body.style.height = `${body.scrollHeight}px`;
    };

    const renderPreviews = () => {
        previews.replaceChildren();

        const items = [
            ...state.existing.filter((item) => ! state.removed.has(item.id)).map((item) => ({kind: 'existing', id: item.id, url: item.url, alt: item.alt})),
            ...state.files.map((item, index) => ({kind: 'new', index, url: item.url, alt: item.file.name})),
        ];

        items.forEach((item, position) => {
            const li = doc.createElement('li');
            li.className = 'social-upload-grid__item';

            const img = doc.createElement('img');
            img.src = item.url;
            img.alt = item.alt || `รูปที่ ${position + 1}`;
            img.loading = 'lazy';

            const remove = doc.createElement('button');
            remove.type = 'button';
            remove.className = 'social-upload-grid__remove';
            remove.setAttribute('aria-label', `เอารูปที่ ${position + 1} ออก`);
            remove.dataset.socialRemoveImage = item.kind;
            remove.dataset.value = String(item.kind === 'existing' ? item.id : item.index);
            remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

            li.append(img, remove);
            previews.append(li);
        });

        mediaBox.hidden = items.length === 0;
    };

    const revokeFiles = () => {
        state.files.forEach((item) => win.URL?.revokeObjectURL?.(item.url));
        state.files = [];
    };

    const reset = () => {
        revokeFiles();
        state.existing = [];
        state.removed = new Set();
        state.updateUrl = null;
        state.card = null;
        state.mode = 'create';
        form.reset();
        body.value = '';
        body.style.height = '';
        fileInput.value = '';
        showError(null);
        renderPreviews();
        updateCounter();
    };

    const addFiles = (fileList) => {
        const {accepted, errors} = screenFiles([...fileList], {...limits, currentCount: imageCount()});

        accepted.forEach((file) => state.files.push({file, url: win.URL?.createObjectURL?.(file) ?? ''}));
        renderPreviews();
        showError(errors[0] ?? null);
        fileInput.value = '';
    };

    const picker = createEmojiPicker(form.querySelector('[data-social-emoji]'), {target: body});
    const audience = createAudienceSelector(form.querySelector('[data-social-audience]'));

    const open = ({mode = 'create', post = null, updateUrl = null, card = null, intent = 'text', trigger = null} = {}) => {
        reset();
        state.mode = mode;
        state.intent = intent;
        state.trigger = trigger;
        audience?.set(EVERYONE);

        if (mode === 'edit' && post) {
            state.updateUrl = updateUrl;
            state.card = card;
            state.existing = Array.isArray(post.media) ? post.media : [];
            body.value = post.body || '';
            audience?.set(post.visibility || EVERYONE, {departmentLabel: post.department_label || null});
        }

        title.textContent = mode === 'edit' ? 'แก้ไขโพสต์' : 'สร้างโพสต์';
        submit.querySelector('[data-social-post-submit-label]').textContent = mode === 'edit' ? 'บันทึก' : 'โพสต์';
        renderPreviews();
        updateCounter();
        modal()?.show();

        // เปิดตัวเลือกไฟล์ใน click เดิมของผู้ใช้ — เบราว์เซอร์ไม่ยอมเปิดตัวเลือกไฟล์นอก user gesture
        if (intent === 'image') fileInput.click();
    };

    const send = async () => {
        if (state.submitting) return;

        const text = body.value.trim();
        if (text === '' && imageCount() === 0) {
            showError('กรุณาพิมพ์ข้อความหรือเลือกรูปภาพอย่างน้อย 1 รูป');
            body.focus();
            return;
        }
        if (text.length > limits.maxLength) {
            showError(`ข้อความโพสต์ต้องไม่เกิน ${limits.maxLength.toLocaleString('en-US')} ตัวอักษร`);
            return;
        }

        const data = new win.FormData();
        data.set('body', body.value);
        if (audience) data.set('visibility', audience.value());
        state.files.forEach((item) => data.append('images[]', item.file));
        state.removed.forEach((id) => data.append('remove_media[]', String(id)));

        state.submitting = true;
        showError(null);
        setBusy(submit, true, state.mode === 'edit' ? 'กำลังบันทึก...' : 'กำลังโพสต์...');

        try {
            const isEdit = state.mode === 'edit';
            const payload = await request(isEdit ? state.updateUrl : modalElement.dataset.storeUrl, {
                method: isEdit ? 'PATCH' : 'POST',
                body: data,
                fetchImpl,
                doc,
            });

            const {mode, card} = state;
            state.submitting = false;
            setBusy(submit, false);
            modal()?.hide();
            const inserted = onSaved(payload, mode, card);
            if (mode === 'edit' && inserted) {
                state.trigger = inserted.querySelector('[data-bs-toggle="dropdown"]') ?? state.trigger;
            }
            toast(swal, 'success', payload.message || (mode === 'edit' ? 'บันทึกการแก้ไขแล้ว' : 'โพสต์เรียบร้อยแล้ว'));
        } catch (error) {
            state.submitting = false;
            setBusy(submit, false);
            showError(error.message);
            if (error.status === 404 || error.status === 403) alertError(swal, error.message);
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        send();
    });

    body.addEventListener('input', () => {
        updateCounter();
        autoGrow();
        if (errorBox.hidden === false && body.value.trim() !== '') showError(null);
    });

    form.querySelector('[data-social-post-pick-image]')?.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => addFiles(fileInput.files || []));

    previews.addEventListener('click', (event) => {
        const button = event.target.closest('[data-social-remove-image]');
        if (! button) return;

        if (button.dataset.socialRemoveImage === 'existing') {
            state.removed.add(Number(button.dataset.value));
        } else {
            const [removed] = state.files.splice(Number(button.dataset.value), 1);
            if (removed) win.URL?.revokeObjectURL?.(removed.url);
        }
        renderPreviews();
        showError(null);
        (previews.querySelector('[data-social-remove-image]') || form.querySelector('[data-social-post-pick-image]'))?.focus();
    });

    // ระหว่างส่งห้ามปิด modal — ผลลัพธ์จะมาถึงโดยไม่มีที่ให้แสดง
    modalElement.addEventListener('hide.bs.modal', (event) => {
        if (state.submitting) event.preventDefault();
        picker?.close({restoreFocus: false});
        audience?.close({restoreFocus: false});
    });

    modalElement.addEventListener('shown.bs.modal', () => {
        autoGrow();
        if (state.intent === 'emoji') {
            picker?.open();
        } else if (state.intent !== 'image') {
            body.focus();
            body.setSelectionRange?.(body.value.length, body.value.length);
        }
    });

    modalElement.addEventListener('hidden.bs.modal', () => {
        const trigger = state.trigger;
        reset();
        if (trigger?.isConnected) trigger.focus();
    });

    return {open, state, addFiles, send};
}
