import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {
    earliestStart,
    fillAnnouncementForm,
    initAnnouncementPage,
    readAnnouncement,
} from '../../resources/js/pages/announcements/index.js';
import {click, mountDom} from './helpers/dom.js';

const scriptSource = readFileSync(new URL('../../resources/js/pages/announcements/index.js', import.meta.url), 'utf8');
const tableSource = readFileSync(new URL('../../resources/views/announcements/components/table.blade.php', import.meta.url), 'utf8');

const editPayload = {
    id: 12,
    title: 'ปิดปรับปรุงระบบ',
    body: 'บรรทัดแรก\nบรรทัดสอง',
    audience: 'all',
    starts_on: '2026-09-20',
    ends_on: '2026-09-30',
    update_url: '/announcements/12',
};

/** markup ที่ตรงกับ resources/views/announcements/index.blade.php และ components/* (เฉพาะ hook ที่ JS ใช้) */
const markup = (feedback = {}, openOnLoad = false) => `<!doctype html><html><body>
<div data-announcements-page>
    <button type="button" data-announcement-create>สร้างประกาศ</button>
    <table><tbody><tr>
        <td><button type="button" data-announcement-edit data-announcement='${JSON.stringify(editPayload)}'>แก้ไข</button></td>
        <td><form method="POST" action="/announcements/12" data-announcement-delete data-announcement-title="ปิดปรับปรุงระบบ"><button type="submit">ลบ</button></form></td>
    </tr></tbody></table>
    <div class="modal" data-announcement-modal data-store-url="/announcements" data-today="2026-09-25"${openOnLoad ? ' data-open-on-load="true"' : ''}>
        <span data-announcement-modal-title>สร้างประกาศ</span>
        <form method="POST" action="/announcements" data-announcement-form>
            <div class="announcement-form__errors">old error</div>
            <input type="hidden" name="_method" value="PATCH" data-announcement-method disabled>
            <input type="hidden" name="_announcement_id" value="" data-announcement-id>
            <input name="title" data-announcement-field="title" class="is-invalid">
            <textarea name="body" data-announcement-field="body"></textarea>
            <input type="radio" name="audience" value="department" data-announcement-field="audience" checked>
            <input type="radio" name="audience" value="all" data-announcement-field="audience">
            <input type="date" name="starts_on" data-announcement-field="starts_on">
            <input type="date" name="ends_on" data-announcement-field="ends_on">
            <button type="button" data-announcement-clear-end>x</button>
            <span data-announcement-submit-label>สร้างประกาศ</span>
        </form>
    </div>
    <script type="application/json" data-announcement-feedback>${JSON.stringify(feedback)}</script>
</div></body></html>`;

function setup({feedback = {}, openOnLoad = false, swalResult = {isConfirmed: false}} = {}) {
    const dom = mountDom(markup(feedback, openOnLoad));
    const shown = [];
    const swalCalls = [];
    const bootstrapApi = {Modal: {getOrCreateInstance: (element) => ({show: () => shown.push(element)})}};
    const swal = () => ({fire: async (options) => { swalCalls.push(options); return swalResult; }});
    const ready = initAnnouncementPage(dom.document, bootstrapApi, swal);
    const modal = dom.document.querySelector('[data-announcement-modal]');
    const form = modal.querySelector('[data-announcement-form]');
    const value = (name) => form.querySelector(`[data-announcement-field="${name}"]`).value;
    const audience = () => form.querySelector('[data-announcement-field="audience"]:checked').value;

    return {dom, ready, shown, swalCalls, modal, form, value, audience};
}

test('create button opens an empty form that posts to the store route', () => {
    const {dom, shown, modal, form, value, audience} = setup();

    click(dom.document.querySelector('[data-announcement-create]'));

    assert.equal(shown.length, 1);
    assert.equal(form.getAttribute('action'), '/announcements');
    assert.equal(form.querySelector('[data-announcement-method]').disabled, true);
    assert.equal(value('title'), '');
    assert.equal(audience(), 'department');
    assert.equal(value('starts_on'), '2026-09-25');
    assert.equal(form.querySelector('[data-announcement-field="starts_on"]').min, '2026-09-25');
    assert.equal(modal.querySelector('[data-announcement-modal-title]').textContent, 'สร้างประกาศ');
    // ข้อความ error ของรอบก่อนต้องไม่ติดมากับฟอร์มใหม่
    assert.equal(modal.querySelector('.announcement-form__errors'), null);
    assert.equal(modal.querySelector('.is-invalid'), null);
    dom.cleanup();
});

test('edit button fills the form with the row data and switches to PATCH', () => {
    const {dom, shown, modal, form, value, audience} = setup();

    click(dom.document.querySelector('[data-announcement-edit]'));

    assert.equal(shown.length, 1);
    assert.equal(form.getAttribute('action'), '/announcements/12');
    assert.equal(form.querySelector('[data-announcement-method]').disabled, false);
    assert.equal(form.querySelector('[data-announcement-id]').value, '12');
    assert.equal(value('title'), 'ปิดปรับปรุงระบบ');
    assert.equal(value('body'), 'บรรทัดแรก\nบรรทัดสอง');
    assert.equal(audience(), 'all');
    assert.equal(value('starts_on'), '2026-09-20');
    // ประกาศที่เริ่มแสดงไปแล้วคงวันเริ่มเดิมไว้ได้
    assert.equal(form.querySelector('[data-announcement-field="starts_on"]').min, '2026-09-20');
    assert.equal(value('ends_on'), '2026-09-30');
    assert.equal(modal.querySelector('[data-announcement-submit-label]').textContent, 'บันทึกการแก้ไข');
    dom.cleanup();
});

test('switching back to create after edit resets every field', () => {
    const {dom, form, value, audience} = setup();

    click(dom.document.querySelector('[data-announcement-edit]'));
    click(dom.document.querySelector('[data-announcement-create]'));

    assert.equal(form.getAttribute('action'), '/announcements');
    assert.equal(form.querySelector('[data-announcement-method]').disabled, true);
    assert.equal(value('title'), '');
    assert.equal(value('ends_on'), '');
    assert.equal(audience(), 'department');
    dom.cleanup();
});

test('clear button empties the end date and start date keeps end date valid', () => {
    const {dom, form, value} = setup();

    click(dom.document.querySelector('[data-announcement-edit]'));
    click(dom.document.querySelector('[data-announcement-clear-end]'));
    assert.equal(value('ends_on'), '');

    const endsOn = form.querySelector('[data-announcement-field="ends_on"]');
    endsOn.value = '2026-09-26';
    const startsOn = form.querySelector('[data-announcement-field="starts_on"]');
    startsOn.value = '2026-09-28';
    startsOn.dispatchEvent(new dom.window.Event('change', {bubbles: true}));

    assert.equal(endsOn.min, '2026-09-28');
    assert.equal(endsOn.value, '');
    dom.cleanup();
});

test('delete asks for confirmation with SweetAlert and does not submit when cancelled', async () => {
    const {dom, swalCalls} = setup({swalResult: {isConfirmed: false}});
    const form = dom.document.querySelector('[data-announcement-delete]');
    let submitted = false;
    form.submit = () => { submitted = true; };

    form.querySelector('button').click();
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(swalCalls.length, 1);
    assert.match(swalCalls[0].text, /ปิดปรับปรุงระบบ/);
    assert.equal(submitted, false);
    dom.cleanup();
});

test('confirmed delete submits the form', async () => {
    const {dom} = setup({swalResult: {isConfirmed: true}});
    const form = dom.document.querySelector('[data-announcement-delete]');
    let submitted = false;
    form.submit = () => { submitted = true; };

    form.querySelector('button').click();
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(submitted, true);
    dom.cleanup();
});

test('server feedback shows a success message and reopens the form after validation errors', () => {
    const {dom, shown, swalCalls} = setup({feedback: {success: 'สร้างประกาศเรียบร้อยแล้ว'}, openOnLoad: true});

    assert.equal(swalCalls[0].icon, 'success');
    assert.equal(shown.length, 1);
    dom.cleanup();
});

test('initializing twice does not register duplicate listeners', () => {
    const {dom, shown} = setup();

    assert.equal(initAnnouncementPage(dom.document, {Modal: {getOrCreateInstance: () => ({show: () => shown.push('dup')})}}), false);
    click(dom.document.querySelector('[data-announcement-create]'));

    assert.equal(shown.length, 1);
    dom.cleanup();
});

test('helpers', () => {
    assert.equal(readAnnouncement({dataset: {announcement: '{broken'}}), null);
    assert.equal(readAnnouncement({dataset: {announcement: JSON.stringify(editPayload)}}).id, 12);
    assert.equal(earliestStart('2026-09-25', '2026-09-20'), '2026-09-20');
    assert.equal(earliestStart('2026-09-25', '2026-09-28'), '2026-09-25');
    assert.equal(earliestStart('2026-09-25'), '2026-09-25');
});

test('row menu escapes the scrolling table instead of being clipped inside the card', () => {
    assert.match(tableSource, /class="announcements-table__menu"[\s\S]*?data-bs-popper-config='\{"strategy":"fixed"\}'/);
});

test('page never uses native dialogs and edit payload is encoded outside the json directive', () => {
    assert.doesNotMatch(scriptSource, /\b(alert|confirm|prompt)\(/);
    // directive json ของ Blade แยกอาร์กิวเมนต์ด้วยจุลภาค ห้ามส่ง array literal เข้าไปตรง ๆ
    assert.doesNotMatch(tableSource, /@json\(\[/);
    assert.match(tableSource, /json_encode\(\$editPayload/);
});
