import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/*
 * ปุ่มควบคุมของปฏิทินอยู่ในแถบเครื่องมือแถบเดียว
 *
 * แถวหัวเรื่องบอกว่า "กำลังดูอะไรอยู่" (กันยายน 2569 และคำอธิบายการแสดงผล)
 * ส่วนแถบเครื่องมือคือที่ของ "สิ่งที่กดเพื่อเปลี่ยนสิ่งที่ดู" ทั้งหมด
 * คำอธิบายสี → ตัวเลือกการแสดงผล → ปุ่มเปลี่ยนเดือน
 * ก่อนหน้านี้ตัวเลือกการแสดงผลไปอยู่ในแถวหัวเรื่อง ปุ่มควบคุมจึงกระจายอยู่สองแถว
 */

test('display controls live in the toolbar with every other calendar control', async () => {
    const blade = await read('resources/views/tasks/partials/calendar.blade.php');

    const toolbarStart = blade.indexOf('<header class="mytasks-calendar__toolbar">');
    const toolbarEnd = blade.indexOf('</header>');
    const toolbar = blade.slice(toolbarStart, toolbarEnd);

    const headingStart = blade.indexOf('<div class="mytasks-calendar__heading">');
    const viewportStart = blade.indexOf('<div class="mytasks-calendar__viewport">');
    const heading = blade.slice(headingStart, viewportStart);

    assert.ok(toolbarStart > 0 && toolbarEnd > toolbarStart);
    assert.ok(headingStart > toolbarEnd && viewportStart > headingStart);

    assert.match(toolbar, /mytasks-calendar__displaybar/, 'ตัวเลือกการแสดงต้องอยู่ในแถบเครื่องมือ');
    assert.match(heading, /data-calendar-display-note/, 'คำอธิบายต้องเป็นคำบรรยายใต้ชื่อเดือน');

    // ต้องมี displaybar ชุดเดียวในหน้า และต้องไม่เหลือชุดเก่าค้างในแถวหัวเรื่อง
    assert.equal(blade.match(/mytasks-calendar__displaybar/g).length, 1);
    assert.ok(heading.indexOf('mytasks-calendar__displaybar') === -1);

    // ลำดับการอ่านในแถบเครื่องมือ: คำอธิบายสี → ตัวเลือกการแสดงผล → ปุ่มเปลี่ยนเดือน
    assert.ok(
        toolbar.indexOf('mytasks-calendar__legend')
            < toolbar.indexOf('mytasks-calendar__displaybar')
            && toolbar.indexOf('mytasks-calendar__displaybar') < toolbar.indexOf('mytasks-calendar__controls'),
        'ลำดับของแถบเครื่องมือต้องเป็น คำอธิบายสี → ตัวเลือกการแสดงผล → ปุ่มเปลี่ยนเดือน',
    );
});

test('the two control groups look different because they behave differently', async () => {
    const blade = await read('resources/views/tasks/partials/calendar.blade.php');
    const css = await read('resources/css/components/task-workspace/calendar/timeline.css');
    const script = await read('resources/js/pages/mytasks/calendar.js');

    // "รูปแบบ" เลือกได้อย่างเดียว ส่วน "วันที่งาน" เปิดพร้อมกันได้ทั้งคู่
    assert.match(blade, /mytasks-calendar__segmented--single[^>]*aria-label="เลือกรูปแบบปฏิทิน"/);
    assert.match(blade, /mytasks-calendar__segmented--multi[^>]*aria-label="เลือกวันที่ของงานที่ต้องการแสดง"/);
    assert.match(script, /toggleCalendarDatePoint\(datePoints, point\)/, 'กลุ่มวันที่ยังต้องเป็นสวิตช์จริง');

    // สถานะที่เลือกไว้ของสองกลุ่มต้องไม่ใช่สไตล์เดียวกัน
    const single = css.match(/--single button\.is-active\s*\{([^}]*)\}/)?.[1] ?? '';
    const multi = css.match(/--multi button\.is-active\s*\{([^}]*)\}/)?.[1] ?? '';

    assert.ok(single.includes('linear-gradient'), 'ตัวเลือกเดียวควรทึบเต็มสี');
    assert.ok(multi.includes('#fff'), 'สวิตช์ควรใช้พื้นอ่อน');
    assert.notEqual(single.trim(), multi.trim());
    assert.match(css, /--multi button\.is-active::after/, 'สวิตช์ที่เปิดอยู่ต้องมีจุดยืนยัน');
});

test('the buttons are large enough to read and tap', async () => {
    const css = await read('resources/css/components/task-workspace/calendar/timeline.css');

    const button = css.match(/\.mytasks-calendar__segmented button \{([^}]*)\}/)?.[1] ?? '';
    const height = Number(button.match(/min-height:\s*(\d+)px/)?.[1]);
    const size = Number(button.match(/font-size:\s*(\d+)px/)?.[1]);

    assert.ok(height >= 34, `ปุ่มสูง ${height}px ยังเล็กเกินไป`);
    assert.ok(size >= 12, `ตัวอักษร ${size}px ยังเล็กเกินไป`);
    assert.match(button, /transition:/, 'ต้องมี transition ให้รู้สึกว่ากดได้');
    assert.match(css, /@media \(prefers-reduced-motion: reduce\)/, 'ต้องมีทางออกให้ผู้ที่ปิดแอนิเมชัน');
});

test('narrow screens stack the heading above full-width controls', async () => {
    const css = await read('resources/css/components/task-workspace/calendar/timeline.css');
    const mobile = css.slice(css.indexOf('@media (max-width: 760px)'));

    assert.match(mobile, /\.mytasks-calendar__heading\s*\{[^}]*flex-direction:\s*column/s);
    assert.match(mobile, /\.mytasks-calendar__segmented button\s*\{[^}]*flex:\s*1/s);
});
