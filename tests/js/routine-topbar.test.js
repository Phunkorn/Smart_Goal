import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom} from './helpers/dom.js';
import {initRoutineTopbar} from '../../resources/js/components/routine-topbar.js';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const topbarMarkup = (total = 3) => `
<div class="dropdown routine-topbar" data-routine-topbar data-routine-status-url="/daily-logs/routine-status">
    <button class="icon-btn routine-topbar__button">
        <span class="notification-count routine-topbar__count" data-routine-count ${total === 0 ? 'hidden' : ''}>${total}</span>
    </button>
    <div class="dropdown-menu">
        <span data-routine-total>${total} รายการ</span>
        <span><b data-routine-waiting>2</b> รอเริ่ม</span>
        <span><b data-routine-running>1</b> กำลังทำ</span>
        <span><b data-routine-overdue>0</b> เกินเวลา</span>
    </div>
</div>`;

const mountTopbar = (attention, {total = 3} = {}) => {
    const env = mountDom();
    env.document.body.innerHTML = topbarMarkup(total);
    const calls = [];
    const controller = initRoutineTopbar({
        doc: env.document,
        fetchImpl: async (url) => {
            calls.push(url);

            return {ok: true, json: async () => ({attention})};
        },
    });

    return {...env, calls, controller};
};

test('ตัวเลขงานประจำบนแถบบนอัปเดตจากสถานะล่าสุด ไม่ใช่ค่าที่ฝังมากับหน้า', async () => {
    const ui = mountTopbar({total: 5, waiting: 3, running: 1, overdue: 1});

    await ui.controller.sync();

    assert.equal(ui.document.querySelector('[data-routine-count]').textContent, '5');
    assert.equal(ui.document.querySelector('[data-routine-count]').hidden, false);
    assert.equal(ui.document.querySelector('[data-routine-total]').textContent, '5 รายการ');
    assert.equal(ui.document.querySelector('[data-routine-waiting]').textContent, '3');
    assert.equal(ui.document.querySelector('[data-routine-running]').textContent, '1');
    assert.equal(ui.document.querySelector('[data-routine-overdue]').textContent, '1');

    ui.controller.destroy();
});

test('ไม่มีงานประจำที่ต้องจัดการแล้ว ตัวเลขต้องถูกซ่อน ไม่ใช่แสดงเลขศูนย์', async () => {
    const ui = mountTopbar({total: 0, waiting: 0, running: 0, overdue: 0});

    await ui.controller.sync();

    assert.equal(ui.document.querySelector('[data-routine-count]').hidden, true);

    ui.controller.destroy();
});

test('กดเริ่ม เสร็จ หรือไม่ได้ทำในหน้าบันทึกงาน ต้องอัปเดตแถบบนทันทีโดยไม่รอรอบถัดไป', async () => {
    const ui = mountTopbar({total: 2, waiting: 1, running: 1, overdue: 0});

    ui.document.dispatchEvent(new ui.window.CustomEvent('smartgoal:routine-changed', {detail: {logId: '9'}}));
    // ตัวจัดการเป็น async จึงต้องปล่อยให้ microtask ของ fetch เดินจบก่อนตรวจผล
    await new Promise((resolve) => ui.window.setTimeout(resolve, 0));

    assert.equal(ui.calls.length, 1, 'ต้องถามสถานะใหม่ทันทีที่งานประจำเปลี่ยน');
    assert.equal(ui.document.querySelector('[data-routine-count]').textContent, '2');

    ui.controller.destroy();
});

test('หน้าบันทึกงานประจำวันยิงเหตุการณ์หลังบันทึกสำเร็จ และเมนูงานประจำไม่ล้นจอ', async () => {
    const [page, topbar] = await Promise.all([
        read('resources/js/pages/daily-logs/index.js'),
        read('resources/css/components/layout/topbar.css'),
    ]);

    // ปุ่มทั้งสามของงานประจำใช้เส้นทางเดียวกัน เหตุการณ์จึงถูกยิงที่จุดเดียวหลังบันทึกสำเร็จ
    assert.match(page, /smartgoal:routine-changed/);
    assert.match(page, /data-row-start[\s\S]*data-row-complete[\s\S]*data-row-skip/);

    // เมนูบนจอแคบต้องกว้างไม่เกินหน้าจอ ไม่งั้นรายการงานประจำจะถูกดันออกนอกขอบ
    assert.match(topbar, /\.routine-topbar__menu\s*\{[^}]*width:\s*min\(360px,\s*calc\(100vw - 20px\)\)/s);
});
