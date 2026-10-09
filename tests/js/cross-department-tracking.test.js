import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom, click, typeInto} from './helpers/dom.js';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

let fixture = 0;

/**
 * หน้าติดตาม "งานข้ามแผนกของทีม" ตามที่ outgoing-queue.blade.php render จริง
 *
 * สองคน: พัชรา มีงานล่าช้าหนึ่งใบกับงานที่เสร็จแล้วหนึ่งใบ ส่วนธนากร มีแต่งานที่กำลังทำ
 * ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย test จึงจะยังสะท้อนของจริง
 */
async function mountTrackingPage(t) {
    const env = mountDom();
    t.after(env.cleanup);

    const task = (bucket, search) => `
        <article class="cross-dept-track__task" data-cross-dept-task data-bucket="${bucket}" data-search="${search}">
            <div class="cross-dept-track__body"><p class="cross-dept-track__topic"><strong>${search}</strong></p></div>
            <p class="cross-dept-track__schedule"><span class="cross-dept-track__due">กำหนดส่ง <strong>15/09/2026</strong></span></p>
            <a class="btn cross-dept-track__action" href="/board">ดูงาน</a>
        </article>`;

    env.document.body.innerHTML = `
        <section class="admin-approvals-queue" id="outgoing-collaboration-queue">
            <div class="cross-dept-track__toolbar">
                <div class="cross-dept-track__search">
                    <input type="search" data-cross-dept-search>
                </div>
                <div class="cross-dept-track__filters">
                    <button type="button" class="cross-dept-track__filter is-active" data-cross-dept-filter="all" aria-pressed="true">ทั้งหมด <span>3</span></button>
                    <button type="button" class="cross-dept-track__filter" data-cross-dept-filter="late" aria-pressed="false">ล่าช้า <span>1</span></button>
                    <button type="button" class="cross-dept-track__filter" data-cross-dept-filter="active" aria-pressed="false">กำลังทำ <span>1</span></button>
                    <button type="button" class="cross-dept-track__filter" data-cross-dept-filter="done" aria-pressed="false">เสร็จแล้ว <span>1</span></button>
                </div>
            </div>
            <div class="cross-dept-track" data-cross-dept-list>
                <article class="cross-dept-track__member" data-cross-dept-member data-search="พัชรา it">
                    <header class="cross-dept-track__member-head">
                        <strong>พัชรา</strong>
                        <span class="cross-dept-track__member-count" data-cross-dept-count>2 งาน</span>
                    </header>
                    <div class="cross-dept-track__tasks">
                        ${task('late', 'ติดตั้งเครื่องพิมพ์')}
                        ${task('done', 'สรุปยอดบัญชี')}
                    </div>
                </article>
                <article class="cross-dept-track__member" data-cross-dept-member data-search="ธนากร it">
                    <header class="cross-dept-track__member-head">
                        <strong>ธนากร</strong>
                        <span class="cross-dept-track__member-count" data-cross-dept-count>1 งาน</span>
                    </header>
                    <div class="cross-dept-track__tasks">
                        ${task('active', 'ย้ายข้อมูลลูกค้า')}
                    </div>
                </article>
            </div>
            <p class="cross-dept-track__no-result" data-cross-dept-no-result hidden>ไม่พบงานที่ตรงกับคำค้นหรือตัวกรองนี้</p>
        </section>`;

    globalThis.window = env.window;
    env.window.Swal = {fire: () => Promise.resolve({isConfirmed: false})};

    fixture += 1;
    await import(`../../resources/js/pages/admin/approvals.js?fixture=${fixture}`);

    const members = [...env.document.querySelectorAll('[data-cross-dept-member]')];

    return {
        ...env,
        members,
        filter: (bucket) => env.document.querySelector(`[data-cross-dept-filter="${bucket}"]`),
        search: () => env.document.querySelector('[data-cross-dept-search]'),
        noResult: () => env.document.querySelector('[data-cross-dept-no-result]'),
        visibleTasks: () => [...env.document.querySelectorAll('[data-cross-dept-task]')]
            .filter((task) => !task.hidden)
            .map((task) => task.dataset.search),
        visibleMembers: () => members.filter((member) => !member.hidden).map((member) => member.dataset.search),
        counts: () => members.map((member) => member.querySelector('[data-cross-dept-count]').textContent),
    };
}

test('ตัวกรองสถานะเหลือเฉพาะงานในถังนั้น และซ่อนการ์ดของคนที่ไม่เหลืองาน', async (t) => {
    const ui = await mountTrackingPage(t);

    click(ui.filter('late'));

    assert.deepEqual(ui.visibleTasks(), ['ติดตั้งเครื่องพิมพ์']);
    assert.deepEqual(ui.visibleMembers(), ['พัชรา it']);
    // หัวการ์ดต้องนับเฉพาะงานที่ยังแสดงอยู่ ไม่งั้นจะโกหกว่ามีงานมากกว่าที่เห็น
    assert.deepEqual(ui.counts(), ['1 งาน', '0 งาน']);
    assert.equal(ui.filter('late').getAttribute('aria-pressed'), 'true');
    assert.equal(ui.filter('all').getAttribute('aria-pressed'), 'false');
    assert.equal(ui.noResult().hidden, true);

    click(ui.filter('all'));

    assert.equal(ui.visibleTasks().length, 3);
    assert.deepEqual(ui.visibleMembers().length, 2);
});

test('ค้นหาชื่อพนักงานแล้วเห็นงานของเขาครบ ไม่ใช่เฉพาะงานที่ชื่อมีคำนั้น', async (t) => {
    const ui = await mountTrackingPage(t);

    typeInto(ui.search(), 'พัชรา');

    assert.deepEqual(ui.visibleMembers(), ['พัชรา it']);
    assert.deepEqual(ui.visibleTasks(), ['ติดตั้งเครื่องพิมพ์', 'สรุปยอดบัญชี']);
});

test('ค้นหาชื่องานข้ามการ์ดได้ และตัวกรองกับคำค้นทำงานร่วมกัน', async (t) => {
    const ui = await mountTrackingPage(t);

    typeInto(ui.search(), 'ย้ายข้อมูล');

    assert.deepEqual(ui.visibleTasks(), ['ย้ายข้อมูลลูกค้า']);

    click(ui.filter('late'));

    assert.deepEqual(ui.visibleTasks(), []);
    assert.equal(ui.noResult().hidden, false);
});

test('หน้าติดตามไม่มีปุ่มตัดสินใจ และตัวกรองไม่ใช่ฟอร์มที่ยิง request ใหม่', async () => {
    const blade = await read('resources/views/admin/approvals/components/outgoing-queue.blade.php');

    assert.doesNotMatch(blade, /<form/);
    assert.doesNotMatch(blade, /data-approval-form/);
    assert.match(blade, /data-cross-dept-filter="\{\{ \$bucket \}\}"/);
    assert.match(blade, /type="search" data-cross-dept-search/);
});
