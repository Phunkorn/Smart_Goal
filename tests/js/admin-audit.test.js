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

/**
 * ติดตั้งแท็บถังขยะแบบที่มีแถบจัดการหลายรายการ
 *
 * ช่องติ๊กอยู่ในตารางแต่เป็นของฟอร์ม auditBulkForm ผ่าน form= จึงต้อง render ให้ตรง
 * กับที่ Blade ทำจริง ไม่งั้นเทสต์จะผ่านบนโครงสร้างที่ไม่มีอยู่จริง
 */
async function mountBulkPage(t, {rows = 2, filteredTotal = 0} = {}) {
    const env = mountDom();
    t.after(env.cleanup);

    const scopeToggle = filteredTotal > rows
        ? `<label class="audit-bulkbar__scope">
               <input type="checkbox" data-audit-scope-toggle data-count="${filteredTotal}">
               เลือกทั้งหมดที่กรองอยู่ (${filteredTotal} รายการ)
           </label>`
        : '';

    const body = Array.from({length: rows}, (unused, index) => `
        <tr>
            <td class="audit-col-select">
                <input type="checkbox" form="auditBulkForm" name="ids[]" value="${index + 1}" data-audit-select>
            </td>
            <td>รายการที่ ${index + 1}</td>
        </tr>`).join('');

    env.document.body.innerHTML = `
        <div class="audit-page">
            <form id="auditBulkForm" method="POST" class="audit-bulkbar" data-audit-bulk hidden>
                <input type="hidden" name="scope" value="" data-audit-scope>
                <p class="audit-bulkbar__count">เลือกแล้ว <strong data-audit-selected-count>0</strong> รายการ</p>
                ${scopeToggle}
                <div class="audit-bulkbar__actions">
                    <button type="submit" formaction="/admin/trash/bulk-restore"
                            name="_method" value="PATCH" data-audit-bulk-restore>กู้คืนที่เลือก</button>
                    <button type="submit" formaction="/admin/trash/bulk-purge"
                            name="_method" value="DELETE" data-audit-bulk-purge>ลบถาวรที่เลือก</button>
                </div>
            </form>
            <table class="audit-table audit-table--trash">
                <thead><tr><th class="audit-col-select"><input type="checkbox" data-audit-select-all></th><th>ข้อมูล</th></tr></thead>
                <tbody>${body}</tbody>
            </table>
        </div>`;

    const submitted = [];
    env.window.HTMLFormElement.prototype.requestSubmit = function requestSubmit(submitter) {
        submitted.push({form: this, submitter});
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

    fixture += 1;
    await import(`../../resources/js/pages/admin/audit.js?fixture=${fixture}`);

    const bulkForm = () => env.document.getElementById('auditBulkForm');

    return {
        ...env,
        submitted,
        swalCalls,
        setAnswer: (value) => { answer = value; },
        bulkForm,
        boxes: () => [...env.document.querySelectorAll('[data-audit-select]')],
        selectAll: () => env.document.querySelector('[data-audit-select-all]'),
        scopeToggle: () => env.document.querySelector('[data-audit-scope-toggle]'),
        check: (element, value = true) => {
            element.checked = value;
            element.dispatchEvent(new env.window.Event('change', {bubbles: true}));
        },
        // ปุ่มที่กดคือสิ่งที่บอกว่าจะกู้คืนหรือลบถาวร จึงต้องส่ง submitter ไปกับ event
        press: (button) => bulkForm().dispatchEvent(
            new env.window.SubmitEvent('submit', {bubbles: true, cancelable: true, submitter: button})
        ),
    };
}

test('ติ๊กหัวตารางหนึ่งครั้งเลือกครบทุกแถว และแถบจัดการโผล่ขึ้นมา', async (t) => {
    const page = await mountBulkPage(t, {rows: 3});

    assert.equal(page.bulkForm().hidden, true, 'ยังไม่ได้เลือกอะไร แถบต้องไม่กินที่');

    page.check(page.selectAll());

    assert.deepEqual(page.boxes().map((box) => box.checked), [true, true, true]);
    assert.equal(page.bulkForm().hidden, false);
    assert.equal(page.document.querySelector('[data-audit-selected-count]').textContent, '3');
});

test('ยกเลิกการติ๊กหนึ่งแถวแล้วตัวนับลด และแถบหายเมื่อไม่เหลือรายการ', async (t) => {
    const page = await mountBulkPage(t, {rows: 2});

    page.check(page.selectAll());
    page.check(page.boxes()[0], false);

    assert.equal(page.document.querySelector('[data-audit-selected-count]').textContent, '1');
    assert.equal(page.selectAll().indeterminate, true, 'เลือกบางส่วนต้องแสดงสถานะกลาง');

    page.check(page.boxes()[1], false);

    assert.equal(page.bulkForm().hidden, true);
});

test('เลือกทั้งหมดที่กรองอยู่ส่ง scope=filtered และนับตามตัวกรอง ไม่ใช่ตามหน้า', async (t) => {
    const page = await mountBulkPage(t, {rows: 2, filteredTotal: 57});

    page.check(page.scopeToggle());

    assert.equal(page.bulkForm().querySelector('[data-audit-scope]').value, 'filtered');
    assert.equal(page.document.querySelector('[data-audit-selected-count]').textContent, '57');

    // ยกเลิกการติ๊กรายแถว = ไม่ได้หมายถึงทั้งหมดตามตัวกรองอีกต่อไป
    page.check(page.boxes()[0], false);

    assert.equal(page.scopeToggle().checked, false);
    assert.equal(page.bulkForm().querySelector('[data-audit-scope]').value, '');
});

test('ลบถาวรหลายรายการบังคับให้พิมพ์จำนวนให้ตรงก่อนยืนยัน', async (t) => {
    const page = await mountBulkPage(t, {rows: 3});

    page.check(page.selectAll());
    page.press(page.document.querySelector('[data-audit-bulk-purge]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    const dialog = page.swalCalls.at(-1);

    assert.equal(dialog.input, 'text');
    assert.equal(dialog.inputPlaceholder, '3');
    assert.equal(dialog.inputValidator('2'), 'จำนวนไม่ตรงกับรายการที่จะลบ');
    assert.equal(dialog.inputValidator(' 3 '), undefined);
});

test('ปุ่มที่กดถูกส่งกลับเข้า requestSubmit เพื่อไม่ให้ formaction และ _method หาย', async (t) => {
    const page = await mountBulkPage(t, {rows: 1});

    page.check(page.boxes()[0]);

    const purgeButton = page.document.querySelector('[data-audit-bulk-purge]');
    page.press(purgeButton);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(page.submitted.length, 1);
    assert.equal(page.submitted[0].submitter, purgeButton);
});

test('กู้คืนหลายรายการถามยืนยันธรรมดา ไม่ต้องพิมพ์อะไร', async (t) => {
    const page = await mountBulkPage(t, {rows: 2});

    page.check(page.selectAll());
    page.press(page.document.querySelector('[data-audit-bulk-restore]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    const dialog = page.swalCalls.at(-1);

    assert.equal(dialog.input, undefined);
    assert.match(dialog.text, /2 รายการ/);
    assert.equal(page.submitted.length, 1);
});

test('สั่งงานโดยไม่เลือกอะไรเลยต้องเตือน ไม่ใช่ส่งฟอร์มเปล่า', async (t) => {
    const page = await mountBulkPage(t, {rows: 2});

    page.bulkForm().hidden = false;
    page.press(page.document.querySelector('[data-audit-bulk-purge]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(page.submitted.length, 0);
    assert.equal(page.swalCalls.at(-1).title, 'ยังไม่ได้เลือกรายการ');
});

test('ปุ่มล้างบันทึกกิจกรรมเก่าบอกจำนวนและบอกว่าอะไรจะไม่ถูกแตะ', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);

    env.document.body.innerHTML = `
        <div class="audit-page">
            <form method="POST" action="/admin/audit/activity/prune" data-audit-prune-activity data-count="128">
                <input type="hidden" name="_method" value="DELETE">
                <button class="audit-btn audit-btn--danger" type="submit">ล้างบันทึกเก่า (128)</button>
            </form>
        </div>`;

    const submitted = [];
    env.window.HTMLFormElement.prototype.requestSubmit = function requestSubmit() {
        submitted.push(this);
        this.dispatchEvent(new env.window.Event('submit', {bubbles: true, cancelable: true}));
    };

    const swalCalls = [];
    env.window.Swal = {
        fire: (options) => {
            swalCalls.push(options);

            if (options.didOpen) {
                const popup = env.document.createElement('div');
                popup.innerHTML = options.html;
                options.didOpen(popup);
                options.renderedHtml = popup.innerHTML;
            }

            return Promise.resolve({isConfirmed: true});
        },
    };
    globalThis.window = env.window;

    fixture += 1;
    await import(`../../resources/js/pages/admin/audit.js?fixture=${fixture}`);

    env.document.querySelector('[data-audit-prune-activity]')
        .dispatchEvent(new env.window.Event('submit', {bubbles: true, cancelable: true}));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(swalCalls.length, 1);
    assert.match(swalCalls[0].renderedHtml, /128/);
    assert.match(swalCalls[0].html, /365 วัน/);
    assert.equal(submitted.length, 1);
});

test('บันทึกกิจกรรมมีนโยบายอายุจริงในโค้ด ไม่ใช่แค่ข้อความบนหน้าจอ', async () => {
    const retention = await read('app/Support/LogRetention.php');
    const blade = await read('resources/views/admin/audit/partials/activity.blade.php');

    assert.match(retention, /CRITICAL_DAYS = 365/);
    assert.match(retention, /ROUTINE_DAYS = 90/);
    // หน้าจอต้องอ่านค่าจากคลาสเดียวกัน ไม่ใช่พิมพ์ตัวเลขซ้ำที่หลุดจากกันได้
    assert.match(blade, /LogRetention::CRITICAL_DAYS/);
    assert.match(blade, /LogRetention::ROUTINE_DAYS/);
});
