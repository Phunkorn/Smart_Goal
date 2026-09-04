import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom, click, pressKey} from './helpers/dom.js';

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

/*
 * ตัวเลือกวันที่ที่ระบบวาดเอง แทนปฏิทินของเบราว์เซอร์ที่เรียกผ่าน input.showPicker()
 *
 * เหตุที่ต้องเปลี่ยน: ปฏิทินของเบราว์เซอร์ใช้ ค.ศ. ตามเครื่องผู้ใช้ ไม่ใช่ พ.ศ. แบบที่ทั้งระบบใช้
 * วางตำแหน่งเองโดยไม่อิงกับปุ่มที่กด และ showPicker() ก็ไม่มีในทุกเบราว์เซอร์
 *
 * หัวใจของการเปลี่ยนคือ <input type="date"> เดิมยังเป็นแหล่งข้อมูลจริงและกติกา min/max
 * ตัวเลือกนี้เพียงเขียนค่าลงไปแล้ว dispatch 'change' เส้นทางบันทึกเดิมจึงไม่ถูกแตะเลย
 */

const boardRowMarkup = () => `
    <div class="my-tasks-page">
        <article class="board-reference-row" data-board-task data-task-id="7">
            <label class="board-start board-start-editable">
                <i class="bi bi-calendar-plus"></i>
                <span data-board-start-label>1 กันยายน 2569</span>
                <input type="date" data-date-picker data-board-field="start" value="2026-09-01" max="2026-09-10" aria-label="เลือกวันที่เริ่ม">
            </label>
        </article>
    </div>
`;

const boot = async (t) => {
    const ui = mountDom(`<!doctype html><html><body>${boardRowMarkup()}</body></html>`);
    t.after(() => ui.cleanup());

    // โมดูลเก็บสถานะ popover ไว้ในตัวเอง จึงต้องโหลดใหม่ทุกเทสต์ให้ผูกกับ document ของเทสต์นั้น
    const picker = await import(`../../resources/js/components/date-picker.js?case=${Math.random()}`);
    picker.useDatePickers();

    return {ui, picker};
};

test('the month grid always covers six weeks and starts on Monday', async () => {
    const {monthGrid, toDateValue} = await import('../../resources/js/components/date-picker.js');

    // กันยายน 2026 ขึ้นต้นวันอังคาร ช่องแรกจึงต้องเป็นจันทร์ที่ 31 สิงหาคม
    const grid = monthGrid(2026, 8);

    assert.equal(grid.length, 42, 'ตารางหกสัปดาห์ทำให้ความสูงของ popover คงที่ทุกเดือน');
    assert.equal(grid[0].value, '2026-08-31');
    assert.equal(grid[0].isCurrentMonth, false);
    assert.equal(grid[1].value, '2026-09-01');
    assert.equal(grid[1].isCurrentMonth, true);
    assert.equal(grid.filter((cell) => cell.isCurrentMonth).length, 30);

    // ต้องไม่ผ่าน toISOString() ไม่เช่นนั้นเขตเวลาไทยจะดันวันที่ถอยไปหนึ่งวัน
    assert.equal(toDateValue(new Date(2026, 8, 1)), '2026-09-01');
});

test('min and max on the input are the only rule for which days can be picked', async () => {
    const {isSelectable, parseDateValue} = await import('../../resources/js/components/date-picker.js');

    assert.equal(isSelectable('2026-09-05', {min: '2026-09-01', max: '2026-09-10'}), true);
    assert.equal(isSelectable('2026-08-31', {min: '2026-09-01'}), false);
    assert.equal(isSelectable('2026-09-11', {max: '2026-09-10'}), false);
    assert.equal(isSelectable('2026-09-05', {}), true, 'ไม่มีขอบเขตแปลว่าเลือกได้ทุกวัน');

    assert.equal(parseDateValue('2026-02-30'), null, 'วันที่ไม่มีจริงต้องไม่ถูกรับ');
    assert.equal(parseDateValue(''), null);
    assert.equal(parseDateValue('2026-09-04').getDate(), 4);
});

test('picking a day writes the input and fires one bubbling change event', async (t) => {
    const {ui} = await boot(t);
    const label = ui.document.querySelector('.board-start-editable');
    const input = label.querySelector('input[type="date"]');

    const changes = [];
    ui.document.addEventListener('change', (event) => changes.push(event.target.value));

    click(label.querySelector('[data-board-start-label]'));

    const popover = ui.document.querySelector('.sg-date-picker');
    assert.ok(popover, 'กดที่ป้ายวันที่แล้วต้องเปิดตัวเลือกของระบบ');
    assert.equal(popover.hidden, false);

    // วันที่ถืออยู่ต้องถูกทำเครื่องหมายไว้ และวันหลัง max ต้องกดไม่ได้
    assert.equal(popover.querySelector('.sg-date-picker__day.is-selected').dataset.dateValue, '2026-09-01');
    assert.equal(popover.querySelector('[data-date-value="2026-09-11"]').disabled, true);
    assert.equal(popover.querySelector('[data-date-value="2026-09-04"]').disabled, false);

    click(popover.querySelector('[data-date-value="2026-09-04"]'));

    assert.equal(input.value, '2026-09-04');
    assert.deepEqual(changes, ['2026-09-04'], 'ตัวจัดการบันทึกเดิมต้องได้ change เพียงครั้งเดียว');
    assert.equal(popover.hidden, true, 'เลือกแล้วต้องปิดเอง');
});

test('a day outside min/max cannot be committed even if it is clicked', async (t) => {
    const {ui} = await boot(t);
    const label = ui.document.querySelector('.board-start-editable');
    const input = label.querySelector('input[type="date"]');

    click(label);
    const popover = ui.document.querySelector('.sg-date-picker');
    click(popover.querySelector('[data-date-value="2026-09-11"]'));

    assert.equal(input.value, '2026-09-01', 'วันเกินขอบเขตต้องไม่ถูกเขียนลง input');
    assert.equal(popover.hidden, false, 'และ popover ต้องยังเปิดอยู่ให้เลือกใหม่');
});

test('the popover closes on Escape and on an outside click, and never stacks listeners', async (t) => {
    const {ui} = await boot(t);
    const label = ui.document.querySelector('.board-start-editable');

    click(label);
    const popover = ui.document.querySelector('.sg-date-picker');
    pressKey(ui.document, 'Escape');
    assert.equal(popover.hidden, true);

    click(label);
    assert.equal(popover.hidden, false);
    ui.document.body.dispatchEvent(new ui.window.MouseEvent('pointerdown', {bubbles: true, cancelable: true}));
    assert.equal(popover.hidden, true);

    // เปิดซ้ำสิบครั้งต้องมี popover เดียวในหน้า ไม่ใช่สิบตัวซ้อนกัน
    for (let round = 0; round < 10; round += 1) {
        click(label);
        pressKey(ui.document, 'Escape');
    }
    assert.equal(ui.document.querySelectorAll('.sg-date-picker').length, 1);
});

test('changing the month keeps the picker open and repaints the grid', async (t) => {
    const {ui} = await boot(t);

    click(ui.document.querySelector('.board-start-editable'));
    const popover = ui.document.querySelector('.sg-date-picker');
    const monthSelect = popover.querySelector('.sg-date-picker__month');

    assert.equal(monthSelect.value, '8', 'เปิดมาที่เดือนของค่าที่ถืออยู่');
    assert.equal(popover.querySelector('.sg-date-picker__year').value, '2026');

    monthSelect.value = '9';
    monthSelect.dispatchEvent(new ui.window.Event('change', {bubbles: true}));

    assert.equal(popover.hidden, false);
    assert.ok(popover.querySelector('[data-date-value="2026-10-01"]'), 'ตารางต้องวาดเดือนใหม่');
    assert.equal(popover.querySelector('[data-date-value="2026-09-15"]'), null);
});

test('the board no longer calls the browser picker, and the date fields opt in from Blade', async () => {
    const script = await read('resources/js/mytasks-project-board.js');
    const board = await read('resources/views/tasks/partials/project-board-card.blade.php');
    const entry = await read('resources/js/pages/mytasks/index.js');

    assert.doesNotMatch(script, /showPicker/, 'ปฏิทินของเบราว์เซอร์ต้องไม่ถูกเรียกอีก');
    assert.doesNotMatch(await read('resources/js/mytasks-management.js'), /showPicker/, 'แถวงานในตารางก็ต้องใช้ปฏิทินชุดเดียวกัน');
    assert.match(await read('resources/views/tasks/partials/notion-task-row.blade.php'), /type="date" data-date-picker data-field="due"/);
    assert.match(board, /<input type="date" data-date-picker data-board-field="start"/);
    assert.match(board, /<input type="date" data-date-picker data-board-field="due"/);
    assert.match(entry, /useDatePickers\(\)/, 'หน้างานของฉันและ Member Workspace ใช้ entry เดียวกัน');
});

/*
 * หน้าประชุมใช้ปฏิทินตัวเดียวกับหน้างาน ต่างกันแค่ประชุมต้องเลือกเวลาด้วย
 * ส่วนงานในโปรเจกต์เป็นความละเอียดระดับวัน แถวเวลาจึงต้องไม่โผล่มา
 */

const meetingMarkup = () => `
    <div class="meeting-form-modal">
        <label>
            <span>เริ่มประชุม</span>
            <input class="form-control" type="datetime-local" data-date-picker name="starts_at" value="2026-09-04T13:30">
        </label>
    </div>
`;

const bootMeeting = async (t) => {
    const ui = mountDom(`<!doctype html><html><body>${meetingMarkup()}</body></html>`);
    t.after(() => ui.cleanup());

    const picker = await import(`../../resources/js/components/date-picker.js?meeting=${Math.random()}`);
    picker.useDatePickers();

    return ui;
};

test('a datetime field keeps its time when a new day is picked', async (t) => {
    const ui = await bootMeeting(t);
    const input = ui.document.querySelector('input[name="starts_at"]');

    click(input);
    const popover = ui.document.querySelector('.sg-date-picker');

    assert.equal(popover.querySelector('.sg-date-picker__time').hidden, false, 'ประชุมต้องมีแถวเลือกเวลา');
    assert.equal(popover.querySelector('.sg-date-picker__time-input').value, '13:30');
    assert.equal(popover.querySelector('.sg-date-picker__day.is-selected').dataset.dateValue, '2026-09-04');

    click(popover.querySelector('[data-date-value="2026-09-09"]'));

    // เลือกวันใหม่ต้องไม่ล้างเวลาที่ตั้งไว้
    assert.equal(input.value, '2026-09-09T13:30');
});

test('changing the time saves immediately and keeps the picker open', async (t) => {
    const ui = await bootMeeting(t);
    const input = ui.document.querySelector('input[name="starts_at"]');

    const changes = [];
    ui.document.addEventListener('change', (event) => changes.push(event.target.value));

    click(input);
    const popover = ui.document.querySelector('.sg-date-picker');
    const time = popover.querySelector('.sg-date-picker__time-input');

    time.value = '09:15';
    time.dispatchEvent(new ui.window.Event('input', {bubbles: true}));

    assert.equal(input.value, '2026-09-04T09:15');
    assert.deepEqual(changes, ['2026-09-04T09:15']);
    assert.equal(popover.hidden, false, 'ปรับเวลาแล้วต้องยังเลือกวันต่อได้');
});

test('a project date field has no time row at all', async (t) => {
    const {ui} = await boot(t);

    click(ui.document.querySelector('.board-start-editable'));

    assert.equal(ui.document.querySelector('.sg-date-picker__time').hidden, true);
});

test('splitting and rejoining a datetime value is lossless', async () => {
    const {splitDateTimeValue, joinDateTimeValue, defaultMeetingTime} = await import('../../resources/js/components/date-picker.js');

    assert.deepEqual(splitDateTimeValue('2026-09-04T13:30'), {date: '2026-09-04', time: '13:30'});
    assert.deepEqual(splitDateTimeValue('2026-09-04'), {date: '2026-09-04', time: ''});
    assert.deepEqual(splitDateTimeValue(''), {date: '', time: ''});
    assert.equal(joinDateTimeValue('2026-09-04', '13:30'), '2026-09-04T13:30');
    assert.equal(joinDateTimeValue('2026-09-04', ''), '2026-09-04', 'ไม่มีเวลาก็ต้องไม่ต่อ T ห้อยไว้');

    // ช่องที่ยังว่างเริ่มที่ชั่วโมงถัดไปแบบเต็มชั่วโมง
    assert.equal(defaultMeetingTime(new Date(2026, 8, 4, 9, 42)), '10:00');
});

test('the meeting form and the meetings page both opt into the shared picker', async () => {
    const form = await read('resources/views/meetings/components/form-modal.blade.php');
    const page = await read('resources/js/pages/meetings/index.js');

    assert.match(form, /type="datetime-local" data-date-picker id="\{\{ \$modalId \}\}Start"/);
    assert.match(form, /type="datetime-local" data-date-picker id="\{\{ \$modalId \}\}End"/);
    assert.match(page, /useDatePickers\(\)/);
    assert.match(await read('resources/css/pages/meetings.css'), /components\/date-picker\.css/);
});

test('the board no longer caps one endpoint of the range with the other', async () => {
    const blade = await read('resources/views/tasks/partials/project-board-card.blade.php');
    const script = await read('resources/js/mytasks-project-board.js');

    /*
     * ของเดิม max ของวันเริ่มคือกำหนดส่ง และ min ของกำหนดส่งคือวันเริ่ม
     * งานที่เริ่มและครบกำหนดวันเดียวกัน (ค่าเริ่มต้นของงานใหม่) จึงเลื่อนวันเริ่มไปข้างหน้าไม่ได้เลย
     */
    // Blade แทรก {{ $task->... }} ไว้กลางแท็ก จึงต้องตัดเป็นแท็กเต็มก่อนแล้วค่อยตรวจแอตทริบิวต์
    const inputTag = (field) => {
        const start = blade.indexOf(`<input type="date" data-date-picker data-board-field="${field}"`);
        assert.notEqual(start, -1, `ไม่พบช่อง ${field} ในบอร์ด`);

        return blade.slice(start, blade.indexOf('>', blade.indexOf('aria-label', start)) + 1);
    };

    const startTag = inputTag('start');
    const dueTag = inputTag('due');

    assert.doesNotMatch(startTag, /\smax=/, 'วันเริ่มต้องไม่ถูกครอบด้วยกำหนดส่ง');
    assert.doesNotMatch(dueTag, /\smin=/, 'กำหนดส่งต้องไม่ถูกครอบด้วยวันเริ่ม');
    assert.match(startTag, /data-range-partner="due"/);
    assert.match(dueTag, /data-range-partner="start"/);

    // เลือกวันข้ามปลายทางอีกข้างได้ โดยลากอีกข้างตามไปแทนการปฏิเสธการแก้ไข
    assert.doesNotMatch(script, /วันที่เริ่มต้องไม่เกินกำหนดส่ง/);
    assert.doesNotMatch(script, /กำหนดส่งต้องไม่น้อยกว่าวันที่เริ่ม/);
    assert.match(script, /const shiftedDue = /);
    assert.match(script, /const shiftedStart = /);
    // และต้องไม่ตั้ง min/max กลับเข้าไปหลังบันทึก ไม่เช่นนั้นการล็อกจะกลับมาทันทีที่แก้ครั้งแรก
    assert.doesNotMatch(script, /\.min = task\.dataset/);
    assert.doesNotMatch(script, /\.max = task\.dataset/);
});

test('inside a Bootstrap modal the popover is appended to the modal, not to body', async (t) => {
    const ui = mountDom(`<!doctype html><html><body>
        <div class="modal fade show" id="createMeetingModal">
            <input type="datetime-local" data-date-picker name="starts_at" value="2026-09-04T13:30">
        </div>
    </body></html>`);
    t.after(() => ui.cleanup());

    const picker = await import(`../../resources/js/components/date-picker.js?modal=${Math.random()}`);
    picker.useDatePickers();

    click(ui.document.querySelector('input[name="starts_at"]'));

    /*
     * Bootstrap ดักโฟกัสไว้ในโมดัล popover ที่อยู่นอกโมดัลจะถูกดึงโฟกัสกลับทันที
     * จนกดเลือกวันด้วยคีย์บอร์ดไม่ได้ จึงต้องอยู่ในโมดัลเดียวกัน
     */
    const popover = ui.document.querySelector('.sg-date-picker');
    assert.equal(popover.closest('.modal')?.id, 'createMeetingModal');
});
