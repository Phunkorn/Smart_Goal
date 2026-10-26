/**
 * ขั้นตอนของฟอร์ม Smart Ticket — hook ชุดเดียวกันทุกที่:
 *   modal แจ้งปัญหา IT (tickets/components/submit-modal.blade.php, 3 ขั้น, #new-ticket)
 *   หน้าสาธารณะ /support (tickets/public/create.blade.php, 2 ขั้น ไม่มี modal)
 *   modal ปิดงานของเจ้าหน้าที่ (tickets/components/close-modal.blade.php, 3 ขั้น, #close-ticket)
 * หนึ่งหน้ามีได้หลายฟอร์ม — ผูกแยกกันทุกตัว hash ของ modal มาจาก data-ticket-stepper-hash (ค่าเริ่มต้น #new-ticket)
 *
 * แยกจาก tickets/form.js ซึ่งดูแลสาขา "อื่น ๆ" รูปภาพ และการกันกดส่งซ้ำของฟอร์มเดียวกัน
 *
 * - "ถัดไป" ตรวจเฉพาะช่องในขั้นปัจจุบัน (constraint validation ของเบราว์เซอร์ ไม่ปิด required)
 * - ช่องที่ไม่ผ่านแสดงข้อความภาษาไทยใต้ช่อง (แทนฟองข้อความของเบราว์เซอร์ที่ภาษาตามเครื่อง) แล้วโฟกัสช่องแรกที่ผิด
 *   ข้อความหายเมื่อผู้ใช้แก้ช่องนั้น · ยังเป็น constraint validation เดิม ไม่ใช่กติกาชุดที่สอง
 * - ขั้นที่ซ่อนอยู่มีช่องผิดตอนกดส่ง: เบราว์เซอร์ยิง invalid → เปิดขั้นนั้นทันที เบราว์เซอร์จึงโฟกัสช่องนั้นได้
 * - ส่งไม่ผ่านที่ server (data-open-on-load): เปิด modal ที่ขั้นแรกที่มีข้อผิดพลาด ค่าที่กรอกกลับมาจาก old()
 * - ปุ่มย้อนกลับของเบราว์เซอร์ปิด modal (#new-ticket) และรีเฟรชแล้วเปิดค้างไว้เหมือนเดิม
 * - Bootstrap Modal ดูแล focus trap, Escape และฉากหลัง การเคลื่อนไหวปิดเองเมื่อ prefers-reduced-motion
 * - ขั้นสุดท้ายเติมสรุป [data-ticket-review="ชื่อช่อง"] จากค่าที่กรอก (ข้อความ หรือจำนวนรูปของช่องไฟล์)
 * - ปุ่ม [data-ticket-quick-note="ข้อความ"] เติมข้อความสำเร็จรูปต่อท้ายช่องข้อความของขั้นเดียวกัน ([data-ticket-quick-note-target])
 */

export const MODAL_HASH = '#new-ticket';

const ERROR_SELECTOR = '.is-invalid, .ticket-form__error';

const CLIENT_ERROR_ATTRIBUTE = 'data-ticket-client-error';

/** ชื่อช่องจากป้าย (ตัดดอกจันและ "(ไม่บังคับ)") ใช้ประกอบข้อความผิดพลาด */
function fieldName(control) {
    const label = control.id ? control.ownerDocument.querySelector(`label[for="${control.id}"]`) : null;

    return (label?.textContent ?? '').replace(/\*/g, '').replace(/\(ไม่บังคับ\)/g, '').replace(/\s+/g, ' ').trim().replace(/^ระบุ/, '');
}

/** ข้อความผิดพลาดของช่องหนึ่ง — ตามเหตุที่เบราว์เซอร์รายงาน (ไม่กรอก / สั้นเกินไป / ไม่ตรงรูปแบบ) */
export function fieldErrorMessage(control) {
    const name = fieldName(control);
    const {valueMissing, tooShort, patternMismatch} = control.validity;

    if (valueMissing) return control.tagName === 'SELECT' ? `กรุณาเลือก${name}` : `กรุณาระบุ${name}`;
    if (tooShort) return `${name}ต้องมีอย่างน้อย ${control.minLength} ตัวอักษร`;
    // ข้อความภาษาไทยจากช่องเอง (data-pattern-message) แทนข้อความภาษาอังกฤษของเบราว์เซอร์
    if (patternMismatch && control.dataset.patternMessage) return control.dataset.patternMessage;

    return control.validationMessage;
}

function showFieldError(control) {
    clearFieldError(control);

    const error = control.ownerDocument.createElement('p');
    error.className = 'ticket-form__error';
    error.id = `${control.id || control.name}-client-error`;
    error.setAttribute(CLIENT_ERROR_ATTRIBUTE, '');
    error.textContent = fieldErrorMessage(control);
    control.after(error);

    control.classList.add('is-invalid');
    control.setAttribute('aria-invalid', 'true');
    control.setAttribute('aria-describedby', [control.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
}

function clearFieldError(control) {
    const error = control.parentElement?.querySelector(`[${CLIENT_ERROR_ATTRIBUTE}][id="${control.id || control.name}-client-error"]`);
    if (! error) return;

    control.setAttribute('aria-describedby', (control.getAttribute('aria-describedby') ?? '').split(/\s+/).filter((id) => id && id !== error.id).join(' '));
    if (control.getAttribute('aria-describedby') === '') control.removeAttribute('aria-describedby');
    control.removeAttribute('aria-invalid');
    control.classList.remove('is-invalid');
    error.remove();
}

/** ข้อผิดพลาดที่ server ส่งกลับมาจริง — ช่องข้อความ error ที่ซ่อนอยู่หรือว่าง (เช่น ของ photo picker) ไม่นับ */
function hasServerError(step) {
    return Array.from(step.querySelectorAll(ERROR_SELECTOR))
        .some((element) => ! element.hidden && (element.classList.contains('is-invalid') || element.textContent.trim() !== ''));
}

/** ช่องที่ผู้ใช้กรอกได้ในขั้นหนึ่ง — ข้ามช่องที่ถูกซ่อนด้วยกลุ่มย่อย (เช่น ช่อง "อื่น ๆ" ที่ไม่ได้เลือก) */
export function stepControls(step) {
    return Array.from(step.querySelectorAll('input, select, textarea'))
        .filter((control) => control.type !== 'hidden' && ! control.disabled)
        .filter((control) => {
            const hiddenAncestor = control.closest('[hidden]');

            return ! hiddenAncestor || hiddenAncestor === step;
        });
}

/** ทุกช่องในขั้นที่ยังไม่ผ่านการตรวจของเบราว์เซอร์ — checkValidity ยิง event invalid ของแต่ละช่อง จึงแสดงข้อความครบทุกช่องพร้อมกัน */
export function invalidControls(step) {
    return stepControls(step).filter((control) => ! control.checkValidity());
}

/** ช่องแรกที่ยังไม่ผ่านการตรวจ หรือ null */
export function firstInvalidControl(step) {
    return invalidControls(step)[0] ?? null;
}

/** index ของขั้นแรกที่มีข้อผิดพลาดจาก server — ข้อผิดพลาดระดับฟอร์ม (rate limit ฯลฯ) ไปขั้นสุดท้ายเพื่อส่งใหม่ */
export function initialStepIndex(steps, form) {
    const index = steps.findIndex(hasServerError);
    if (index !== -1) return index;

    return form.querySelector('.ticket-form__alert') ? steps.length - 1 : 0;
}

/** สรุปของช่องหนึ่งสำหรับขั้นตรวจทาน — ช่องไฟล์เป็นจำนวนรูป ช่องอื่นเป็นข้อความที่กรอก */
export function reviewText(control) {
    if (! control) return '';
    if (control.type === 'file') {
        const count = control.files?.length ?? 0;

        return count > 0 ? `แนบ ${count} รูป` : 'ไม่มีรูป';
    }

    return String(control.value ?? '').trim();
}

/** เติมข้อความสำเร็จรูปต่อท้ายช่อง (ขึ้นบรรทัดใหม่ถ้ามีข้อความอยู่แล้ว ไม่เติมซ้ำ) */
export function appendQuickNote(current, note) {
    const text = String(current ?? '').trimEnd();
    if (text.split('\n').some((line) => line.trim() === note)) return text;

    return text === '' ? note : `${text}\n${note}`;
}

/**
 * ผูกทุกฟอร์ม [data-ticket-stepper] ที่ยังไม่ผูก — เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ
 * คืนตัวควบคุมของฟอร์มแรกที่เพิ่งผูก (หรือ null เมื่อไม่มีฟอร์มใหม่)
 *
 * @param {ParentNode} root
 * @param {{modalApi?: {getOrCreateInstance: Function}, history?: History, location?: Location}} options
 */
export function initTicketStepper(root = document, {
    modalApi = globalThis.window?.bootstrap?.Modal,
    history = globalThis.window?.history,
    location = globalThis.window?.location,
} = {}) {
    const controllers = Array.from(root.querySelectorAll('[data-ticket-stepper]'))
        .filter((form) => form.dataset.ticketStepperReady !== 'true')
        .map((form) => bindStepper(root, form, {modalApi, history, location}));

    return controllers[0] ?? null;
}

function bindStepper(root, form, {modalApi, history, location}) {
    form.dataset.ticketStepperReady = 'true';

    const modal = form.closest('.modal');
    const hash = form.dataset.ticketStepperHash || MODAL_HASH;
    const steps = Array.from(form.querySelectorAll('[data-ticket-step]'));
    const indicators = Array.from(form.querySelectorAll('[data-ticket-step-indicator]'));
    const back = form.querySelector('[data-ticket-step-back]');
    const next = form.querySelector('[data-ticket-step-next]');
    const submit = form.querySelector('[data-ticket-step-submit]');
    const status = form.querySelector('[data-ticket-step-status]');
    const labels = indicators.map((item) => item.querySelector('.ticket-stepper__label')?.textContent.trim() ?? '');
    let current = 0;

    const show = (index, {focus = false} = {}) => {
        current = Math.max(0, Math.min(index, steps.length - 1));
        steps.forEach((step, position) => { step.hidden = position !== current; });
        indicators.forEach((item, position) => {
            item.classList.toggle('is-complete', position < current);
            if (position === current) item.setAttribute('aria-current', 'step');
            else item.removeAttribute('aria-current');
        });

        const last = current === steps.length - 1;
        if (back) back.hidden = current === 0;
        if (next) next.hidden = last;
        if (submit) submit.hidden = ! last;
        if (status) status.textContent = `ขั้นที่ ${current + 1} จาก ${steps.length}: ${labels[current] ?? ''}`;
        if (last) {
            form.querySelectorAll('[data-ticket-review]').forEach((target) => {
                target.textContent = reviewText(form.elements.namedItem(target.dataset.ticketReview)) || '—';
            });
        }

        // บอกผู้ใช้ (และ screen reader) ว่ามาถึงขั้นไหน — เฉพาะเมื่อผู้ใช้เปลี่ยนขั้นเอง
        if (focus) steps[current].querySelector('.ticket-step__title')?.focus({preventScroll: true});
    };

    // invalidControls ยิง event invalid ของทุกช่องที่ผิด — ตัวฟังด้านล่างแสดงข้อความและโฟกัสช่องแรกให้
    next?.addEventListener('click', () => {
        if (invalidControls(steps[current]).length > 0) return;

        show(current + 1, {focus: true});
    });

    back?.addEventListener('click', () => show(current - 1, {focus: true}));

    // ช่องที่ไม่ผ่านการตรวจ (กด "ถัดไป" หรือกดส่ง): แสดงข้อความใต้ช่องแทนฟองของเบราว์เซอร์ (preventDefault)
    // ช่องแรกของรอบนี้เป็นตัวตัดสินว่าจะเปิดขั้นไหนและโฟกัสที่ไหน — ช่องถัด ๆ ไปแสดงข้อความอย่างเดียว
    // (invalid ไม่ bubble จึงฟังแบบ capture)
    let firstInvalid = null;
    form.addEventListener('invalid', (event) => {
        const control = event.target;
        event.preventDefault();
        showFieldError(control);
        if (firstInvalid) return;

        firstInvalid = control;
        const step = control.closest('[data-ticket-step]');
        const index = steps.indexOf(step);
        if (index !== -1 && index !== current && step.hidden) show(index);

        queueMicrotask(() => {
            firstInvalid?.focus();
            firstInvalid = null;
        });
    }, true);

    // แก้ช่องไหน ข้อความของช่องนั้นหาย
    form.addEventListener('input', (event) => clearFieldError(event.target));
    form.addEventListener('change', (event) => clearFieldError(event.target));

    // ข้อความสำเร็จรูป — เติมต่อท้ายช่องข้อความของขั้นเดียวกัน แล้วโฟกัสช่องนั้นให้แก้ต่อได้
    form.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-ticket-quick-note]');
        if (! chip || ! form.contains(chip)) return;
        const target = chip.closest('[data-ticket-step]')?.querySelector('[data-ticket-quick-note-target]');
        if (! target) return;
        target.value = appendQuickNote(target.value, chip.dataset.ticketQuickNote);
        target.dispatchEvent(new target.ownerDocument.defaultView.Event('input', {bubbles: true}));
        target.focus();
    });

    show(initialStepIndex(steps, form));

    if (modal && modalApi) {
        const opener = modal.id ? root.querySelector(`[data-bs-target="#${modal.id}"]`) : null;
        bindModal(modal, {modalApi, history, location, opener, hash});
    }

    return {show, current: () => current};
}

/** เปิดค้างหลังส่งไม่ผ่าน · hash ของ modal สำหรับปุ่มย้อนกลับ/รีเฟรช · คืนโฟกัสให้ปุ่มเปิดเมื่อไม่ได้เปิดจากปุ่ม */
function bindModal(modal, {modalApi, history, location, opener, hash}) {
    const instance = modalApi.getOrCreateInstance(modal);
    let closingFromHistory = false;

    modal.addEventListener('shown.bs.modal', () => {
        if (location && history && location.hash !== hash) history.pushState({ticketModal: true}, '', hash);
    });

    modal.addEventListener('hidden.bs.modal', () => {
        if (! closingFromHistory && location && history && location.hash === hash) {
            if (history.state?.ticketModal) history.back();
            else history.replaceState(null, '', location.pathname + location.search);
        }
        closingFromHistory = false;
        if (opener && ! modal.contains(document.activeElement) && document.activeElement === document.body) opener.focus();
    });

    globalThis.window?.addEventListener('popstate', () => {
        if (location.hash !== hash && modal.classList.contains('show')) {
            closingFromHistory = true;
            instance.hide();
        } else if (location.hash === hash && ! modal.classList.contains('show')) {
            instance.show();
        }
    });

    if (modal.hasAttribute('data-open-on-load') || location?.hash === hash) instance.show();
}
