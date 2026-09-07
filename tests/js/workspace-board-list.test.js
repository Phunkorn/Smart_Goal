import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {mountDom, click, typeInto, pressKey} from './helpers/dom.js';
import {initBoardList} from '../../resources/js/pages/workspace/board-list.js';

/*
 * หน้ารายการกระดานไอเดีย
 *
 * ตรวจสองเรื่องที่เทสต์ฝั่ง PHP มองไม่เห็น คือพฤติกรรมของ modal (เปิด/ปิด/คืนโฟกัส
 * และไม่ผูก listener ซ้ำเมื่อเปิดหลายรอบ) กับการที่การลบต้องผ่าน window.Swal
 * ไม่ใช่ confirm() ของเบราว์เซอร์ ตามกติกาใน CLAUDE.md
 */

const markup = ({withBoard = true} = {}) => `<!doctype html>
<html><head><meta name="csrf-token" content="test-token"></head><body>
<div class="ws-page" data-workspace-list>
    <script type="application/json" id="workspace-list-routes">{"store":"/workspace/boards"}</script>

    <button type="button" data-workspace-create data-department-id="7">สร้างกระดานใหม่</button>

    ${withBoard ? `
    <article data-workspace-board-card data-board-id="12">
        <button type="button" data-workspace-settings
            data-board-id="12"
            data-board-title="ไอเดียแจ้งซ่อม"
            data-board-visibility="department"
            data-update-url="/workspace/boards/12">ตั้งค่า</button>
        <button type="button" data-workspace-delete
            data-board-id="12"
            data-board-title="ไอเดียแจ้งซ่อม"
            data-delete-url="/workspace/boards/12">ลบ</button>
    </article>` : ''}

    <div class="ws-modal" data-workspace-modal hidden>
        <div class="ws-modal__backdrop" data-workspace-modal-dismiss></div>
        <div class="ws-modal__dialog" role="dialog" aria-modal="true">
            <form data-workspace-form novalidate>
                <h2 data-workspace-modal-title>สร้างกระดานใหม่</h2>
                <button type="button" data-workspace-modal-dismiss>ปิด</button>
                <input type="text" name="title" data-workspace-title>
                <label><input type="radio" name="visibility" value="organization" checked></label>
                <label><input type="radio" name="visibility" value="department"></label>
                <p data-workspace-error hidden></p>
                <button type="submit" data-workspace-submit>บันทึก</button>
            </form>
        </div>
    </div>
</div>
</body></html>`;

/** ตัวช่วยประกอบสภาพแวดล้อมทดสอบพร้อม fetch และ Swal ปลอม */
const mount = (options = {}) => {
    const dom = mountDom(markup(options));
    const calls = [];
    const navigations = [];
    let reloads = 0;

    const fetchImpl = async (url, init) => {
        const fields = {};
        init.body.forEach((value, key) => { fields[key] = value; });
        calls.push({url, fields});

        return {
            ok: options.responseOk !== false,
            json: async () => options.payload ?? {ok: true, redirect: '/workspace/boards/99'},
        };
    };

    const swal = {
        calls: [],
        fire: async (config) => {
            swal.calls.push(config);

            return {isConfirmed: options.confirmDelete !== false};
        },
    };

    const controller = initBoardList({
        root: dom.document.querySelector('[data-workspace-list]'),
        doc: dom.document,
        fetchImpl,
        swal,
        navigate: (url) => navigations.push(url),
        reload: () => { reloads += 1; },
    });

    return {dom, calls, navigations, swal, controller, reloadCount: () => reloads};
};

test('ปุ่มสร้างเปิดกล่องในโหมดสร้าง พร้อมล็อกการเลื่อนหน้าเบื้องหลัง', () => {
    const env = mount();

    try {
        const doc = env.dom.document;
        const modal = doc.querySelector('[data-workspace-modal]');

        assert.equal(modal.hidden, true);

        click(doc.querySelector('[data-workspace-create]'));

        assert.equal(modal.hidden, false);
        assert.equal(doc.querySelector('[data-workspace-modal-title]').textContent, 'สร้างกระดานใหม่');
        assert.equal(doc.querySelector('[data-workspace-title]').value, '');
        assert.equal(doc.querySelector('[data-workspace-form]').dataset.departmentId, '7');
        assert.ok(doc.body.classList.contains('ws-modal-open'), 'modal ต้องล็อกการเลื่อนหน้า ต่างจาก popover');
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มตั้งค่าเปิดกล่องพร้อมค่าเดิมของกระดานใบนั้น', () => {
    const env = mount();

    try {
        const doc = env.dom.document;

        click(doc.querySelector('[data-workspace-settings]'));

        assert.equal(doc.querySelector('[data-workspace-modal-title]').textContent, 'ตั้งค่ากระดาน');
        assert.equal(doc.querySelector('[data-workspace-title]').value, 'ไอเดียแจ้งซ่อม');
        assert.equal(
            doc.querySelector('input[name="visibility"]:checked').value,
            'department',
            'กล่องต้องแสดงระดับการมองเห็นปัจจุบัน ไม่ใช่ค่าเริ่มต้นเสมอ'
        );
    } finally {
        env.dom.cleanup();
    }
});

test('การสร้างส่ง department_id ไปด้วย แล้วพาไปที่กระดานใหม่', async () => {
    const env = mount();

    try {
        const doc = env.dom.document;

        click(doc.querySelector('[data-workspace-create]'));
        typeInto(doc.querySelector('[data-workspace-title]'), 'ไอเดียใหม่');
        doc.querySelector('[data-workspace-form]').dispatchEvent(
            new env.dom.window.Event('submit', {bubbles: true, cancelable: true})
        );

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(env.calls.length, 1);
        assert.equal(env.calls[0].url, '/workspace/boards');
        assert.equal(env.calls[0].fields.title, 'ไอเดียใหม่');
        assert.equal(env.calls[0].fields.department_id, '7');
        assert.equal(env.calls[0].fields._method, undefined, 'การสร้างเป็น POST จริง ไม่ต้อง override');
        assert.deepEqual(env.navigations, ['/workspace/boards/99']);
    } finally {
        env.dom.cleanup();
    }
});

test('การแก้ไขส่งเป็น PATCH ผ่าน _method และไม่ส่ง department_id', async () => {
    const env = mount({payload: {ok: true}});

    try {
        const doc = env.dom.document;

        click(doc.querySelector('[data-workspace-settings]'));
        doc.querySelector('[data-workspace-form]').dispatchEvent(
            new env.dom.window.Event('submit', {bubbles: true, cancelable: true})
        );

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(env.calls[0].url, '/workspace/boards/12');
        assert.equal(env.calls[0].fields._method, 'PATCH');
        assert.equal(env.calls[0].fields.department_id, undefined);
        assert.equal(env.reloadCount(), 1, 'การแก้ไขโหลดหน้าเดิมซ้ำ เพื่อให้ทุกการ์ดสะท้อนค่าใหม่');
    } finally {
        env.dom.cleanup();
    }
});

test('ชื่อว่างถูกปฏิเสธที่ฝั่งหน้าจอ โดยไม่ยิงคำขอไปเซิร์ฟเวอร์', async () => {
    const env = mount();

    try {
        const doc = env.dom.document;

        click(doc.querySelector('[data-workspace-create]'));
        typeInto(doc.querySelector('[data-workspace-title]'), '   ');
        doc.querySelector('[data-workspace-form]').dispatchEvent(
            new env.dom.window.Event('submit', {bubbles: true, cancelable: true})
        );

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(env.calls.length, 0);
        assert.equal(doc.querySelector('[data-workspace-error]').hidden, false);
        assert.equal(doc.querySelector('[data-workspace-modal]').hidden, false, 'กล่องต้องยังเปิดให้แก้ไขต่อ');
    } finally {
        env.dom.cleanup();
    }
});

test('ข้อความผิดพลาดจากเซิร์ฟเวอร์ถูกแสดงในกล่อง ไม่ใช่ปิดกล่องทิ้ง', async () => {
    const env = mount({responseOk: false, payload: {ok: false, message: 'ชื่อกระดานยาวเกินกำหนด'}});

    try {
        const doc = env.dom.document;

        click(doc.querySelector('[data-workspace-create]'));
        typeInto(doc.querySelector('[data-workspace-title]'), 'ชื่อยาว');
        doc.querySelector('[data-workspace-form]').dispatchEvent(
            new env.dom.window.Event('submit', {bubbles: true, cancelable: true})
        );

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(doc.querySelector('[data-workspace-error]').textContent, 'ชื่อกระดานยาวเกินกำหนด');
        assert.equal(doc.querySelector('[data-workspace-modal]').hidden, false);
        assert.equal(doc.querySelector('[data-workspace-submit]').disabled, false, 'ปุ่มต้องกลับมากดได้');
    } finally {
        env.dom.cleanup();
    }
});

test('Escape ปิดกล่องและคืนโฟกัสให้ปุ่มที่เปิดมัน', () => {
    const env = mount();

    try {
        const doc = env.dom.document;
        const trigger = doc.querySelector('[data-workspace-create]');

        click(trigger);
        pressKey(doc, 'Escape');

        assert.equal(doc.querySelector('[data-workspace-modal]').hidden, true);
        assert.equal(doc.body.classList.contains('ws-modal-open'), false);
        assert.equal(doc.activeElement, trigger, 'โฟกัสต้องกลับไปที่ปุ่มเดิม ไม่หลุดไปต้นหน้า');
    } finally {
        env.dom.cleanup();
    }
});

test('เปิดกล่องซ้ำหลายรอบต้องไม่ผูก listener เพิ่ม', async () => {
    const env = mount({payload: {ok: true}});

    try {
        const doc = env.dom.document;
        const form = doc.querySelector('[data-workspace-form]');

        for (let round = 0; round < 3; round += 1) {
            click(doc.querySelector('[data-workspace-settings]'));
            pressKey(doc, 'Escape');
        }

        click(doc.querySelector('[data-workspace-settings]'));
        form.dispatchEvent(new env.dom.window.Event('submit', {bubbles: true, cancelable: true}));

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(env.calls.length, 1, 'การเปิดซ้ำต้องไม่ทำให้ยิงคำขอหลายครั้งต่อการกดบันทึกครั้งเดียว');
    } finally {
        env.dom.cleanup();
    }
});

test('การเรียก init ซ้ำบน root เดิมไม่ผูกตัวจัดการซ้ำ', () => {
    const env = mount();

    try {
        const root = env.dom.document.querySelector('[data-workspace-list]');

        assert.equal(root.dataset.workspaceListReady, 'on');
        assert.equal(initBoardList({root, doc: env.dom.document}), null);
    } finally {
        env.dom.cleanup();
    }
});

test('การลบถามยืนยันผ่าน window.Swal และส่ง DELETE เมื่อยืนยัน', async () => {
    const env = mount({payload: {ok: true}});

    try {
        click(env.dom.document.querySelector('[data-workspace-delete]'));

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(env.swal.calls.length, 1);
        assert.match(env.swal.calls[0].title, /ลบกระดาน/);
        assert.equal(env.calls[0].url, '/workspace/boards/12');
        assert.equal(env.calls[0].fields._method, 'DELETE');
        assert.equal(env.reloadCount(), 1);
    } finally {
        env.dom.cleanup();
    }
});

test('การกดยกเลิกในกล่องยืนยันต้องไม่ลบอะไรเลย', async () => {
    const env = mount({confirmDelete: false});

    try {
        click(env.dom.document.querySelector('[data-workspace-delete]'));

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(env.calls.length, 0);
        assert.equal(env.reloadCount(), 0);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * โค้ดฝั่งหน้าจอต้องไม่ใช้กล่องโต้ตอบของเบราว์เซอร์ ตามกติกาใน CLAUDE.md
 * ตรวจจากไฟล์ต้นฉบับโดยตรง เพราะการเรียก confirm() อาจอยู่ในเส้นทางที่เทสต์
 * ด้านบนไม่ได้เดินผ่าน
 */
test('หน้ารายการไม่เรียก alert/confirm/prompt ของเบราว์เซอร์', () => {
    // ตัวกล่องและคำสั่งอยู่ใน board-settings.js เพราะใช้ร่วมกับหน้าวาด
    // จึงต้องตรวจทั้งสองไฟล์ ไม่ใช่เฉพาะไฟล์ที่ต่อสายเหตุการณ์
    const files = ['board-list.js', 'board-settings.js'];
    let combined = '';

    files.forEach((file) => {
        const source = fs.readFileSync(`resources/js/pages/workspace/${file}`, 'utf8');

        // ตัดคอมเมนต์ออกก่อน เพราะคอมเมนต์อธิบายกติกาเองว่า "ไม่ใช่ confirm()"
        // ซึ่งไม่ใช่การเรียกใช้จริง
        const code = source
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/(^|[^:])\/\/.*$/gm, '$1');

        assert.doesNotMatch(code, /(^|[^.\w])(alert|confirm|prompt)\s*\(/m, `${file} ต้องไม่เรียกกล่องของเบราว์เซอร์`);
        combined += code;
    });

    assert.match(combined, /swal\.fire/);
});
