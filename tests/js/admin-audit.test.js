import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom} from './helpers/dom.js';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

let fixture = 0;

/**
 * ติดตั้งหน้า Audit Log ในรูปแบบที่ Blade ของแท็บถังขยะ render จริง
 * แล้วโหลดโมดูลใหม่ทุกครั้ง เพราะ IIFE ผูกกับ document ตอน evaluate
 */
async function mountAuditPage(t, {canRestore = true, withFilters = false} = {}) {
    const env = mountDom();
    t.after(env.cleanup);

    env.document.body.innerHTML = `
        <div class="audit-page">
            ${withFilters ? `
            <form method="GET" action="/admin/audit" class="audit-filters" data-audit-filters>
                <input type="search" name="q" value="">
                <select name="entity_type"><option value="">ทั้งหมด</option></select>
                <button class="audit-btn audit-btn--primary" type="submit">กรอง</button>
            </form>` : ''}
            <table class="audit-table"><tbody>
                <tr>
                    <td>
                        <div class="audit-row-actions">
                            ${canRestore ? `
                            <form method="POST" action="/admin/trash/7/restore" data-audit-restore data-name="โปรเจกต์ทดสอบ">
                                <input type="hidden" name="_method" value="PATCH">
                                <button class="audit-btn audit-btn--primary" type="submit">กู้คืน</button>
                            </form>` : ''}
                            <form method="POST" action="/admin/trash/7" data-audit-purge data-name="โปรเจกต์ทดสอบ">
                                <input type="hidden" name="_method" value="DELETE">
                                <button class="audit-btn audit-btn--danger" type="submit">ลบถาวร</button>
                            </form>
                        </div>
                    </td>
                </tr>
            </tbody></table>
        </div>`;

    const submitted = [];
    // jsdom ไม่มี requestSubmit ให้ใช้ ต้องจำลองให้ยิง submit event ซ้ำเหมือนเบราว์เซอร์จริง
    // มิฉะนั้นรอบการยืนยัน "ถาม แล้วปล่อยผ่านรอบสอง" จะไม่ถูกทดสอบเลย
    env.window.HTMLFormElement.prototype.requestSubmit = function requestSubmit() {
        submitted.push(this);
        this.dispatchEvent(new env.window.Event('submit', {bubbles: true, cancelable: true}));
    };

    const swalCalls = [];
    let answer = {isConfirmed: true};
    env.window.Swal = {
        fire: (options) => {
            swalCalls.push(options);

            return Promise.resolve(answer);
        },
    };
    globalThis.window = env.window;

    const confirmCalls = [];
    env.window.confirm = (message) => {
        confirmCalls.push(message);

        return true;
    };

    fixture += 1;
    await import(`../../resources/js/pages/admin/audit.js?fixture=${fixture}`);

    return {
        ...env,
        submitted,
        swalCalls,
        confirmCalls,
        setAnswer: (value) => { answer = value; },
        form: () => env.document.querySelector('[data-audit-restore]'),
        purgeForm: () => env.document.querySelector('[data-audit-purge]'),
        submit: (form) => form.dispatchEvent(new env.window.Event('submit', {bubbles: true, cancelable: true})),
    };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

test('การกู้คืนถามยืนยันด้วย SweetAlert ไม่ใช่ confirm ของเบราว์เซอร์', async (t) => {
    const ui = await mountAuditPage(t);

    ui.submit(ui.form());
    await flush();

    assert.equal(ui.swalCalls.length, 1);
    assert.equal(ui.confirmCalls.length, 0);
    assert.match(ui.swalCalls[0].text, /โปรเจกต์ทดสอบ/);
    assert.equal(ui.swalCalls[0].showCancelButton, true);
});

test('ยืนยันแล้วจึงส่งฟอร์มจริงเพียงครั้งเดียว', async (t) => {
    const ui = await mountAuditPage(t);

    ui.submit(ui.form());
    await flush();

    assert.equal(ui.submitted.length, 1);
    assert.equal(ui.form().querySelector('button[type="submit"]').disabled, true);
});

test('กดยกเลิกแล้วต้องไม่ส่งฟอร์ม', async (t) => {
    const ui = await mountAuditPage(t);
    ui.setAnswer({isConfirmed: false});

    ui.submit(ui.form());
    await flush();

    assert.equal(ui.submitted.length, 0);
    assert.equal(ui.form().querySelector('button[type="submit"]').disabled, false);
});

test('กดซ้ำหลายครั้งถามยืนยันทีละครั้ง ไม่ผูก listener ซ้ำ', async (t) => {
    const ui = await mountAuditPage(t);

    ui.submit(ui.form());
    await flush();
    ui.submit(ui.form());
    await flush();

    // listener เดียวที่ document จึงต้องได้ Swal ครั้งละหนึ่ง ไม่ทวีคูณตามจำนวนครั้งที่กด
    assert.equal(ui.swalCalls.length, 2);
    assert.equal(ui.submitted.length, 2);
});

test('รายการที่กู้คืนไม่ได้จะไม่มีฟอร์มกู้คืนให้กด', async (t) => {
    const ui = await mountAuditPage(t, {canRestore: false});

    assert.equal(ui.form(), null);
    assert.equal(ui.swalCalls.length, 0);
});

test('หน้าเดิมทั้งสองถูกลบออกจริง ไม่ได้เก็บซ้อนไว้', async () => {
    const vite = await read('vite.config.js');

    assert.equal(vite.includes('admin-activity-logs.css'), false);
    assert.equal(vite.includes('admin-trash.css'), false);
    assert.match(vite, /resources\/css\/pages\/admin-audit\.css/);
    assert.match(vite, /resources\/js\/pages\/admin\/audit\.js/);

    for (const path of [
        'resources/views/admin/activity-logs/index.blade.php',
        'resources/views/admin/trash/index.blade.php',
        'resources/css/pages/admin/activity-logs.css',
        'resources/css/pages/admin/trash.css',
    ]) {
        await assert.rejects(() => read(path), `ยังพบไฟล์เดิมที่ควรถูกลบ: ${path}`);
    }
});

test('เมนูข้างรวมเหลือรายการเดียวและชี้ไปหน้า Audit Log', async () => {
    const layout = await read('resources/views/layouts/app.blade.php');

    assert.match(layout, /route\('admin\.audit\.index'\)/);
    assert.equal(layout.includes("route('admin.activity-logs.index')"), false);
    assert.equal(layout.includes("route('admin.trash.index')"), false);
});

test('สไตล์ที่ซ้ำกันสองไฟล์ถูกยุบเหลือชุดเดียว', async () => {
    const css = await read('resources/css/pages/admin/audit.css');

    for (const selector of ['.audit-filters', '.audit-stat', '.audit-empty', '.audit-btn', '.audit-table']) {
        assert.ok(css.includes(selector), `ขาดสไตล์ ${selector}`);
    }

    // เลย์เอาต์ต้องมีจุดตัดสำหรับจอแคบครบทั้งสองระดับ
    assert.match(css, /@media \(max-width: 1100px\)/);
    assert.match(css, /@media \(max-width: 640px\)/);
});

test('การ์ดในภาพรวมถูกจำกัดความสูงและเลื่อนอ่านในตัวเอง', async () => {
    const css = await read('resources/css/pages/admin/audit.css');

    // ถ้าไม่จำกัด รายการยาว ๆ จะดันหน้าให้ยาวจนต้องเลื่อนหาการ์ดใบข้าง ๆ
    assert.match(
        css,
        /\.audit-overview-grid > \.audit-card\s*\{[^}]*max-height:\s*\d+px[^}]*overflow:\s*hidden/s,
    );
    assert.match(css, /\.audit-stream\s*\{[^}]*overflow-y:\s*auto/s);
    assert.match(css, /\.audit-stream\s*\{[^}]*min-height:\s*0/s);
});

test('ประโยคของเหตุการณ์ตัดบรรทัดได้โดยยังเว้นช่องไฟสม่ำเสมอ', async () => {
    const css = await read('resources/css/pages/admin/audit.css');

    assert.match(css, /\.audit-sentence\s*\{[^}]*display:\s*flex[^}]*flex-wrap:\s*wrap/s);
});

/*
 * ลบถาวรอยู่ในแถวเดียวกับกู้คืน การกดพลาดหนึ่งครั้งคือข้อมูลและไฟล์หายถาวร
 * การยืนยันจึงต้องแพงกว่าการกดปุ่มเดียว คือพิมพ์ชื่อรายการให้ตรงก่อน
 */
test('การลบถาวรบังคับให้พิมพ์ชื่อรายการให้ตรงก่อนยืนยัน', async (t) => {
    const ui = await mountAuditPage(t);

    ui.submit(ui.purgeForm());
    await flush();

    assert.equal(ui.swalCalls.length, 1);
    const options = ui.swalCalls[0];

    assert.equal(options.input, 'text', 'ต้องมีช่องให้พิมพ์ยืนยัน ไม่ใช่แค่ปุ่มตกลง');
    assert.equal(typeof options.inputValidator, 'function');
    assert.equal(options.icon, 'warning');
    assert.equal(options.confirmButtonColor, '#dc2626', 'ปุ่มยืนยันต้องเป็นสีอันตราย');

    // ชื่อที่ไม่ตรงต้องถูกปฏิเสธ ชื่อที่ตรง (แม้มีช่องว่างหัวท้าย) ต้องผ่าน
    assert.equal(typeof options.inputValidator('ชื่ออื่น'), 'string');
    assert.equal(options.inputValidator(''), 'ชื่อไม่ตรงกับรายการที่จะลบ');
    assert.equal(options.inputValidator('  โปรเจกต์ทดสอบ  '), undefined);
});

/*
 * ชื่อรายการมาจากข้อมูลที่ผู้ใช้กรอก การต่อเข้า innerHTML ตรง ๆ คือช่องทาง XSS
 * บนหน้าที่มีแต่ผู้ดูแลระบบเข้าถึง ซึ่งเป็นเป้าหมายที่มีค่าที่สุดของระบบ
 */
test('ชื่อรายการในกล่องยืนยันถูกใส่ด้วย textContent ไม่ใช่ต่อเข้า HTML', async (t) => {
    const ui = await mountAuditPage(t);
    ui.purgeForm().dataset.name = '<img src=x onerror=alert(1)>';

    ui.submit(ui.purgeForm());
    await flush();

    const options = ui.swalCalls[0];
    assert.doesNotMatch(options.html, /<img/, 'ห้ามต่อชื่อรายการเข้า html');
    assert.equal(typeof options.didOpen, 'function');

    const popup = ui.document.createElement('div');
    popup.innerHTML = options.html;
    options.didOpen(popup);

    assert.equal(popup.querySelector('strong').textContent, '<img src=x onerror=alert(1)>');
    assert.equal(popup.querySelector('img'), null);
});

test('กดยกเลิกในกล่องลบถาวรแล้วต้องไม่ส่งฟอร์ม', async (t) => {
    const ui = await mountAuditPage(t);
    ui.setAnswer({isConfirmed: false});

    ui.submit(ui.purgeForm());
    await flush();

    assert.equal(ui.submitted.length, 0);
});

/*
 * ตัวกรองส่งเองเมื่อหยุดพิมพ์ ปุ่ม "กรอง" ยังอยู่สำหรับผู้ใช้คีย์บอร์ด
 * และกรณีที่ JavaScript ไม่ทำงาน
 */
test('ช่องค้นหาส่งฟอร์มเองหลังหยุดพิมพ์ และ dropdown ส่งทันทีที่เปลี่ยน', async (t) => {
    const ui = await mountAuditPage(t, {withFilters: true});
    const filters = ui.document.querySelector('[data-audit-filters]');

    filters.querySelector('input[type="search"]').dispatchEvent(
        new ui.window.Event('input', {bubbles: true})
    );
    assert.equal(ui.submitted.length, 0, 'ต้องรอให้หยุดพิมพ์ก่อน ไม่ยิงทุกตัวอักษร');

    await new Promise((resolve) => setTimeout(resolve, 600));
    assert.equal(ui.submitted.length, 1);

    filters.querySelector('select').dispatchEvent(new ui.window.Event('change', {bubbles: true}));
    assert.equal(ui.submitted.length, 2, 'เปลี่ยนตัวเลือกแล้วส่งทันที ไม่ต้องรอ');
});
