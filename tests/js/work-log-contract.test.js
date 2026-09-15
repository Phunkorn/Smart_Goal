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
});

/*
 * งานประจำถูกยุบเข้ามาเป็น "โหมด" หนึ่งของกล่องเพิ่มงาน ไม่มีหน้าแยก ไม่มี entry
 * ของตัวเอง และไม่มีกล่องของตัวเองอีกแล้ว โมดูลนี้ถูก import จาก index.js
 * เพื่อให้ฟอร์มงานประจำมีชุดเดียวในระบบ
 */
test('งานประจำไม่มี entry และไม่มีกล่องแยกอีกต่อไป', () => {
    assert.ok(
        ! viteConfig.includes("'resources/js/pages/daily-logs/routines.js'"),
        'หน้างานประจำถูกยุบเข้ามาแล้ว จึงต้องไม่มี entry ค้างใน vite.config.js'
    );

    const entry = read('resources/js/pages/daily-logs/index.js');

    assert.ok(entry.includes("from './routines.js'"));
    assert.ok(entry.includes('initRoutinePanel('));
    assert.ok(
        ! indexView.includes('daily-logs.components.routine-modal'),
        'กล่องงานประจำถูกยุบเข้ากล่องเพิ่มงานแล้ว จึงต้องไม่ include กล่องเดิมอีก'
    );

    const entryModal = read('resources/views/daily-logs/components/entry-modal.blade.php');

    assert.ok(entryModal.includes('data-entry-panel="routine"'), 'งานประจำต้องเป็นโหมดในกล่องเพิ่มงาน');
    assert.ok(entryModal.includes('data-routine-form'));
});

/*
 * ปุ่ม +เพิ่มงานมีทางเดียวและเปิดกล่องเลือกประเภท
 * การจัดการแม่แบบเดิมในแท็บปฏิทินไม่ใช่ quick-add
 */
test('หน้าบันทึกงานมีปุ่มเพิ่มงานปุ่มเดียวเป็นทางเข้า', () => {
    const launcher = read('resources/views/daily-logs/components/launcher.blade.php');
    const header = read('resources/views/daily-logs/components/day-header.blade.php');

    assert.ok(header.includes('daily-logs.components.launcher'));
    assert.ok(launcher.includes('data-open-entry-modal'));
    assert.equal(launcher.split('data-open-entry-modal').length - 1, 1, 'ปุ่ม +เพิ่มงานต้องมีทางเดียว');
    assert.ok(read('resources/views/daily-logs/components/entry-modal.blade.php').includes('data-entry-panel="choice"'));
});

/*
 * การลบงานประจำต้องยืนยันผ่าน Swal ไม่ใช่ confirm() ของเบราว์เซอร์ และโมดูลนี้
 * ต้องไม่แตะการเปิด/ปิดกล่องเอง เพราะเจ้าของสถานะนั้นมีรายเดียวคือ modal-stack
 * ที่ถูกเรียกผ่าน entry-form.js
 */
test('โหมดงานประจำยืนยันการลบผ่าน Swal และไม่เป็นเจ้าของ overlay เอง', () => {
    const routines = codeOf('resources/js/pages/daily-logs/routines.js');

    assert.ok(routines.includes('swal?.fire'));
    assert.ok(! /\bwindow\.confirm\b|\bconfirm\(/.test(routines));
    assert.ok(! routines.includes('modal-stack.js'), 'ห้ามมีเจ้าของ overlay รายที่สอง');
});

/*
 * ระบบจับเวลาถูกถอดออกจากหน้านี้แล้ว — งานถูกปิดด้วยการกดยืนยันครั้งเดียว
 * ต้องไม่เหลือซากไว้ในหน้าจอ สไตล์ หรือโมดูล ตามกติกาใน CLAUDE.md ที่ห้าม
 * ทิ้ง UI เก่าไว้แบบซ่อน ปิดการทำงาน หรือรันคู่ขนาน
 */
test('ไม่มีซากของตัวจับเวลาเหลืออยู่ในหน้าบันทึกงาน', () => {
    const cssEntryFile = read('resources/css/pages/daily-logs.css');

    assert.ok(! cssEntryFile.includes('timer.css'), 'ต้องไม่ import สไตล์ของตัวจับเวลาอีก');

    const entry = codeOf('resources/js/pages/daily-logs/index.js');

    ['timer.js', 'timerStart', 'timerResume', 'timerStop', 'data-row-timer'].forEach((needle) => {
        assert.ok(! entry.includes(needle), `index.js ต้องไม่อ้างถึง ${needle}`);
    });

    const card = read('resources/views/daily-logs/components/log-card.blade.php');

    assert.ok(! card.includes('data-row-timer-start'));
    assert.ok(! card.includes('data-row-timer-stop'));
    assert.ok(! card.includes('data-timer-banner'));
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
    ['layout', 'launcher', 'calendar', 'plan-management', 'month', 'participants', 'timeline', 'routines', 'modal', 'responsive'].forEach((partial) => {
        assert.ok(
            cssEntry.includes(`./daily-logs/${partial}.css`),
            `daily-logs.css ต้อง import ${partial}.css`
        );
    });
});

/*
 * หน้านี้ใช้ data-date-picker (ช่องวันที่ในกล่องเพิ่มงาน) แต่เคยไม่ได้ import สไตล์ของปฏิทิน
 * popover จึงไม่มี position:fixed/z-index แล้วไปกองเป็นปุ่มตัวเลขท้ายหน้า ข้างหลังกล่อง
 * ผู้ใช้เลยเลือกหลายวันไม่ได้ ทั้งที่ JavaScript ทำงานถูก
 */
test('หน้าบันทึกงานโหลดสไตล์ของปฏิทินเลือกวัน เพราะมีช่อง data-date-picker', () => {
    assert.ok(read('resources/views/daily-logs/components/entry-modal.blade.php').includes('data-date-picker'));
    assert.ok(
        cssEntry.includes("@import '../components/date-picker.css';"),
        'daily-logs.css ต้อง import components/date-picker.css'
    );
});

test('เมนูข้างมีบันทึกงานประจำวันทั้งฝั่งพนักงานและ admin', () => {
    const occurrences = layout.split("route('daily-logs.index')").length - 1;

    // อย่างน้อยหนึ่งครั้งในสาขา admin และอีกหนึ่งครั้งในสาขาพนักงาน
    // ลิงก์จากตัวแจ้งเตือนงานประจำบน topbar อาจใช้ route เดียวกันเพิ่มเติมได้
    assert.ok(occurrences >= 2, 'ต้องมีเมนูทั้งสาขา admin และสาขาพนักงาน');
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
    // ยึดจุดตัดที่โครงสร้างของ Blade เอง ไม่ใช่ข้อความคอมเมนต์ซึ่งแก้ถ้อยคำได้ตลอด
    const marker = '@elseif ($isViewer)';
    const viewerStart = layout.indexOf(marker);
    // ค้นหา @else ถัดจากตัว @elseif เอง ไม่งั้นจะเจอตัวมันเองแล้วได้ช่วงว่าง
    const viewerBranch = layout.slice(viewerStart, layout.indexOf('@else', viewerStart + marker.length));

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

        ['งานประจำ', 'งานนอกสถานที่'].forEach((label) => {
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
        'resources/js/pages/daily-logs/plan-calendar.js',
        'resources/js/pages/daily-logs/attachments.js',
        'resources/js/pages/daily-logs/routines.js',
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

/*
 * กล่องยืนยันที่เปิดจากในกล่องเพิ่มงานต้องอยู่ "เหนือ" กล่องนั้น
 *
 * SweetAlert2 ต่อ container เข้ากับ body ที่ z-index 1060 ซึ่งต่ำกว่าชั้นของ
 * modal-stack (เริ่มที่ 1200) ถ้าลืมส่งคลาสนี้ กล่องยืนยันจะไปโผล่ข้างหลังแล้ว
 * ผู้ใช้กดอะไรไม่ได้เลย — เป็นบั๊กที่เกิดขึ้นมาแล้วกับปุ่มลบงานประจำ
 */
test('กล่องยืนยันที่เปิดจากในกล่องเพิ่มงานอยู่เหนือกล่องเสมอ', () => {
    const modalCss = read('resources/css/pages/daily-logs/modal.css');
    const rule = modalCss.match(/\.swal2-container\.daily-log-dialog \{([^}]*)\}/)?.[1] ?? '';
    const layer = Number(rule.match(/z-index:\s*(\d+)/)?.[1] ?? 0);

    assert.ok(layer > 1200, `กล่องยืนยันต้องอยู่เหนือชั้นของ modal-stack แต่ได้ ${layer}`);

    // ทุกที่ที่เรียก Swal จากในกล่องต้องส่งคลาสนี้ ไม่งั้นอาการจะกลับมา
    ['routines.js', 'attachments.js'].forEach((file) => {
        const code = codeOf(`resources/js/pages/daily-logs/${file}`);

        assert.ok(
            code.includes('customClass: DAILY_LOG_DIALOG_CLASS'),
            `${file} ต้องส่ง customClass ให้กล่องยืนยันที่เปิดจากในกล่อง`
        );
    });

    // ค่าคงที่มีที่อยู่เดียวในโมดูลร่วม ไม่ใช่สตริงที่พิมพ์ซ้ำในแต่ละไฟล์
    assert.ok(
        codeOf('resources/js/pages/daily-logs/client.js').includes("container: 'daily-log-dialog'"),
        'ชื่อคลาสต้องประกาศไว้ที่เดียวใน client.js'
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
