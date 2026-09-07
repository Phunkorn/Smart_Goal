import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

/*
 * สัญญาการต่อสายของฟีเจอร์บันทึกงานประจำวัน
 *
 * ตรวจจากซอร์สโดยตรง เพื่อดักความผิดพลาดที่ทดสอบด้วย PHPUnit ไม่เจอ และบน
 * dev server ก็ไม่แสดงอาการ คือ "สร้างไฟล์แล้วลืมลงทะเบียนใน vite.config.js"
 * ซึ่งจะไปพังตอน npm run build บนเครื่อง production เท่านั้น
 */
const read = (relativePath) => readFileSync(new URL(`../../${relativePath}`, import.meta.url), 'utf8');

/**
 * อ่านไฟล์แล้วตัดคอมเมนต์ออก
 *
 * จำเป็นสำหรับการตรวจแบบ "ต้องไม่มีข้อความนี้" เพราะคำอธิบายภาษาไทยในไฟล์
 * มักพูดถึงสิ่งที่ห้ามใช้อยู่แล้ว (เช่น "ห้ามใช้ confirm() ของเบราว์เซอร์"
 * หรือชื่อฟีเจอร์ "บันทึกงานประจำวัน" ที่มีคำว่า "งานประจำ" อยู่ข้างใน)
 */
const codeOf = (relativePath) => read(relativePath)
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1');

const viteConfig = read('vite.config.js');
const layout = read('resources/views/layouts/app.blade.php');
const cssEntry = read('resources/css/pages/daily-logs.css');
const indexView = read('resources/views/daily-logs/index.blade.php');

test('vite ลงทะเบียนไฟล์ entry ของบันทึกงานประจำวันครบ', () => {
    assert.ok(
        viteConfig.includes("'resources/css/pages/daily-logs.css'"),
        'ต้องมี CSS entry ใน vite.config.js'
    );
    assert.ok(
        viteConfig.includes("'resources/js/pages/daily-logs/index.js'"),
        'ต้องมี JS entry ของหน้าไทม์ไลน์ใน vite.config.js'
    );
    assert.ok(
        viteConfig.includes("'resources/js/pages/daily-logs/routines.js'"),
        'ต้องมี JS entry ของหน้างานประจำใน vite.config.js'
    );
});

test('หน้างานประจำเรียกใช้ entry ที่ลงทะเบียนไว้', () => {
    const routinesView = read('resources/views/daily-logs/routines.blade.php');

    assert.ok(routinesView.includes("@vite('resources/css/pages/daily-logs.css')"));
    assert.ok(routinesView.includes("@vite('resources/js/pages/daily-logs/routines.js')"));
});

/*
 * การลบแม่แบบต้องยืนยันผ่าน Swal ไม่ใช่ confirm() ของเบราว์เซอร์
 */
test('หน้างานประจำยืนยันการลบผ่าน Swal', () => {
    const routines = codeOf('resources/js/pages/daily-logs/routines.js');

    assert.ok(routines.includes('swal?.fire'));
    assert.ok(! /\bwindow\.confirm\b|\bconfirm\(/.test(routines));
});

test('หน้าบันทึกงานเรียกใช้ entry ที่ลงทะเบียนไว้จริง', () => {
    assert.ok(indexView.includes("@vite('resources/css/pages/daily-logs.css')"));
    assert.ok(indexView.includes("@vite('resources/js/pages/daily-logs/index.js')"));
});

/*
 * ไฟล์ย่อยของ CSS ไม่ได้เป็น vite input เอง จึงต้องถูก import จากไฟล์แบน
 * ถ้าลืม import สไตล์ของบล็อกนั้นจะหายไปเงียบ ๆ โดยไม่มี error
 */
test('CSS entry import ไฟล์ย่อยครบทุกบล็อกของหน้า', () => {
    ['layout', 'composer', 'timer', 'participants', 'timeline', 'summary', 'routines', 'modal', 'responsive'].forEach((partial) => {
        assert.ok(
            cssEntry.includes(`./daily-logs/${partial}.css`),
            `daily-logs.css ต้อง import ${partial}.css`
        );
    });
});

test('เมนูข้างมีบันทึกงานประจำวันทั้งฝั่งพนักงานและ admin', () => {
    const occurrences = layout.split("route('daily-logs.index')").length - 1;

    // หนึ่งครั้งในสาขา admin และอีกหนึ่งครั้งในสาขาพนักงาน
    assert.equal(occurrences, 2, 'ต้องมีเมนูทั้งสาขา admin และสาขาพนักงาน');
    assert.ok(layout.includes('บันทึกงานประจำวัน'));
    assert.ok(
        layout.includes("request()->routeIs('daily-logs.*')"),
        'เมนูต้องไฮไลต์เมื่ออยู่ในหน้าของฟีเจอร์นี้'
    );
});

/*
 * viewer เป็น read-only ของงานโครงการ และต้องไม่มีบันทึกงานประจำวันของตัวเอง
 * สาขา viewer ในเมนูจึงต้องไม่มีรายการนี้ (สิทธิ์จริงถูกบังคับที่ route และ policy
 * เทสต์นี้กันแค่ไม่ให้ผู้ใช้เห็นเมนูที่กดแล้วเจอ 403)
 */
test('สาขา viewer ในเมนูข้างไม่มีบันทึกงานประจำวัน', () => {
    const viewerBranch = layout.slice(
        layout.indexOf('@elseif ($isViewer)'),
        layout.indexOf('{{-- พนักงาน:')
    );

    assert.ok(viewerBranch.length > 0, 'ต้องหาสาขา viewer ในเลย์เอาต์เจอ');
    assert.ok(! viewerBranch.includes('daily-logs.index'));
});

/*
 * ข้อความไทยของประเภทงานต้องมาจาก WorkLogDesign ฝั่งเซิร์ฟเวอร์ผ่าน JSON island
 * ไม่ใช่เขียนซ้ำในไฟล์ .js ซึ่งเป็นปัญหาที่เกิดขึ้นแล้วกับป้ายสถานะของบอร์ดงาน
 */
test('ป้ายชื่อประเภทงานไม่ถูกเขียนซ้ำในโมดูล JavaScript', () => {
    const modules = [
        'resources/js/pages/daily-logs/index.js',
        'resources/js/pages/daily-logs/timeline.js',
        'resources/js/pages/daily-logs/filters.js',
        'resources/js/pages/daily-logs/entry-form.js',
        'resources/js/pages/daily-logs/participants.js',
    ];

    modules.forEach((path) => {
        const code = codeOf(path);

        ['งานประจำ', 'งานแทรก', 'งานนอกสถานที่'].forEach((label) => {
            assert.ok(
                ! code.includes(label),
                `${path} ต้องไม่มีป้ายชื่อ "${label}" เขียนไว้เอง — ให้อ่านจาก work-log-design`
            );
        });
    });

    assert.ok(indexView.includes('id="work-log-design"'), 'ต้องมี JSON island ของ WorkLogDesign');
});

test('หน้าบันทึกงานส่ง URL มาจากฝั่งเซิร์ฟเวอร์ ไม่ให้ client ประกอบเอง', () => {
    assert.ok(indexView.includes('id="work-log-routes"'));

    const entry = read('resources/js/pages/daily-logs/index.js');
    assert.ok(entry.includes("readJson(doc, 'work-log-routes'"));
    assert.ok(! entry.includes("'/daily-logs"), 'ห้าม hardcode เส้นทางในไฟล์ JavaScript');
});

/*
 * กติกาของโปรเจกต์: ห้ามใช้ alert/confirm/prompt ให้ใช้ window.Swal เท่านั้น
 */
test('โมดูลของหน้านี้ไม่ใช้ alert confirm หรือ prompt', () => {
    const modules = [
        'resources/js/pages/daily-logs/index.js',
        'resources/js/pages/daily-logs/timeline.js',
        'resources/js/pages/daily-logs/entry-form.js',
        'resources/js/pages/daily-logs/client.js',
        'resources/js/pages/daily-logs/summary.js',
        'resources/js/pages/daily-logs/attachments.js',
        'resources/js/pages/daily-logs/timer.js',
        'resources/js/pages/daily-logs/participants.js',
    ];

    modules.forEach((path) => {
        const source = codeOf(path);

        assert.ok(! /\bwindow\.confirm\b|\bconfirm\(/.test(source), `${path} ต้องไม่ใช้ confirm()`);
        assert.ok(! /\bwindow\.alert\b|\balert\(/.test(source), `${path} ต้องไม่ใช้ alert()`);
        assert.ok(! /\bwindow\.prompt\b|\bprompt\(/.test(source), `${path} ต้องไม่ใช้ prompt()`);
    });

    assert.ok(
        read('resources/js/pages/daily-logs/index.js').includes('swal?.fire'),
        'การยืนยันการลบต้องผ่าน Swal'
    );
});

/*
 * ฟอร์มเต็มต้องขับด้วย modal-stack ที่มีอยู่แล้ว ซึ่งเป็นเจ้าของ backdrop โฟกัส
 * Escape และการซ้อนชั้นเพียงผู้เดียว ห้ามสลับ hidden เอง
 */
/*
 * path จริงของไฟล์แนบใน storage ต้องไม่ถูกประกอบขึ้นฝั่ง client เลย
 * ไฟล์เป็นไฟล์ส่วนตัวที่ต้องผ่าน MediaController ซึ่งตรวจสิทธิ์ทุกครั้ง
 */
test('โมดูลไฟล์แนบใช้ URL จากเซิร์ฟเวอร์ ไม่ประกอบเส้นทางไฟล์เอง', () => {
    const attachments = codeOf('resources/js/pages/daily-logs/attachments.js');

    assert.ok(attachments.includes('attachment.url'), 'ต้องใช้ url ที่เซิร์ฟเวอร์ส่งมา');
    assert.ok(! attachments.includes('storage/'), 'ห้ามอ้างอิงเส้นทาง storage โดยตรง');
    assert.ok(! attachments.includes('file_path'), 'ห้ามอ่าน file_path ฝั่ง client');
    // ชื่อไฟล์ที่ผู้ใช้ตั้งเองต้องไม่ถูกใส่เข้า innerHTML
    assert.ok(
        attachments.includes('createTextNode(attachment.name)'),
        'ชื่อไฟล์ต้องใส่ผ่าน text node ไม่ใช่ innerHTML'
    );
});

test('ฟอร์มเต็มใช้ modal-stack ที่มีอยู่แล้วแทนการจัดการเอง', () => {
    const entryForm = read('resources/js/pages/daily-logs/entry-form.js');

    assert.ok(entryForm.includes("from '../../components/modal-stack.js'"));
    assert.ok(entryForm.includes('stack.open('));
    assert.ok(entryForm.includes('stack.close('));
    assert.ok(
        entryForm.includes("modal.addEventListener('modalstack:dismiss'"),
        'ต้องรับเหตุการณ์ Escape จาก modal-stack'
    );
});
