import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {MODAL_HASH, appendQuickNote, initTicketStepper, reviewText} from '../../resources/js/pages/tickets/stepper.js';
import {initTicketDetail} from '../../resources/js/pages/tickets/detail.js';
import {initTicketForm} from '../../resources/js/pages/tickets/form.js';
import {click, mountDom} from './helpers/dom.js';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

/**
 * hook ชุดเดียวกับ tickets/show.blade.php: ปุ่มปิดงาน (components/actions) + modal ปิดงานแบบขั้นตอน (components/close-modal)
 * ฟอร์มที่สอง (#note) ในตัวอย่างนี้ใช้พิสูจน์ว่า form.js / stepper.js ผูกหลายฟอร์มในหน้าเดียวแยกกันได้
 */
function detailMarkup({openOnLoad = false, noteError = false} = {}) {
    return `<!doctype html><html><body>
<div data-ticket-detail>
  <button type="button" data-bs-toggle="modal" data-bs-target="#ticket-modal-close">ปิดงาน</button>
  <form id="note" data-ticket-form method="POST" action="/tickets/1/notes">
    <textarea name="body" required></textarea>
    <div data-ticket-photos data-max-files="3" data-max-bytes="10485760" data-accept=".jpg,.png">
      <input id="ticket-note-photos-input" type="file" name="photos[]" multiple data-ticket-photos-input>
      <ul data-ticket-photos-list></ul><p data-ticket-photos-error hidden></p>
    </div>
    <button type="submit" data-ticket-submit><span data-ticket-submit-label>บันทึก</span></button>
  </form>
  <div class="modal fade" id="ticket-modal-close" data-ticket-modal ${openOnLoad ? 'data-open-on-load="true"' : ''}>
    <form id="close" data-ticket-form data-ticket-stepper data-ticket-stepper-hash="#close-ticket" method="POST" action="/tickets/1/status">
      <ol>
        <li data-ticket-step-indicator="1" aria-current="step"><span class="ticket-stepper__label">ผลการแก้ไข</span></li>
        <li data-ticket-step-indicator="2"><span class="ticket-stepper__label">รูปหลังแก้ไข</span></li>
        <li data-ticket-step-indicator="3"><span class="ticket-stepper__label">ตรวจทาน</span></li>
      </ol>
      <p data-ticket-step-status></p>
      <section data-ticket-step="1"><h3 class="ticket-step__title" tabindex="-1">แก้ไขอะไรไปบ้าง</h3>
        <button type="button" data-ticket-quick-note="แก้ไขเรียบร้อย ทดสอบใช้งานได้ปกติ">แก้ไขเรียบร้อย ทดสอบใช้งานได้ปกติ</button>
        <button type="button" data-ticket-quick-note="เปลี่ยนอุปกรณ์ใหม่แล้ว">เปลี่ยนอุปกรณ์ใหม่แล้ว</button>
        <textarea id="ticket-close-note" name="note" required data-ticket-quick-note-target class="${noteError ? 'is-invalid' : ''}"></textarea>
        ${noteError ? '<p class="ticket-form__error">กรุณาบันทึกผลการแก้ไขก่อนปิดงาน</p>' : ''}
      </section>
      <section data-ticket-step="2" hidden><h3 class="ticket-step__title" tabindex="-1">มีรูปหลังแก้ไขไหม</h3>
        <div data-ticket-photos data-max-files="3" data-max-bytes="10485760" data-accept=".jpg,.png">
          <input id="ticket-close-photos-input" type="file" name="photos[]" multiple data-ticket-photos-input>
          <ul data-ticket-photos-list></ul><p data-ticket-photos-error hidden></p>
        </div>
      </section>
      <section data-ticket-step="3" hidden><h3 class="ticket-step__title" tabindex="-1">ตรวจทานก่อนปิดงาน</h3>
        <dl><dd data-ticket-review="note">—</dd><dd data-ticket-review="photos[]">—</dd></dl>
      </section>
      <button type="button" data-ticket-step-back hidden>ย้อนกลับ</button>
      <button type="button" data-ticket-step-next>ถัดไป</button>
      <div data-ticket-step-submit hidden><button type="submit" data-ticket-submit><span data-ticket-submit-label>ยืนยันปิดงาน</span></button></div>
    </form>
  </div>
</div>
</body></html>`;
}

const visibleStep = (form) => form.querySelector('[data-ticket-step]:not([hidden])').dataset.ticketStep;

function fakeModalApi() {
    const calls = [];
    const instances = new Map();

    return {
        calls,
        getOrCreateInstance(element) {
            if (! instances.has(element)) {
                instances.set(element, {
                    show: () => calls.push(['show', element.id]),
                    hide: () => calls.push(['hide', element.id]),
                });
            }

            return instances.get(element);
        },
    };
}

test('quick notes append once to the result field, and the review sums up text and photos', () => {
    assert.equal(appendQuickNote('', 'เปลี่ยนอุปกรณ์ใหม่แล้ว'), 'เปลี่ยนอุปกรณ์ใหม่แล้ว');
    assert.equal(appendQuickNote('ตรวจสาย LAN  ', 'เปลี่ยนอุปกรณ์ใหม่แล้ว'), 'ตรวจสาย LAN\nเปลี่ยนอุปกรณ์ใหม่แล้ว');
    assert.equal(appendQuickNote('เปลี่ยนอุปกรณ์ใหม่แล้ว', 'เปลี่ยนอุปกรณ์ใหม่แล้ว'), 'เปลี่ยนอุปกรณ์ใหม่แล้ว', 'ไม่เติมซ้ำ');

    assert.equal(reviewText({type: 'file', files: {length: 0}}), 'ไม่มีรูป');
    assert.equal(reviewText({type: 'file', files: {length: 2}}), 'แนบ 2 รูป');
    assert.equal(reviewText({type: 'textarea', value: '  แก้ไขแล้ว  '}), 'แก้ไขแล้ว');
});

test('closing walks three steps: the result is required, photos are optional, and the last step reviews both', () => {
    const dom = mountDom(detailMarkup());
    try {
        const {document} = dom;
        initTicketStepper(document, {modalApi: null});
        const form = document.getElementById('close');
        const next = form.querySelector('[data-ticket-step-next]');
        const note = form.querySelector('#ticket-close-note');

        assert.equal(document.getElementById('note').dataset.ticketStepperReady, undefined, 'ฟอร์มบันทึกไม่ใช่ขั้นตอน');
        assert.equal(form.querySelector('[data-ticket-step-status]').textContent, 'ขั้นที่ 1 จาก 3: ผลการแก้ไข');

        click(next);
        assert.equal(visibleStep(form), '1', 'ยังไม่กรอกผลการแก้ไข — ไปต่อไม่ได้');
        assert.equal(note.getAttribute('aria-invalid'), 'true');

        click(form.querySelector('[data-ticket-quick-note="แก้ไขเรียบร้อย ทดสอบใช้งานได้ปกติ"]'));
        click(form.querySelector('[data-ticket-quick-note="เปลี่ยนอุปกรณ์ใหม่แล้ว"]'));
        assert.equal(note.value, 'แก้ไขเรียบร้อย ทดสอบใช้งานได้ปกติ\nเปลี่ยนอุปกรณ์ใหม่แล้ว');
        assert.equal(document.activeElement, note, 'แตะแล้วโฟกัสช่องให้แก้ต่อได้');
        assert.equal(note.hasAttribute('aria-invalid'), false, 'ข้อความผิดพลาดหายเมื่อมีข้อความ');

        click(next);
        assert.equal(visibleStep(form), '2');
        click(next);
        assert.equal(visibleStep(form), '3', 'รูปไม่บังคับ — ข้ามได้');
        assert.equal(form.querySelector('[data-ticket-review="note"]').textContent, note.value);
        assert.equal(form.querySelector('[data-ticket-review="photos[]"]').textContent, 'ไม่มีรูป');
        assert.equal(form.querySelector('[data-ticket-step-submit]').hidden, false);
        assert.equal(next.hidden, true);
    } finally {
        dom.cleanup();
    }
});

test('the close modal keeps its own back-button hash and does not take over #new-ticket', () => {
    const dom = mountDom(detailMarkup());
    try {
        const {document, window} = dom;
        const modalApi = fakeModalApi();
        const location = {hash: '', pathname: '/tickets/1', search: ''};
        const log = [];
        const history = {
            state: null,
            pushState(state, title, url) { this.state = state; location.hash = url; log.push(['push', url]); },
            replaceState(state, title, url) { this.state = state; log.push(['replace', url]); },
            back() { this.state = null; location.hash = ''; log.push(['back']); },
        };

        initTicketStepper(document, {modalApi, history, location});
        const modal = document.getElementById('ticket-modal-close');
        modal.dispatchEvent(new window.Event('shown.bs.modal'));
        assert.deepEqual(log, [['push', '#close-ticket']]);
        assert.notEqual('#close-ticket', MODAL_HASH);

        // ปุ่มย้อนกลับของมือถือ: hash หายไป → modal ปิด ไม่ออกจากหน้า
        location.hash = '';
        modal.classList.add('show');
        window.dispatchEvent(new window.PopStateEvent('popstate'));
        assert.deepEqual(modalApi.calls.at(-1), ['hide', 'ticket-modal-close']);
    } finally {
        dom.cleanup();
    }
});

test('after a rejected close only the stepper reopens the modal (once) at the step with the error', () => {
    const dom = mountDom(detailMarkup({openOnLoad: true, noteError: true}));
    try {
        const modalApi = fakeModalApi();
        initTicketDetail(dom.document, {bootstrapApi: {Modal: modalApi}, swal: () => null});

        assert.deepEqual(modalApi.calls, [['show', 'ticket-modal-close']], 'เปิดครั้งเดียว โดย stepper');
        assert.equal(visibleStep(dom.document.getElementById('close')), '1');
    } finally {
        dom.cleanup();
    }
});

test('both photo forms on the page are bound separately', () => {
    const dom = mountDom(detailMarkup());
    try {
        assert.equal(initTicketForm(dom.document, {createFileList: () => null, objectUrls: null}), true);
        assert.equal(dom.document.getElementById('note').dataset.ticketFormReady, 'true');
        assert.equal(dom.document.getElementById('close').dataset.ticketFormReady, 'true');
        assert.equal(initTicketForm(dom.document), false, 'เรียกซ้ำไม่ผูกซ้ำ');
    } finally {
        dom.cleanup();
    }
});

test('the Blade close modal carries every hook, and the detail page uses it instead of the generic status modal', () => {
    const modal = read('resources/views/tickets/components/close-modal.blade.php');
    for (const hook of ['id="ticket-modal-close"', 'data-ticket-modal', 'data-open-on-load="true"', 'enctype="multipart/form-data"',
        'data-ticket-form', 'data-ticket-stepper', 'data-ticket-stepper-hash="#close-ticket"', 'data-ticket-step-indicator', 'data-ticket-step=',
        'data-ticket-step-status', 'data-ticket-step-back', 'data-ticket-step-next', 'data-ticket-step-submit', 'data-ticket-quick-note=',
        'data-ticket-quick-note-target', 'data-ticket-review="note"', 'data-ticket-review="photos[]"', "'pickerId' => 'ticket-close-photos'",
        'modal-fullscreen-sm-down', 'modal-dialog-scrollable', "'tone' => 'success'"]) {
        assert.ok(modal.includes(hook), hook);
    }
    const show = read('resources/views/tickets/show.blade.php');
    assert.match(show, /@include\('tickets\.components\.close-modal'\)/);
    assert.doesNotMatch(show, /'action' => Ticket::ACTION_CLOSE/);
    assert.match(modal, /'camera' => true/, 'ขั้นรูปเลือกถ่ายรูป (กล้อง) หรือคลังรูปได้บนมือถือ');
    assert.match(read('resources/js/pages/tickets/detail.js'), /initTicketStepper\(page/);
    // ปุ่มปิดงาน: ปุ่มแรกของการ์ดตามลำดับใน DOM (ไม่ใช้ order) เต็มแถว และสูง 52px บนจอแคบ — ไม่ใช่แถบลอย
    const actions = read('resources/views/tickets/components/actions.blade.php');
    assert.ok(actions.indexOf('ticket-actions__button--close') < actions.indexOf("can['resume']"), 'ปิดงานมาก่อนกลับมาดำเนินการ');
    const responsive = read('resources/css/pages/tickets/responsive.css');
    assert.match(responsive, /\.ticket-actions__button--close \{\s*min-height: 52px;/);
    assert.doesNotMatch(responsive, /order: -1/);
});
