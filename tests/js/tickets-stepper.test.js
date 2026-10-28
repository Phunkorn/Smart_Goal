import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {MODAL_HASH, initTicketStepper, initialStepIndex} from '../../resources/js/pages/tickets/stepper.js';
import {click, mountDom} from './helpers/dom.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

/** hook ชุดเดียวกับ tickets/components/submit-modal.blade.php (+ location-field / problem-fields) */
/** contact ตั้งต้นเป็นเบอร์จากโปรไฟล์เหมือนของจริง (TicketSubmissionService::prefillPhone) — ส่ง '' เพื่อจำลองโปรไฟล์ที่ไม่มีเบอร์ */
function modalMarkup({openOnLoad = false, descriptionError = false, formAlert = false, contact = '0812345678'} = {}) {
    return `<!doctype html><html><body>
<a href="/tickets/create" data-ticket-open-modal>แจ้งปัญหา IT</a>
<div class="modal fade" id="ticket-submit-modal" data-ticket-submit-modal ${openOnLoad ? 'data-open-on-load' : ''}>
  <form data-ticket-form data-ticket-stepper action="/tickets" method="POST">
    <ol>
      <li data-ticket-step-indicator="1" aria-current="step"><span class="ticket-stepper__label">ข้อมูลผู้แจ้ง</span></li>
      <li data-ticket-step-indicator="2"><span class="ticket-stepper__label">สาขา/สถานที่</span></li>
      <li data-ticket-step-indicator="3"><span class="ticket-stepper__label">รายละเอียดปัญหา</span></li>
    </ol>
    <p data-ticket-step-status></p>
    ${formAlert ? '<div class="ticket-form__alert" role="alert">ส่งถี่เกินไป</div>' : ''}
    <section data-ticket-step="1"><h3 class="ticket-step__title" tabindex="-1">ข้อมูลผู้แจ้ง</h3><dl><dd>สมชาย</dd></dl></section>
    <section data-ticket-step="2" hidden><h3 class="ticket-step__title" tabindex="-1">สาขา</h3>
      <select name="location_id" required data-ticket-location><option value="" selected>เลือก</option><option value="1">สาขา 1</option><option value="other">อื่น ๆ</option></select>
      <div data-ticket-location-other hidden><input name="location_name"></div>
    </section>
    <section data-ticket-step="3" hidden><h3 class="ticket-step__title" tabindex="-1">รายละเอียด</h3>
      <textarea name="description" required minlength="10" class="${descriptionError ? 'is-invalid' : ''}"></textarea>
      ${descriptionError ? '<p class="ticket-form__error">อย่างน้อย 10 ตัวอักษร</p>' : ''}
      <input id="ticket-contact" type="tel" name="contact" required pattern="0[0-9]{9}" data-ticket-digits="10"
        data-pattern-message="เบอร์โทรต้องเป็นตัวเลข 10 หลัก ขึ้นต้นด้วย 0" value="${contact}">
      <input id="ticket-remote" name="remote_access" pattern="[0-9][0-9 \-]{5,19}">
      <p class="ticket-form__error" data-ticket-photos-error role="alert" hidden></p>
    </section>
    <button type="button" data-ticket-step-back hidden>ย้อนกลับ</button>
    <button type="button" data-ticket-step-next>ถัดไป</button>
    <div data-ticket-step-submit hidden><button type="submit" data-ticket-submit>ส่ง</button></div>
  </form>
</div>
</body></html>`;
}

const visibleStep = (document) => document.querySelector('[data-ticket-step]:not([hidden])').dataset.ticketStep;

test('next only moves on when the current step is valid, and back returns', () => {
    const dom = mountDom(modalMarkup());
    try {
        const {document} = dom;
        initTicketStepper(document, {modalApi: null});
        const next = document.querySelector('[data-ticket-step-next]');
        const back = document.querySelector('[data-ticket-step-back]');

        assert.equal(visibleStep(document), '1');
        assert.equal(back.hidden, true);
        assert.equal(document.querySelector('[data-ticket-step-status]').textContent, 'ขั้นที่ 1 จาก 3: ข้อมูลผู้แจ้ง');

        click(next);
        assert.equal(visibleStep(document), '2', 'ขั้นที่ 1 ไม่มีช่องกรอก จึงผ่านเสมอ');
        assert.equal(document.activeElement.textContent, 'สาขา', 'โฟกัสหัวข้อของขั้นใหม่');

        click(next);
        assert.equal(visibleStep(document), '2', 'ยังไม่เลือกสาขา — ไปต่อไม่ได้');

        document.querySelector('select').value = '1';
        click(next);
        assert.equal(visibleStep(document), '3');
        assert.equal(next.hidden, true);
        assert.equal(document.querySelector('[data-ticket-step-submit]').hidden, false);

        const [first, second, third] = document.querySelectorAll('[data-ticket-step-indicator]');
        assert.ok(first.classList.contains('is-complete') && second.classList.contains('is-complete'));
        assert.equal(third.getAttribute('aria-current'), 'step');
        assert.equal(first.hasAttribute('aria-current'), false);

        click(back);
        assert.equal(visibleStep(document), '2');
        assert.equal(document.querySelector('[data-ticket-step-status]').textContent, 'ขั้นที่ 2 จาก 3: สาขา/สถานที่');
    } finally {
        dom.cleanup();
    }
});

test('a hidden step with an invalid field is opened when the browser blocks submit', () => {
    const dom = mountDom(modalMarkup());
    try {
        const {document, window} = dom;
        const stepper = initTicketStepper(document, {modalApi: null});
        stepper.show(2); // อยู่ขั้นที่ 3 แต่สาขา (ขั้นที่ 2) ยังว่าง
        document.querySelector('textarea').value = 'เครื่องพิมพ์ชั้นสองพิมพ์ไม่ออก';

        let submitted = false;
        document.querySelector('form').addEventListener('submit', (event) => { submitted = true; event.preventDefault(); });
        document.querySelector('[data-ticket-submit]').dispatchEvent(new window.MouseEvent('click', {bubbles: true}));

        assert.equal(submitted, false, 'ช่องบังคับยังบังคับอยู่ — ไม่ได้ปิด required');
        assert.equal(visibleStep(document), '2', 'เปิดขั้นที่มีช่องผิด เบราว์เซอร์จึงโฟกัสได้');
    } finally {
        dom.cleanup();
    }
});

test('a server-side error opens the step that has it, form errors open the last step', () => {
    // {} = ฟอร์มปกติ: ช่อง error ของ photo picker ที่ซ่อนและว่างอยู่ต้องไม่ถูกนับเป็นข้อผิดพลาด (เคยเปิดที่ขั้นที่ 3)
    for (const [options, expected] of [[{descriptionError: true}, '3'], [{formAlert: true}, '3'], [{}, '1']]) {
        const dom = mountDom(modalMarkup(options));
        try {
            initTicketStepper(dom.document, {modalApi: null});
            assert.equal(visibleStep(dom.document), expected, JSON.stringify(options));
        } finally {
            dom.cleanup();
        }
    }

    const dom = mountDom(modalMarkup({descriptionError: true}));
    try {
        const steps = [...dom.document.querySelectorAll('[data-ticket-step]')];
        assert.equal(initialStepIndex(steps, dom.document.querySelector('form')), 2);
    } finally {
        dom.cleanup();
    }
});

/** Bootstrap Modal จำลอง — ยิง shown/hidden.bs.modal เหมือนของจริง */
function fakeModalApi(window) {
    const calls = [];

    return {
        calls,
        getOrCreateInstance(element) {
            return {
                show() {
                    calls.push('show');
                    element.classList.add('show');
                    element.dispatchEvent(new window.Event('shown.bs.modal'));
                },
                hide() {
                    calls.push('hide');
                    element.classList.remove('show');
                    element.dispatchEvent(new window.Event('hidden.bs.modal'));
                },
            };
        },
    };
}

function fakeHistory(location) {
    const log = [];

    return {
        log,
        state: null,
        pushState(state, title, url) { this.state = state; location.hash = url; log.push(['push', url]); },
        replaceState(state, title, url) { this.state = state; location.hash = ''; log.push(['replace', url]); },
        back() { this.state = null; location.hash = ''; log.push(['back']); },
    };
}

test('the modal reopens after a rejected submit and the browser back button closes it', () => {
    const dom = mountDom(modalMarkup({openOnLoad: true, descriptionError: true}));
    try {
        const {document, window} = dom;
        const modalApi = fakeModalApi(window);
        const location = {hash: '', pathname: '/tickets', search: ''};
        const history = fakeHistory(location);

        initTicketStepper(document, {modalApi, history, location});

        assert.deepEqual(modalApi.calls, ['show']);
        assert.deepEqual(history.log, [['push', MODAL_HASH]]);
        assert.equal(visibleStep(document), '3');

        // ปุ่มย้อนกลับของเบราว์เซอร์: hash หายไป → ปิด modal โดยไม่ย้อนประวัติซ้ำ
        location.hash = '';
        window.dispatchEvent(new window.PopStateEvent('popstate'));
        assert.deepEqual(modalApi.calls, ['show', 'hide']);
        assert.deepEqual(history.log, [['push', MODAL_HASH]]);
    } finally {
        dom.cleanup();
    }
});

test('closing the modal removes its history entry, and a refresh on #new-ticket opens it', () => {
    const dom = mountDom(modalMarkup());
    try {
        const {document, window} = dom;
        const modalApi = fakeModalApi(window);
        const location = {hash: MODAL_HASH, pathname: '/tickets', search: ''};
        const history = fakeHistory(location);

        initTicketStepper(document, {modalApi, history, location});
        assert.deepEqual(modalApi.calls, ['show'], 'รีเฟรชบน #new-ticket เปิด modal');
        assert.deepEqual(history.log, [], 'hash อยู่แล้ว ไม่ push ซ้ำ');

        modalApi.getOrCreateInstance(document.querySelector('[data-ticket-submit-modal]')).hide();
        assert.deepEqual(history.log, [['replace', '/tickets']], 'ไม่มี entry ของเราให้ย้อน จึงแทนที่ URL');
    } finally {
        dom.cleanup();
    }
});

test('init is idempotent', () => {
    const dom = mountDom(modalMarkup());
    try {
        assert.ok(initTicketStepper(dom.document, {modalApi: null}));
        assert.equal(initTicketStepper(dom.document, {modalApi: null}), null);
    } finally {
        dom.cleanup();
    }
});

test('step 3 of the internal modal needs the phone, and the AnyDesk id stays optional', () => {
    // โปรไฟล์ไม่มีเบอร์ 10 หลัก → ช่องว่าง ผู้แจ้งต้องกรอกเอง
    const dom = mountDom(modalMarkup({contact: ''}));
    try {
        const {document, window} = dom;
        const stepper = initTicketStepper(document, {modalApi: null});
        document.querySelector('select').value = '1';
        stepper.show(2);
        document.querySelector('textarea').value = 'เครื่องพิมพ์ชั้นสองพิมพ์ไม่ออก';

        let submitted = false;
        document.querySelector('form').addEventListener('submit', (event) => { submitted = true; event.preventDefault(); });
        const submit = () => document.querySelector('[data-ticket-submit]').dispatchEvent(new window.MouseEvent('click', {bubbles: true}));

        submit();
        assert.equal(submitted, false, 'ไม่กรอกเบอร์โทร → ส่งไม่ได้');
        assert.equal(visibleStep(document), '3');

        // เบอร์ไม่ครบ 10 หลักก็ยังส่งไม่ได้ (pattern เดียวกับฟอร์มสาธารณะ)
        document.getElementById('ticket-contact').value = '08123';
        submit();
        assert.equal(submitted, false, 'เบอร์ไม่ครบ 10 หลัก → ส่งไม่ได้');

        // ครบแล้วส่งได้ โดยไม่ต้องกรอก AnyDesk
        document.getElementById('ticket-contact').value = '0898765432';
        assert.equal(document.getElementById('ticket-remote').value, '', 'AnyDesk ไม่บังคับ');
        submit();
        assert.equal(submitted, true);
    } finally {
        dom.cleanup();
    }
});

test('every hook the stepper needs exists in the Blade modal, and the page script has no new polling', () => {
    const modal = read('resources/views/tickets/components/submit-modal.blade.php');
    for (const hook of ['data-ticket-submit-modal', 'data-open-on-load', 'data-ticket-stepper', 'data-ticket-step-indicator', 'data-ticket-step=',
        'data-ticket-step-status', 'data-ticket-step-back', 'data-ticket-step-next', 'data-ticket-step-submit', 'ticket-step__title',
        'modal-fullscreen-sm-down', 'modal-dialog-scrollable', 'data-bs-dismiss="modal"',
        // ขั้นที่ 3 ใช้ partial ช่องติดต่อกลับชุดเดียวกับหน้าสาธารณะ และเติมเบอร์จากโปรไฟล์
        "@include('tickets.components.contact-fields'", 'TicketSubmissionService::prefillPhone($requester->phone)',
        "@include('tickets.components.problem-fields', ['withPhotos' => false])", "@include('tickets.components.photo-picker')"]) {
        assert.ok(modal.includes(hook), hook);
    }
    // ปุ่มเปิดฟอร์มอยู่ที่การ์ดของผู้แจ้งเท่านั้น — คิวงานของ Handler/admin และรายการแผนกไม่มีปุ่มแจ้งปัญหา (แยกหน้าที่)
    assert.match(read('resources/views/tickets/components/reporter-card.blade.php'),
        /href="\{\{ route\('tickets\.create'\) \}\}"\s+data-bs-toggle="modal" data-bs-target="#ticket-submit-modal" data-ticket-open-modal/);
    for (const page of ['resources/views/tickets/index.blade.php', 'resources/views/tickets/department.blade.php']) {
        const blade = read(page);
        assert.doesNotMatch(blade, /data-ticket-open-modal|ticket-submit-modal|submit-modal'\)/, page);
    }

    const scripts = ['resources/js/pages/tickets/index.js', 'resources/js/pages/tickets/stepper.js']
        .map((path) => read(path).replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, ''));
    for (const source of scripts) {
        assert.doesNotMatch(source, /\bfetch\(|XMLHttpRequest|setInterval/);
        assert.doesNotMatch(source, /\b(alert|confirm|prompt)\(/);
    }
});
