import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {initPublicTicketForm} from '../../resources/js/pages/tickets/public.js';
import {fieldErrorMessage} from '../../resources/js/pages/tickets/stepper.js';
import {click, mountDom} from './helpers/dom.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const stripCssComments = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '');

/**
 * hook ชุดเดียวกับ tickets/public/create.blade.php (+ location-field / problem-fields / photo-picker)
 * ขั้นที่ 1: ชื่อ สาขา รายละเอียดปัญหา (บังคับทั้งสาม) · ขั้นที่ 2: เบอร์โทร 10 หลัก (บังคับ) รหัสรีโมทและรูปภาพ (ไม่บังคับ)
 */
function publicMarkup({serverErrorInStep = null, formAlert = false} = {}) {
    return `<!doctype html><html><body>
<form data-ticket-form data-ticket-stepper method="POST" action="/support">
  <ol>
    <li data-ticket-step-indicator="1" aria-current="step"><span class="ticket-stepper__label">ข้อมูลและปัญหา</span></li>
    <li data-ticket-step-indicator="2"><span class="ticket-stepper__label">รายละเอียดเพิ่มเติม</span></li>
  </ol>
  <p data-ticket-step-status></p>
  ${formAlert ? '<div class="ticket-form__alert" role="alert">ส่งถี่เกินไป</div>' : ''}
  <section data-ticket-step="1">
    <h2 class="ticket-step__title" tabindex="-1">ข้อมูลและปัญหา</h2>
    <div class="ticket-form__field">
      <label for="ticket-requester-name"><span class="ticket-form__icon"></span><span>ชื่อผู้แจ้ง <span>*</span></span></label>
      <input id="ticket-requester-name" name="requester_name" required class="${serverErrorInStep === 1 ? 'is-invalid' : ''}">
      ${serverErrorInStep === 1 ? '<p class="ticket-form__error">กรุณาระบุชื่อผู้แจ้ง</p>' : ''}
    </div>
    <div class="ticket-form__field">
      <label for="ticket-location">สาขา/สถานที่ <span>*</span></label>
      <select id="ticket-location" name="location_id" required data-ticket-location><option value="" selected>เลือกสาขา/สถานที่</option><option value="1">สาขา 1</option><option value="other">อื่น ๆ</option></select>
      <div data-ticket-location-other hidden><label for="ticket-location-name">ระบุสาขา/สถานที่</label><input id="ticket-location-name" name="location_name"></div>
    </div>
    <div class="ticket-form__field">
      <label for="ticket-description">รายละเอียดปัญหา <span>*</span></label>
      <textarea id="ticket-description" name="description" required minlength="10"></textarea>
    </div>
  </section>
  <section data-ticket-step="2" hidden>
    <h2 class="ticket-step__title" tabindex="-1">รายละเอียดเพิ่มเติม</h2>
    <div class="ticket-form__field">
      <label for="ticket-contact"><span class="ticket-form__icon"></span><span>เบอร์โทรติดต่อกลับ <span>*</span></span></label>
      <input id="ticket-contact" type="tel" name="contact" inputmode="numeric" required pattern="0[0-9]{9}" data-ticket-digits="10"
        data-pattern-message="เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0" class="${serverErrorInStep === 2 ? 'is-invalid' : ''}">
      ${serverErrorInStep === 2 ? '<p class="ticket-form__error">เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0</p>' : ''}
    </div>
    <div class="ticket-form__field">
      <label for="ticket-remote">AnyDesk / รหัสรีโมท <span>(ไม่บังคับ)</span></label>
      <input id="ticket-remote" name="remote_access">
    </div>
    <div data-ticket-photos data-max-files="3" data-max-bytes="10485760" data-accept="image/jpeg,image/png">
      <input type="file" name="photos[]" multiple data-ticket-photos-input>
      <ul data-ticket-photos-list></ul>
      <p data-ticket-photos-error hidden></p>
    </div>
  </section>
  <div>
    <button type="button" data-ticket-step-back hidden>ย้อนกลับ</button>
    <button type="button" data-ticket-step-next>ถัดไป</button>
    <div data-ticket-step-submit hidden><button type="submit" data-ticket-submit><span data-ticket-submit-label>ส่งแจ้งปัญหา</span><span data-ticket-submit-spinner hidden></span></button></div>
  </div>
</form>
</body></html>`;
}

const DESCRIPTION = 'เครื่องพิมพ์ชั้น 2 พิมพ์ไม่ออก';
const visibleStep = (document) => document.querySelector('[data-ticket-step]:not([hidden])').dataset.ticketStep;
const type = (window, control, value) => {
    control.value = value;
    control.dispatchEvent(new window.Event('input', {bubbles: true}));
};
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));
const clientError = (document, id) => document.getElementById(`${id}-client-error`);

/** กรอกขั้นที่ 1 ให้ผ่านครบ (ชื่อ สาขา รายละเอียด) */
function fillStep1(document, window) {
    type(window, document.getElementById('ticket-requester-name'), 'สมชาย ใจดี');
    document.getElementById('ticket-location').value = '1';
    type(window, document.getElementById('ticket-description'), DESCRIPTION);
}

test('step 1 blocks "next" with Thai messages under every missing field and focuses the first one', async () => {
    const dom = mountDom(publicMarkup());
    try {
        const {document, window} = dom;
        initPublicTicketForm(document);
        const name = document.getElementById('ticket-requester-name');

        click(document.querySelector('[data-ticket-step-next]'));
        await settle();

        assert.equal(visibleStep(document), '1', 'ไม่เปลี่ยนขั้น และไม่ส่ง Ticket');
        assert.equal(clientError(document, 'ticket-requester-name').textContent, 'กรุณาระบุชื่อผู้แจ้ง');
        assert.equal(clientError(document, 'ticket-location').textContent, 'กรุณาเลือกสาขา/สถานที่');
        assert.equal(clientError(document, 'ticket-description').textContent, 'กรุณาระบุรายละเอียดปัญหา', 'รายละเอียดปัญหาอยู่ขั้นที่ 1');
        assert.ok(name.classList.contains('is-invalid'));
        assert.equal(name.getAttribute('aria-invalid'), 'true');
        assert.equal(name.getAttribute('aria-describedby'), 'ticket-requester-name-client-error');
        assert.equal(document.activeElement, name, 'โฟกัสช่องแรกที่ผิด');

        // แก้ช่องไหน ข้อความของช่องนั้นหาย ช่องอื่นยังอยู่
        type(window, name, 'สมชาย ใจดี');
        assert.equal(clientError(document, 'ticket-requester-name'), null);
        assert.equal(name.classList.contains('is-invalid'), false);
        assert.equal(name.hasAttribute('aria-describedby'), false);
        assert.ok(clientError(document, 'ticket-location'));

        click(document.querySelector('[data-ticket-step-next]'));
        await settle();
        assert.equal(visibleStep(document), '1');
        assert.equal(document.activeElement, document.getElementById('ticket-location'), 'ช่องแรกที่ยังผิดคือสาขา');
    } finally {
        dom.cleanup();
    }
});

test('a valid step 1 opens step 2, back returns, and nothing the user typed is lost', () => {
    const dom = mountDom(publicMarkup());
    try {
        const {document, window} = dom;
        initPublicTicketForm(document);
        const next = document.querySelector('[data-ticket-step-next]');
        const back = document.querySelector('[data-ticket-step-back]');
        const submit = document.querySelector('[data-ticket-step-submit]');

        assert.equal(visibleStep(document), '1');
        assert.deepEqual([back.hidden, next.hidden, submit.hidden], [true, false, true]);
        assert.equal(document.querySelector('[data-ticket-step-status]').textContent, 'ขั้นที่ 1 จาก 2: ข้อมูลและปัญหา');

        fillStep1(document, window);
        click(next);

        assert.equal(visibleStep(document), '2');
        assert.deepEqual([back.hidden, next.hidden, submit.hidden], [false, true, false]);
        assert.equal(document.querySelector('[data-ticket-step-status]').textContent, 'ขั้นที่ 2 จาก 2: รายละเอียดเพิ่มเติม');
        const [first, second] = document.querySelectorAll('[data-ticket-step-indicator]');
        assert.ok(first.classList.contains('is-complete'));
        assert.equal(second.getAttribute('aria-current'), 'step');
        assert.equal(document.activeElement.textContent, 'รายละเอียดเพิ่มเติม', 'โฟกัสหัวข้อของขั้นใหม่');

        type(window, document.getElementById('ticket-contact'), '081-234-5678');
        click(back);

        assert.equal(visibleStep(document), '1');
        assert.equal(document.getElementById('ticket-requester-name').value, 'สมชาย ใจดี');
        assert.equal(document.getElementById('ticket-location').value, '1');
        assert.equal(document.getElementById('ticket-description').value, DESCRIPTION);
        // เบอร์โทรเก็บเป็นตัวเลขล้วน (form.js ตัดขีด) — ค่าที่พิมพ์ไว้ยังอยู่ครบหลังสลับขั้น
        assert.equal(document.getElementById('ticket-contact').value, '0812345678', 'ค่าในขั้นที่ซ่อนอยู่ไม่หาย');
        assert.equal(first.classList.contains('is-complete'), false);
    } finally {
        dom.cleanup();
    }
});

test('the "other" location asks for the typed place before going on', async () => {
    const dom = mountDom(publicMarkup());
    try {
        const {document, window} = dom;
        initPublicTicketForm(document);
        type(window, document.getElementById('ticket-requester-name'), 'สมชาย');
        type(window, document.getElementById('ticket-description'), DESCRIPTION);
        const select = document.getElementById('ticket-location');
        select.value = 'other';
        select.dispatchEvent(new window.Event('change', {bubbles: true}));

        click(document.querySelector('[data-ticket-step-next]'));
        await settle();

        assert.equal(visibleStep(document), '1');
        assert.equal(clientError(document, 'ticket-location-name').textContent, 'กรุณาระบุสาขา/สถานที่');
        assert.equal(document.activeElement, document.getElementById('ticket-location-name'));
    } finally {
        dom.cleanup();
    }
});

test('step 2 requires the phone: without it nothing is sent and the phone gets a Thai message', async () => {
    const dom = mountDom(publicMarkup());
    try {
        const {document, window} = dom;
        initPublicTicketForm(document);
        fillStep1(document, window);
        click(document.querySelector('[data-ticket-step-next]'));

        const form = document.querySelector('form');
        let submitted = 0;
        form.addEventListener('submit', (event) => { submitted += 1; event.preventDefault(); });
        document.querySelector('[data-ticket-submit]').dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        await settle();

        assert.equal(submitted, 0);
        assert.notEqual(form.dataset.submitting, 'true');
        assert.equal(visibleStep(document), '2');
        assert.equal(document.activeElement, document.getElementById('ticket-contact'));
        assert.equal(clientError(document, 'ticket-contact')?.textContent, 'กรุณาระบุเบอร์โทรติดต่อกลับ');
        assert.equal(clientError(document, 'ticket-remote'), null, 'รหัสรีโมทไม่บังคับ');

        // เบอร์ไม่ครบ 10 หลัก → ข้อความภาษาไทยของรูปแบบ ไม่ใช่ข้อความอังกฤษของเบราว์เซอร์
        type(window, document.getElementById('ticket-contact'), '081234');
        document.querySelector('[data-ticket-submit]').dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        await settle();
        assert.equal(submitted, 0);
        assert.equal(clientError(document, 'ticket-contact')?.textContent, 'เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0');
    } finally {
        dom.cleanup();
    }
});

test('with a 10-digit phone, step 2 sends once and shows the sending state', async () => {
    const dom = mountDom(publicMarkup());
    try {
        const {document, window} = dom;
        initPublicTicketForm(document);
        fillStep1(document, window);
        click(document.querySelector('[data-ticket-step-next]'));
        type(window, document.getElementById('ticket-contact'), '0812345678');

        const form = document.querySelector('form');
        let submitted = 0;
        form.addEventListener('submit', (event) => { submitted += 1; event.preventDefault(); });
        const button = document.querySelector('[data-ticket-submit]');
        button.dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        await settle();

        assert.equal(submitted, 1);
        assert.equal(form.dataset.submitting, 'true');
        assert.equal(button.disabled, true, 'กันกดซ้ำ');
        assert.equal(document.querySelector('[data-ticket-submit-label]').textContent, 'กำลังส่ง…');
        assert.equal(document.querySelector('[data-ticket-client-error]'), null);
    } finally {
        dom.cleanup();
    }
});

test('a field left empty in step 1 reopens step 1 when submitting from step 2, and nothing starts sending', async () => {
    const dom = mountDom(publicMarkup());
    try {
        const {document, window} = dom;
        const stepper = initPublicTicketForm(document);
        stepper.show(1);
        type(window, document.getElementById('ticket-contact'), '081-234-5678');

        const form = document.querySelector('form');
        let submitted = false;
        form.addEventListener('submit', (event) => { submitted = true; event.preventDefault(); });
        document.querySelector('[data-ticket-submit]').dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        await settle();

        assert.equal(submitted, false);
        assert.notEqual(form.dataset.submitting, 'true', 'ยังไม่ขึ้นสถานะกำลังส่ง');
        assert.equal(visibleStep(document), '1', 'เปิดขั้นของช่องแรกที่ผิด (ชื่อผู้แจ้ง)');
        assert.equal(document.activeElement, document.getElementById('ticket-requester-name'));
        assert.ok(clientError(document, 'ticket-location'), 'ช่องอื่นที่ผิดก็แสดงข้อความ');
        assert.ok(clientError(document, 'ticket-description'));
    } finally {
        dom.cleanup();
    }
});

test('the phone field keeps digits only and at most 10, so a pasted "081-234-5678" stays complete', () => {
    const dom = mountDom(publicMarkup());
    try {
        const {document, window} = dom;
        initPublicTicketForm(document);
        const phone = document.getElementById('ticket-contact');

        for (const [typed, expected] of [['081-234-5678', '0812345678'], ['081 234 5678', '0812345678'], ['+66 81a', '6681'], ['08123456789999', '0812345678']]) {
            type(window, phone, typed);
            assert.equal(phone.value, expected, typed);
        }

        // ช่องอื่นไม่ถูกแตะ
        type(window, document.getElementById('ticket-remote'), '123 456 789');
        assert.equal(document.getElementById('ticket-remote').value, '123 456 789');
    } finally {
        dom.cleanup();
    }
});

test('a server error opens its own step, and a whole-form error opens the last step', () => {
    for (const [options, expected] of [[{serverErrorInStep: 1}, '1'], [{serverErrorInStep: 2}, '2'], [{formAlert: true}, '2'], [{}, '1']]) {
        const dom = mountDom(publicMarkup(options));
        try {
            initPublicTicketForm(dom.document);
            assert.equal(visibleStep(dom.document), expected, JSON.stringify(options));
        } finally {
            dom.cleanup();
        }
    }
});

test('messages follow the browser reason and the label of the field', () => {
    const dom = mountDom(publicMarkup());
    try {
        const {document} = dom;
        assert.equal(fieldErrorMessage(document.getElementById('ticket-requester-name')), 'กรุณาระบุชื่อผู้แจ้ง');
        assert.equal(fieldErrorMessage(document.getElementById('ticket-location')), 'กรุณาเลือกสาขา/สถานที่');
        assert.equal(fieldErrorMessage(document.getElementById('ticket-description')), 'กรุณาระบุรายละเอียดปัญหา');
    } finally {
        dom.cleanup();
    }
});

test('initialising twice binds each behaviour once', async () => {
    const dom = mountDom(publicMarkup());
    try {
        const {document} = dom;
        assert.ok(initPublicTicketForm(document));
        assert.equal(initPublicTicketForm(document), null);

        click(document.querySelector('[data-ticket-step-next]'));
        await settle();
        assert.equal(document.querySelectorAll('[data-ticket-client-error]').length, 3, 'ข้อความละหนึ่งช่อง ไม่ซ้ำ');
    } finally {
        dom.cleanup();
    }
});

test('the public Blade has every hook the form and stepper need, and the entry is registered', () => {
    const create = read('resources/views/tickets/public/create.blade.php');

    for (const hook of ['data-ticket-form', 'data-ticket-stepper', 'data-ticket-step-indicator="', 'data-ticket-step="1"', 'data-ticket-step="2"',
        'data-ticket-step-status', 'data-ticket-step-back', 'data-ticket-step-next', 'data-ticket-step-submit', 'ticket-step__title',
        'resources/js/pages/tickets/public.js', 'ticket-stepper', 'location-field', 'problem-fields', 'photo-picker', 'submit-button']) {
        assert.ok(create.includes(hook), hook);
    }
    assert.doesNotMatch(create, /<script(?![^>]*type="application\/json")|\sstyle="|\b(alert|confirm|prompt)\(/);
    assert.match(read('vite.config.js'), /'resources\/js\/pages\/tickets\/public\.js'/);

    // รายละเอียดปัญหา (problem-fields โดยไม่มีรูป) อยู่ขั้นที่ 1 · ช่องทางติดต่อกลับและรูปอยู่ขั้นที่ 2
    const stepTwo = create.indexOf('data-ticket-step="2"');
    assert.ok(create.indexOf("'withPhotos' => false") < stepTwo, 'รายละเอียดอยู่ก่อนขั้นที่ 2');
    assert.ok(create.indexOf('for="ticket-contact"') > stepTwo, 'เบอร์โทรอยู่หลังเริ่มขั้นที่ 2');
    assert.ok(create.indexOf('for="ticket-remote"') > create.indexOf('for="ticket-contact"'), 'รหัสรีโมทต่อจากเบอร์โทร');
    for (const hook of ['type="tel" name="contact" inputmode="numeric"', 'pattern="0[0-9]{9}" data-ticket-digits="10"',
        'data-pattern-message="เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0"', 'name="remote_access" inputmode="numeric"',
        // รหัสรีโมทรับเฉพาะเลขเครื่อง ตรงกับ TicketSubmissionService::REMOTE_ACCESS_PATTERN — ไม่รับรหัสผ่าน
        'pattern="[0-9][0-9 \\-]{5,19}"', 'กรอกเฉพาะเลขเครื่อง ห้ามใส่รหัสผ่าน']) {
        assert.ok(create.includes(hook), hook);
    }
    assert.ok(create.indexOf("@include('tickets.components.photo-picker'") > stepTwo, 'รูปอยู่ขั้นที่ 2');

    const entry = read('resources/js/pages/tickets/public.js').replace(/\/\*[\s\S]*?\*\//g, '');
    assert.doesNotMatch(entry, /\bfetch\(|XMLHttpRequest|setInterval/);
});

test('the success page shows the summary, one way out, and no tracking or contact promises', () => {
    const done = read('resources/views/tickets/public/submitted.blade.php').replace(/\{\{--[\s\S]*?--\}\}/g, '');

    for (const text of ['ส่งข้อมูลสำเร็จ', 'ทีม IT ได้รับข้อมูลของคุณแล้ว', 'เลขที่ Ticket', 'ชื่อผู้แจ้ง', 'สาขา / สถานที่', 'แจ้งปัญหาใหม่']) {
        assert.ok(done.includes(text), text);
    }
    assert.match(done, /route\('support\.create'\)/);
    assert.doesNotMatch(done, /ติดต่อทีม IT เพิ่มเติม|กลับหน้าหลัก|ติดตามสถานะ/);
    assert.equal((done.match(/<a /g) ?? []).length, 1);
});

test('the stepper styles are one shared file used by the modal and the public page', () => {
    const stepper = stripCssComments(read('resources/css/pages/tickets/stepper.css'));

    assert.match(read('resources/css/pages/tickets.css'), /@import '\.\/tickets\/stepper\.css';/);
    assert.match(read('resources/css/pages/tickets-public.css'), /@import '\.\/tickets\/stepper\.css';/);
    assert.match(stepper, /\.ticket-stepper \{[^}]*grid-auto-flow: column;/s, 'จำนวนขั้นมาจาก markup ไม่ใช่ตัวเลขใน CSS');
    assert.doesNotMatch(stripCssComments(read('resources/css/pages/tickets/submit-modal.css')), /\.ticket-stepper__(step|index|label|done|number)|\.ticket-step(__| \{)/, 'ไม่มี CSS ของแถบขั้นตอนซ้ำใน submit-modal.css');
    assert.doesNotMatch(stepper, /!important|gradient\(/);
});

/** jsdom ไม่มี showModal/close ของ <dialog> — จำลองพฤติกรรมพื้นฐานของเบราว์เซอร์ (open + เหตุการณ์ close) */
function stubDialog(window) {
    window.HTMLDialogElement.prototype.showModal = function showModal() { this.setAttribute('open', ''); };
    window.HTMLDialogElement.prototype.close = function close() {
        if (! this.hasAttribute('open')) return;
        this.removeAttribute('open');
        this.dispatchEvent(new window.Event('close'));
    };
}

const helpMarkup = `<!doctype html><html><body>
<form data-ticket-form>
  <div class="ticket-form__label-row">
    <label for="ticket-remote">AnyDesk / รหัสรีโมท</label>
    <button type="button" aria-controls="ticket-anydesk-help" data-help-dialog-open="ticket-anydesk-help" aria-label="วิธีหาเลข AnyDesk">?</button>
  </div>
  <input id="ticket-remote" name="remote_access">
</form>
<dialog id="ticket-anydesk-help" data-help-dialog>
  <h2>วิธีหาเลข AnyDesk</h2>
  <button type="button" data-help-dialog-close>ปิด</button>
  <ol><li>เปิด AnyDesk</li></ol>
</dialog>
</body></html>`;

test('the AnyDesk "?" opens the help dialog as a modal, and every way of closing returns focus to it', async () => {
    const {initHelpDialogs} = await import('../../resources/js/components/help-dialog.js');
    const dom = mountDom(helpMarkup);
    try {
        const {document, window} = dom;
        stubDialog(window);
        assert.ok(initHelpDialogs(document));
        assert.equal(initHelpDialogs(document), false, 'ไม่ผูก listener ซ้ำ');

        const trigger = document.querySelector('[data-help-dialog-open]');
        const dialog = document.getElementById('ticket-anydesk-help');

        // ปุ่มปิด
        trigger.focus();
        click(trigger);
        assert.equal(dialog.open, true);
        click(dialog.querySelector('[data-help-dialog-close]'));
        assert.equal(dialog.open, false);
        assert.equal(document.activeElement, trigger);

        // คลิกที่ backdrop (target = <dialog>) ปิดได้ · คลิกในเนื้อหาไม่ปิด
        click(trigger);
        click(dialog.querySelector('li'));
        assert.equal(dialog.open, true, 'คลิกในเนื้อหาไม่ปิด');
        click(dialog);
        assert.equal(dialog.open, false);
        assert.equal(document.activeElement, trigger);

        // ปิดโดยเบราว์เซอร์ (Escape → close event) ก็คืนโฟกัสเหมือนกัน
        click(trigger);
        dialog.close();
        assert.equal(document.activeElement, trigger);

        // การเปิด dialog ไม่ส่งฟอร์มแจ้งปัญหา
        let submitted = false;
        document.querySelector('form').addEventListener('submit', () => { submitted = true; });
        click(trigger);
        assert.equal(submitted, false);
    } finally {
        dom.cleanup();
    }
});

test('the AnyDesk help is a native dialog outside the report form, with short steps and the example image', () => {
    const create = read('resources/views/tickets/public/create.blade.php');
    const formEnd = create.indexOf('</form>');
    const dialogStart = create.indexOf('<dialog class="ticket-help-dialog" id="ticket-anydesk-help"');

    assert.ok(dialogStart > formEnd, 'dialog อยู่นอกฟอร์มแจ้งปัญหา');
    assert.match(create, /data-help-dialog-open="ticket-anydesk-help" aria-label="วิธีหาเลข AnyDesk"/);
    assert.match(create, /<\/label>\s*<button type="button" class="ticket-help-trigger"/, 'ปุ่ม ? อยู่นอก <label>');
    assert.match(create, /asset\('images\/'\.rawurlencode\('Anydesk - Example\.png'\)\)/);
    assert.match(create, /data-help-dialog-close aria-label="ปิดหน้าต่างวิธีหาเลข AnyDesk"/);
    assert.match(read('resources/js/pages/tickets/public.js'), /initHelpDialogs\(root\)/);
});
