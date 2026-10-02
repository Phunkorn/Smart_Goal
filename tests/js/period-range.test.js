import test from 'node:test';
import assert from 'node:assert/strict';
import {CUSTOM_PERIOD, initPeriodRange} from '../../resources/js/components/period-range.js';
import {initAutoSubmitFilters} from '../../resources/js/components/auto-submit-filter.js';
import {click, mountDom} from './helpers/dom.js';

function mountForm(env, {autoSubmit = false} = {}) {
    env.document.body.innerHTML = `
        <form data-auto-submit-form action="/meetings" method="GET" ${autoSubmit ? 'data-period-auto-submit' : ''}>
            <select name="period" data-auto-submit data-period-select>
                <option value="upcoming" selected>กำลังจะมาถึง</option>
                <option value="${CUSTOM_PERIOD}" data-auto-submit-skip>กำหนดช่วงวันที่เอง</option>
            </select>
            <div data-period-range hidden>
                <input type="date" name="date_from" data-period-input="from" disabled>
                <input type="date" name="date_to" data-period-input="to" disabled>
            </div>
        </form>`;

    const form = env.document.querySelector('form');
    const select = env.document.querySelector('select');
    const range = env.document.querySelector('[data-period-range]');
    const [fromInput, toInput] = [...env.document.querySelectorAll('[data-period-input]')];
    let submissions = 0;
    form.requestSubmit = () => {
        submissions += 1;
        form.dispatchEvent(new env.window.Event('submit', {cancelable: true}));
    };

    return {form, select, range, fromInput, toInput, getSubmissions: () => submissions};
}

test('เลือก "กำหนดช่วงวันที่เอง" เปิดช่องวันที่ให้กรอก แต่ยังไม่ส่งฟอร์มจนกว่าจะกรอกครบ', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    const {select, range, fromInput, toInput, getSubmissions} = mountForm(env, {autoSubmit: true});

    initAutoSubmitFilters(env.document);
    initPeriodRange(env.document);

    select.value = CUSTOM_PERIOD;
    select.dispatchEvent(new env.window.Event('change', {bubbles: true}));

    assert.equal(getSubmissions(), 0, 'เลือกกำหนดช่วงเองแล้วต้องยังไม่ส่ง เพราะยังไม่มีวันที่');
    assert.equal(range.hidden, false);
    assert.equal(fromInput.disabled, false);
    assert.equal(toInput.disabled, false);
});

test('ฟอร์มที่ติด data-period-auto-submit ส่งเองทันทีที่กรอกวันเริ่มและวันสิ้นสุดครบ', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    const {select, fromInput, toInput, getSubmissions} = mountForm(env, {autoSubmit: true});

    initPeriodRange(env.document);
    select.value = CUSTOM_PERIOD;
    select.dispatchEvent(new env.window.Event('change', {bubbles: true}));

    fromInput.value = '2026-08-01';
    fromInput.dispatchEvent(new env.window.Event('change', {bubbles: true}));
    assert.equal(getSubmissions(), 0, 'กรอกแค่วันเริ่มยังไม่ครบ ต้องยังไม่ส่ง');

    toInput.value = '2026-08-10';
    toInput.dispatchEvent(new env.window.Event('change', {bubbles: true}));
    assert.equal(getSubmissions(), 1, 'กรอกครบทั้งสองช่องแล้วต้องส่งทันทีโดยไม่ต้องกดปุ่ม');
});

test('ฟอร์มที่ไม่ติด data-period-auto-submit ไม่ส่งเองแม้กรอกวันที่ครบ (พฤติกรรมเดิมของรายงาน)', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    const {select, fromInput, toInput, getSubmissions} = mountForm(env, {autoSubmit: false});

    initPeriodRange(env.document);
    select.value = CUSTOM_PERIOD;
    select.dispatchEvent(new env.window.Event('change', {bubbles: true}));

    fromInput.value = '2026-08-01';
    fromInput.dispatchEvent(new env.window.Event('change', {bubbles: true}));
    toInput.value = '2026-08-10';
    toInput.dispatchEvent(new env.window.Event('change', {bubbles: true}));

    assert.equal(getSubmissions(), 0, 'ไม่ได้ขอ auto-submit ไว้ จึงต้องรอผู้ใช้กดปุ่มเองเหมือนเดิม');
});

test('เรียก initPeriodRange ซ้ำไม่ผูก listener ซ้อน', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    const {select, fromInput, toInput, getSubmissions} = mountForm(env, {autoSubmit: true});

    initPeriodRange(env.document);
    initPeriodRange(env.document);

    select.value = CUSTOM_PERIOD;
    select.dispatchEvent(new env.window.Event('change', {bubbles: true}));
    fromInput.value = '2026-08-01';
    fromInput.dispatchEvent(new env.window.Event('change', {bubbles: true}));
    toInput.value = '2026-08-10';
    toInput.dispatchEvent(new env.window.Event('change', {bubbles: true}));

    assert.equal(getSubmissions(), 1, 'ผูกซ้ำต้องไม่ทำให้ยิงซ้อนเป็น 2 ครั้ง');
});

test('สลับกลับไปพรีเซ็ตแล้วช่องวันที่ถูก disable กลับ ไม่ติดค่าค้างไปกับ submit', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    const {select, range, fromInput, toInput} = mountForm(env, {autoSubmit: true});

    initPeriodRange(env.document);
    select.value = CUSTOM_PERIOD;
    select.dispatchEvent(new env.window.Event('change', {bubbles: true}));
    fromInput.value = '2026-08-01';
    toInput.value = '2026-08-10';

    select.value = 'upcoming';
    select.dispatchEvent(new env.window.Event('change', {bubbles: true}));

    assert.equal(range.hidden, true);
    assert.equal(fromInput.disabled, true);
    assert.equal(toInput.disabled, true);
});

test('ช่องค้นหาแบบ debounce ส่งฟอร์มหลังพิมพ์หยุด ไม่ใช่ทุกตัวอักษร', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = `
        <form data-auto-submit-form action="/meetings" method="GET">
            <input type="search" name="search" data-auto-submit-debounce="20">
        </form>`;
    const form = env.document.querySelector('form');
    const input = env.document.querySelector('input');
    let submissions = 0;
    form.requestSubmit = () => { submissions += 1; };

    initAutoSubmitFilters(env.document);

    input.value = 'f';
    input.dispatchEvent(new env.window.Event('input', {bubbles: true}));
    input.value = 'fo';
    input.dispatchEvent(new env.window.Event('input', {bubbles: true}));
    input.value = 'foo';
    input.dispatchEvent(new env.window.Event('input', {bubbles: true}));

    assert.equal(submissions, 0, 'ยังไม่ครบเวลาหน่วง ต้องยังไม่ส่ง');

    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.equal(submissions, 1, 'พิมพ์สามครั้งติดกันเร็ว ๆ ต้องส่งแค่ครั้งเดียวหลังหยุดพิมพ์');
});
