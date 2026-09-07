import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {mountDom, click} from './helpers/dom.js';
import {initAutosave} from '../../resources/js/pages/workspace/autosave.js';
import {createClient, VersionConflictError} from '../../resources/js/pages/workspace/client.js';

/*
 * เส้นทางการชนเวอร์ชันแบบครบวงจร
 *
 * นี่คือสัญญาหลักที่ผู้ใช้เลือกไว้ตอนตัดสินใจไม่ทำเรียลไทม์ คนที่บันทึกทีหลัง
 * ต้อง "ถูกบอก" ไม่ใช่ "เขียนทับเงียบ ๆ" และต้องเป็นคนตัดสินใจเองว่าจะทิ้งงาน
 * ที่เพิ่งวาดหรือไม่
 *
 * กติกาที่เทสต์ชุดนี้ล็อกไว้
 * 1. ห้ามรวมงานของสองคนเข้าด้วยกันเอง
 * 2. ห้ามยิงบันทึกด้วยเวอร์ชันเก่าหลังจากรู้แล้วว่าชนกัน
 * 3. การกดยกเลิกต้องไม่ทำให้งานของคนอื่นถูกทับ
 */

const DESIGN = {
    saveStates: {
        saved: {label: 'บันทึกแล้ว', icon: 'bi-cloud-check', tone: 'teal'},
        dirty: {label: 'ยังไม่ได้บันทึก', icon: 'bi-cloud', tone: 'gray'},
        saving: {label: 'กำลังบันทึก...', icon: 'bi-arrow-repeat', tone: 'blue'},
        error: {label: 'บันทึกไม่สำเร็จ', icon: 'bi-exclamation-triangle', tone: 'amber'},
        conflict: {label: 'มีฉบับใหม่กว่า', icon: 'bi-exclamation-octagon', tone: 'amber'},
    },
    conflictDialog: {
        title: 'มีคนอื่นบันทึกกระดานนี้แล้ว',
        confirm: 'โหลดฉบับล่าสุด',
        cancel: 'ยังไม่โหลด',
        warning: 'การโหลดฉบับล่าสุดจะทิ้งสิ่งที่คุณวาดหลังจากนั้น',
        refreshDirty: 'กระดานนี้มีสิ่งที่ยังไม่ได้บันทึก',
    },
};

const markup = () => `<!doctype html><html>
<head><meta name="csrf-token" content="test-token"></head>
<body>
<div data-workspace-board>
    <span class="wsb-save" data-workspace-save-state data-state="saved">
        <i class="bi bi-cloud-check"></i>
        <span data-workspace-save-label>บันทึกแล้ว</span>
    </span>
    <button type="button" data-workspace-refresh>รีเฟรช</button>
</div>
</body></html>`;

const mount = ({canEdit = true, confirmDialog = true} = {}) => {
    const dom = mountDom(markup());
    const swalCalls = [];
    const replaced = [];

    const swal = {
        fire: async (config) => {
            swalCalls.push(config);

            return {isConfirmed: confirmDialog};
        },
    };

    return {
        dom,
        swalCalls,
        replaced,
        swal,
        indicator: dom.document.querySelector('[data-workspace-save-state]'),
        refreshButton: dom.document.querySelector('[data-workspace-refresh]'),
        build: (client, extra = {}) => initAutosave({
            root: dom.document.querySelector('[data-workspace-board]'),
            doc: dom.document,
            design: DESIGN,
            capabilities: {canEdit},
            initialVersion: 5,
            client,
            swal,
            getDocument: () => ({schema: 1, elements: []}),
            onReplaceDocument: (payloadDocument) => replaced.push(payloadDocument),
            ...extra,
        }),
    };
};

/** client ปลอมที่บันทึกคำขอทั้งหมดไว้ให้ตรวจ */
const fakeClient = ({saveResult, loadResult} = {}) => {
    const calls = {save: [], load: 0};

    return {
        calls,
        async save(request) {
            calls.save.push(request);

            const result = typeof saveResult === 'function' ? saveResult(calls.save.length) : saveResult;

            if (result instanceof Error) {
                throw result;
            }

            return result || {version: request.baseVersion + 1, saved_at_label: '14:12'};
        },
        async load() {
            calls.load += 1;

            return loadResult || {version: 9, document: {schema: 1, elements: []}, saved_at_label: '14:20'};
        },
    };
};

const conflictError = () => new VersionConflictError({
    version: 9,
    saved_by: {name: 'สมชาย ใจดี'},
    saved_at_label: '14:10',
    document: {schema: 1, elements: [{id: 'server-1', type: 'pen', z: 1, points: [[0, 0]]}]},
});

/* ── การบันทึกตามปกติ ────────────────────────────────────────── */

test('การบันทึกส่งเวอร์ชันที่หน้านี้โหลดมา และรับเวอร์ชันใหม่กลับไป', async () => {
    const env = mount();
    const client = fakeClient();

    try {
        const autosave = env.build(client);

        await autosave.saveNow();

        assert.equal(client.calls.save[0].baseVersion, 5);
        assert.equal(autosave.currentVersion(), 6);

        await autosave.saveNow();
        assert.equal(client.calls.save[1].baseVersion, 6, 'ครั้งถัดไปต้องใช้เวอร์ชันใหม่');
    } finally {
        env.dom.cleanup();
    }
});

test('ตัวบอกสถานะสะท้อนสถานะปัจจุบันด้วยข้อความจากเซิร์ฟเวอร์', async () => {
    const env = mount();

    try {
        const autosave = env.build(fakeClient());

        assert.equal(env.indicator.dataset.state, 'saved');

        await autosave.saveNow();

        assert.equal(env.indicator.dataset.state, 'saved');
        assert.equal(
            env.indicator.querySelector('[data-workspace-save-label]').textContent,
            'บันทึกแล้ว 14:12',
            'ต้องต่อท้ายด้วยเวลาที่บันทึกจริงจากเซิร์ฟเวอร์'
        );
        assert.equal(env.indicator.querySelector('i').className, 'bi bi-cloud-check');
    } finally {
        env.dom.cleanup();
    }
});

/* ── การชนเวอร์ชัน ───────────────────────────────────────────── */

test('การชนเวอร์ชันเปิดกล่องเตือนพร้อมบอกว่าใครบันทึกไว้เมื่อไร', async () => {
    const env = mount();

    try {
        const autosave = env.build(fakeClient({saveResult: conflictError()}));

        await autosave.saveNow();
        await Promise.resolve();

        assert.equal(env.swalCalls.length, 1);
        assert.equal(env.swalCalls[0].title, DESIGN.conflictDialog.title);
        assert.match(env.swalCalls[0].text, /สมชาย ใจดี/);
        assert.match(env.swalCalls[0].text, /14:10/);
        assert.match(env.swalCalls[0].text, /จะทิ้งสิ่งที่คุณวาด/, 'ต้องบอกผลลัพธ์ให้ชัด ไม่ใช่แค่แจ้งว่าเกิดอะไร');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * กู้คืนได้ในคำขอเดียว เพราะเซิร์ฟเวอร์ส่งเนื้อหาฉบับล่าสุดมากับ 409 อยู่แล้ว
 * ไม่ต้องยิง GET ตามอีกรอบขณะที่ผู้ใช้กำลังรออยู่หน้ากล่อง
 */
test('กดโหลดฉบับล่าสุดแทนเนื้อหาทันทีโดยไม่ยิงคำขอเพิ่ม', async () => {
    const env = mount({confirmDialog: true});
    const client = fakeClient({saveResult: conflictError()});

    try {
        const autosave = env.build(client);

        await autosave.saveNow();
        await Promise.resolve();
        await Promise.resolve();

        assert.equal(client.calls.load, 0, 'ต้องใช้เนื้อหาที่มากับ 409 ไม่ยิง GET ซ้ำ');
        assert.equal(env.replaced.length, 1);
        assert.equal(env.replaced[0].elements[0].id, 'server-1');
        assert.equal(autosave.currentVersion(), 9, 'ต้องรับเวอร์ชันใหม่ก่อนเปิดบันทึกอีกครั้ง');
    } finally {
        env.dom.cleanup();
    }
});

test('หลังโหลดฉบับล่าสุดแล้ว การบันทึกครั้งถัดไปใช้เวอร์ชันใหม่', async () => {
    const env = mount({confirmDialog: true});
    let attempt = 0;
    const client = fakeClient({
        saveResult: () => {
            attempt += 1;

            return attempt === 1 ? conflictError() : {version: 10, saved_at_label: '14:30'};
        },
    });

    try {
        const autosave = env.build(client);

        await autosave.saveNow();
        await Promise.resolve();
        await Promise.resolve();

        await autosave.saveNow();

        assert.equal(client.calls.save[1].baseVersion, 9, 'ห้ามยิงด้วยเวอร์ชันเก่าอีก');
        assert.equal(env.indicator.dataset.state, 'saved');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * กดยกเลิก = หยุดบันทึกอัตโนมัติค้างไว้ เครื่องมือยังใช้ได้เพื่อให้ผู้ใช้คัดลอก
 * งานของตัวเองไว้ก่อน แต่ต้องไม่มีทางเขียนทับงานของคนอื่น
 */
test('กดยังไม่โหลดทำให้ค้างสถานะชน และไม่แทนเนื้อหา', async () => {
    const env = mount({confirmDialog: false});
    const client = fakeClient({saveResult: conflictError()});

    try {
        const autosave = env.build(client);

        await autosave.saveNow();
        await Promise.resolve();
        await Promise.resolve();

        assert.equal(env.replaced.length, 0, 'ห้ามแทนเนื้อหาโดยที่ผู้ใช้ไม่ได้สั่ง');
        assert.equal(env.indicator.dataset.state, 'conflict');
        assert.equal(autosave.currentVersion(), 5, 'ยังถือเวอร์ชันเดิมไว้');

        // พยายามบันทึกซ้ำต้องไม่ยิงออกไปอีก
        autosave.markDirty();
        await autosave.saveNow();

        assert.equal(client.calls.save.length, 1, 'ห้ามยิงบันทึกซ้ำหลังรู้ว่าชนกัน');
    } finally {
        env.dom.cleanup();
    }
});

/* ── ปุ่มรีเฟรช ──────────────────────────────────────────────── */

test('ปุ่มรีเฟรชโหลดเนื้อหาใหม่เมื่อไม่มีอะไรค้าง', async () => {
    const env = mount();
    const client = fakeClient();

    try {
        env.build(client);

        click(env.refreshButton);
        await Promise.resolve();
        await Promise.resolve();

        assert.equal(client.calls.load, 1);
        assert.equal(env.swalCalls.length, 0, 'ไม่มีของค้างก็ไม่ต้องถาม');
        assert.equal(env.replaced.length, 1);
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มรีเฟรชถามก่อนเมื่อยังมีสิ่งที่ไม่ได้บันทึก', async () => {
    const env = mount({confirmDialog: false});
    const client = fakeClient();

    try {
        const autosave = env.build(client);

        autosave.markDirty();
        await autosave.refresh();

        assert.equal(env.swalCalls.length, 1);
        assert.match(env.swalCalls[0].text, /ยังไม่ได้บันทึก/);
        assert.equal(client.calls.load, 0, 'กดยกเลิกต้องไม่โหลดทับ');
    } finally {
        env.dom.cleanup();
    }
});

/* ── ผู้ที่ดูอย่างเดียว ──────────────────────────────────────── */

test('ผู้ที่ดูอย่างเดียวยังรีเฟรชได้ แต่ไม่มีตัวจัดตารางบันทึก', async () => {
    const env = mount({canEdit: false});
    const client = fakeClient();

    try {
        const autosave = env.build(client);

        assert.equal(autosave.scheduler, null);

        click(env.refreshButton);
        await Promise.resolve();
        await Promise.resolve();

        assert.equal(client.calls.load, 1);
        assert.equal(client.calls.save.length, 0);
    } finally {
        env.dom.cleanup();
    }
});

/* ── ตัว client ──────────────────────────────────────────────── */

test('การตอบ 409 ถูกแปลงเป็นข้อผิดพลาดที่แยกออกจาก error ทั่วไป', async () => {
    const dom = mountDom(markup());

    try {
        const payload = {version: 9, message: 'ชนกัน'};
        const client = createClient({
            routes: {document: '/d', save: '/s'},
            doc: dom.document,
            fetchImpl: async () => ({ok: false, status: 409, json: async () => payload}),
        });

        await assert.rejects(
            () => client.save({document: {}, baseVersion: 5}),
            (error) => error.isVersionConflict === true && error.payload.version === 9
        );
    } finally {
        dom.cleanup();
    }
});

test('การบันทึกส่งเป็น JSON พร้อม _method และโทเคน CSRF', async () => {
    const dom = mountDom(markup());
    const requests = [];

    try {
        const client = createClient({
            routes: {document: '/d', save: '/s'},
            doc: dom.document,
            fetchImpl: async (url, init) => {
                requests.push({url, init});

                return {ok: true, status: 200, json: async () => ({ok: true, version: 6})};
            },
        });

        await client.save({document: {schema: 1, elements: []}, baseVersion: 5});

        const {init} = requests[0];
        const body = JSON.parse(init.body);

        assert.equal(init.headers['X-CSRF-TOKEN'], 'test-token');
        assert.equal(init.headers['Content-Type'], 'application/json');
        assert.equal(body._method, 'PUT');
        assert.equal(body.base_version, 5);
    } finally {
        dom.cleanup();
    }
});

/*
 * เพดาน 64 KB ของ sendBeacon และ fetch แบบ keepalive จะตัดเนื้อหากระดานจริง
 * ทิ้งอย่างเงียบสนิท ตรวจจากไฟล์ต้นฉบับเพราะเป็นข้อห้ามเชิงออกแบบ ไม่ใช่
 * พฤติกรรมที่เรียกออกมาทดสอบได้
 */
test('การบันทึกไม่ใช้ sendBeacon หรือ keepalive', () => {
    ['autosave.js', 'client.js', 'save-state.js'].forEach((file) => {
        const source = fs.readFileSync(`resources/js/pages/workspace/${file}`, 'utf8');
        const code = source
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/(^|[^:])\/\/.*$/gm, '$1');

        assert.doesNotMatch(code, /sendBeacon/, `${file} ต้องไม่ใช้ sendBeacon`);
        assert.doesNotMatch(code, /keepalive/, `${file} ต้องไม่ใช้ keepalive`);
    });
});

test('การเตือนก่อนออกจากหน้าใช้กลไกของเบราว์เซอร์ ไม่ใช่ confirm()', () => {
    const source = fs.readFileSync('resources/js/pages/workspace/autosave.js', 'utf8');
    const code = source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1');

    assert.doesNotMatch(code, /(^|[^.\w])(alert|confirm|prompt)\s*\(/m);
    assert.match(code, /beforeunload/);
});
