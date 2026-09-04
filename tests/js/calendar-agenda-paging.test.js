import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

/*
 * การ์ดสรุปใต้ปฏิทินแสดงครั้งละสิบแถว
 *
 * เดือนที่ยุ่งมีได้หลายสิบรายการ ถ้าปล่อยยาวลงไปทั้งหมด การ์ดจะสูงกว่าตัวปฏิทินเอง
 * และผู้ใช้ต้องเลื่อนผ่านมันทุกครั้งเพื่อกลับไปเปลี่ยนเดือน
 */

test('both agenda cards ship a pager from Blade instead of building one in JS', async () => {
    const blade = await read('resources/views/tasks/partials/calendar.blade.php');

    // ปุ่มมาจาก Blade เสมอ การวาดการ์ดใหม่ทุกครั้งที่เปลี่ยนเดือนจึงไม่สร้างปุ่มซ้อน
    const pagers = blade.match(/data-calendar-agenda-pager="(today|month)"/g) ?? [];
    assert.deepEqual(pagers.sort(), [
        'data-calendar-agenda-pager="month"',
        'data-calendar-agenda-pager="today"',
    ]);

    // ระวัง: "data-calendar-agenda-page" เป็นคำนำหน้าของ "...pager" ด้วย จึงต้องบังคับตัวคั่นท้ายคำ
    for (const hook of ['data-calendar-agenda-previous', 'data-calendar-agenda-next', 'data-calendar-agenda-page(?= )']) {
        assert.equal((blade.match(new RegExp(hook, 'g')) ?? []).length, 2, `${hook} ต้องมีการ์ดละหนึ่งตัว`);
    }

    // ซ่อนไว้ตั้งแต่ HTML แรก แล้วให้ JS เปิดเมื่อรายการเกินหนึ่งหน้าจริง
    assert.match(blade, /data-calendar-agenda-pager="today" aria-label="เปลี่ยนหน้ารายการ" hidden/);
});

test('the pager shows only when there is more than one page and never leaves a dead page', async () => {
    const script = await read('resources/js/pages/mytasks/calendar.js');

    assert.match(script, /const AGENDA_PAGE_SIZE = 10;/);
    assert.match(script, /pager\.hidden = pages <= 1;/, 'หน้าเดียวต้องไม่มีปุ่มเปลี่ยนหน้า');
    assert.match(script, /\.disabled = page === 0;/);
    assert.match(script, /\.disabled = page >= pages - 1;/);

    // เปลี่ยนเดือนหรือค้นหาแล้วชุดรายการเปลี่ยน ต้องกลับหน้าแรกเสมอ
    assert.match(script, /agendaPages\.today = 0;\s*\n\s*agendaPages\.month = 0;/);

    // ตัวนับบนหัวการ์ดต้องบอกจำนวนทั้งหมด ไม่ใช่จำนวนของหน้าที่กำลังดู
    assert.match(script, /count\.textContent = `\$\{items\.length\} \$\{unit\}`;/);

    // และหน้าที่ค้างอยู่ต้องถูกหนีบให้ไม่เกินจำนวนหน้าที่มีจริง
    assert.match(script, /Math\.min\(agendaPages\[section\] \?\? 0, pages - 1\)/);
});

test('the pager has its own styles and disappears with the nav it belongs to', async () => {
    const css = await read('resources/css/components/task-workspace/calendar/agenda.css');

    assert.match(css, /\.mytasks-calendar-agenda__pager \{[^}]*display: inline-flex/s);
    assert.match(css, /\.mytasks-calendar-agenda__pager\[hidden\] \{[^}]*display: none/s);
    assert.match(css, /\.mytasks-calendar-agenda__pager button:disabled \{[^}]*cursor: not-allowed/s);
    assert.match(css, /\.mytasks-calendar-agenda__pager button:focus-visible/);
});

/*
 * แถบเครื่องมือของปฏิทิน: ปุ่มควบคุมทุกกลุ่มอยู่แถวเดียวกัน
 *
 * คำอธิบายสีเป็นข้อมูลอ้างอิง จึงกินแถวบนของตัวเอง
 * ส่วนตัวเลือกการแสดงผลกับปุ่มเปลี่ยนเดือนเป็น "สิ่งที่กดได้" เหมือนกัน จึงต้องอยู่ระดับเดียวกัน
 */
test('display options and month navigation share one toolbar row', async () => {
    const css = await read('resources/css/components/task-workspace/calendar/base.css');
    const desktop = css.slice(0, css.lastIndexOf('@media (max-width: 760px)'));

    const rowOf = (child) => {
        const rule = desktop.match(new RegExp(`__toolbar > \\.mytasks-calendar__${child} \\{([^}]*)\\}`, 's'))?.[1] ?? '';

        return {
            row: rule.match(/grid-row:\s*([^;]+);/)?.[1]?.trim(),
            column: rule.match(/grid-column:\s*([^;]+);/)?.[1]?.trim(),
        };
    };

    assert.equal(rowOf('legend').row, '1', 'คำอธิบายสีอยู่แถวบนของตัวเอง');
    assert.equal(rowOf('legend').column, '1 / -1');
    assert.equal(rowOf('displaybar').row, '2');
    assert.equal(rowOf('controls').row, '2', 'ปุ่มเปลี่ยนเดือนต้องอยู่แถวเดียวกับตัวเลือกการแสดงผล');
    assert.equal(rowOf('displaybar').column, '1');
    assert.equal(rowOf('controls').column, '2');

    // กฎของจอแคบต้องอยู่หลังกฎเดสก์ท็อป มิฉะนั้นเดสก์ท็อปจะทับเลย์เอาต์มือถือทุกความกว้าง
    assert.ok(
        css.lastIndexOf('@media (max-width: 760px)') > css.indexOf('__toolbar > .mytasks-calendar__legend'),
        'กฎมือถือต้องประกาศหลังกฎเดสก์ท็อป เพราะ media query ไม่เพิ่ม specificity',
    );
});

/*
 * คอลัมน์ของมุมมองตารางที่กางรายการเพิ่ม
 *
 * overflow-y: auto ทำให้ overflow-x กลายเป็น auto ตามไปด้วยถ้าไม่ระบุ
 * การ์ดที่กว้างเกินคอลัมน์จึงสร้างแถบเลื่อนแนวนอนที่กินความสูงและตัดการ์ดใบล่างขาดครึ่ง
 */
test('an expanded kanban column clips only the axis it means to scroll', async () => {
    const css = await read('resources/css/components/task-workspace/kanban.css');
    const script = await read('resources/js/pages/mytasks/kanban-card-limit.js');

    const expanded = css.match(/\.mytasks-kanban__cards\.is-expanded \{([^}]*)\}/s)?.[1] ?? '';

    assert.match(expanded, /overflow: hidden auto/, 'ต้องระบุทั้งสองแกน ไม่ปล่อยให้ overflow-x ถูกอนุมาน');
    assert.match(expanded, /overscroll-behavior: contain/, 'เลื่อนสุดคอลัมน์แล้วต้องไม่ลามไปเลื่อนทั้งหน้า');
    assert.match(expanded, /padding-bottom:\s*\d+px/, 'ต้องเว้นที่ให้เงาของการ์ดใบล่างสุด');

    // การ์ดต้องยุบตามคอลัมน์ได้ ไม่เช่นนั้นชื่องานยาว ๆ จะดันความกว้างจนล้น
    assert.match(css, /\.mytasks-kanban__cards > \* \{[^}]*min-width: 0/s);
    assert.match(css, /\.mytasks-kanban__card-head strong,[\s\S]{0,200}?overflow-wrap: anywhere/);

    // สไตล์ทั้งชุดอยู่ในไฟล์ CSS จริง ไม่ใช่ <style> ที่ JS ฉีดใส่ head เอง
    assert.doesNotMatch(script, /document\.head\.append/);
    assert.doesNotMatch(script, /kanbanCardLimitStyle/);
    assert.match(css, /\.mytasks-kanban__more \{/);
});
