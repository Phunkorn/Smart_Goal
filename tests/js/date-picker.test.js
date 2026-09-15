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
                <input type="datetime-local" data-date-picker data-default-time="00:00" data-board-field="start" value="2026-09-01T09:00" max="2026-09-10T23:59" aria-label="เลือกวันที่และเวลาเริ่ม">
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
    const input = label.querySelector('input[type="datetime-local"]');

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

    // เลือกวันใหม่ต้องไม่ล้างเวลาที่ตั้งไว้ ช่องจึงยังถือ 09:00 เดิมอยู่
    assert.equal(input.value, '2026-09-04T09:00');
    assert.deepEqual(changes, ['2026-09-04T09:00'], 'ตัวจัดการบันทึกเดิมต้องได้ change เพียงครั้งเดียว');
    assert.equal(popover.hidden, true, 'เลือกแล้วต้องปิดเอง');
});

test('multiple mode keeps the picker open and submits every selected date', async (t) => {
    const ui = mountDom(`<!doctype html><html><body>
        <div data-date-picker-field>
            <label>
                <input type="date" name="work_date" value="2026-09-01" max="2026-09-10"
                    data-date-picker data-date-picker-multiple data-date-picker-multiple-name="work_dates[]">
            </label>
            <small data-date-picker-summary></small>
        </div>
    </body></html>`);
    t.after(() => ui.cleanup());
    const picker = await import(`../../resources/js/components/date-picker.js?multiple=${Math.random()}`);
    picker.useDatePickers();

    const input = ui.document.querySelector('[data-date-picker-multiple]');
    click(input);
    const popover = ui.document.querySelector('.sg-date-picker');
    click(popover.querySelector('[data-date-value="2026-09-04"]'));

    assert.equal(popover.hidden, false, 'เลือกวันเพิ่มแล้วต้องยังเปิดอยู่');
    assert.equal(popover.querySelectorAll('.sg-date-picker__day.is-selected').length, 2);
    click(popover.querySelector('.sg-date-picker__confirm'));

    const dates = [...ui.document.querySelectorAll('[data-date-picker-generated]')].map((node) => node.value);
    assert.deepEqual(dates, ['2026-09-01', '2026-09-04']);
    assert.equal(ui.document.querySelector('[data-date-picker-summary]').textContent, 'เลือกแล้ว 2 วัน');
    assert.equal(popover.hidden, true);
});

/*
 * ของเดิมวันที่เลือกเพิ่มถูกเขียนลงฟอร์มเฉพาะตอนกด "ใช้ N วัน"
 * คลิกนอกกล่องแล้วทุกวันที่เลือกหายไป เหลือวันแรกวันเดียว ผู้ใช้จึงเห็นว่าเลือกได้ทีละวัน
 */
test('multiple mode keeps every selected date even when closed by an outside click', async (t) => {
    const ui = mountDom(`<!doctype html><html><body>
        <div data-date-picker-field>
            <input type="date" name="work_date" value="2026-09-01" max="2026-09-10"
                data-date-picker data-date-picker-multiple data-date-picker-multiple-name="work_dates[]">
            <small data-date-picker-summary></small>
            <small data-date-picker-dates hidden></small>
        </div>
    </body></html>`);
    t.after(() => ui.cleanup());
    const picker = await import(`../../resources/js/components/date-picker.js?outside=${Math.random()}`);
    picker.useDatePickers();

    click(ui.document.querySelector('[data-date-picker-multiple]'));
    const popover = ui.document.querySelector('.sg-date-picker');
    click(popover.querySelector('[data-date-value="2026-09-03"]'));
    click(popover.querySelector('[data-date-value="2026-09-05"]'));
    ui.document.body.dispatchEvent(new ui.window.MouseEvent('pointerdown', {bubbles: true, cancelable: true}));

    assert.equal(popover.hidden, true);
    const dates = [...ui.document.querySelectorAll('[data-date-picker-generated]')].map((node) => node.value);
    assert.deepEqual(dates, ['2026-09-01', '2026-09-03', '2026-09-05']);
    const list = ui.document.querySelector('[data-date-picker-dates]');
    assert.equal(list.hidden, false);
    assert.equal(list.textContent, '1 ก.ย., 3 ก.ย., 5 ก.ย.');
});

test('multiple mode never lets the last selected date be removed', async (t) => {
    const ui = mountDom(`<!doctype html><html><body>
        <div data-date-picker-field>
            <input type="date" name="work_date" value="2026-09-01" max="2026-09-10"
                data-date-picker data-date-picker-multiple data-date-picker-multiple-name="work_dates[]">
        </div>
    </body></html>`);
    t.after(() => ui.cleanup());
    const picker = await import(`../../resources/js/components/date-picker.js?last=${Math.random()}`);
    picker.useDatePickers();

    click(ui.document.querySelector('[data-date-picker-multiple]'));
    const popover = ui.document.querySelector('.sg-date-picker');
    click(popover.querySelector('[data-date-value="2026-09-01"]'));

    assert.equal(popover.querySelectorAll('.sg-date-picker__day.is-selected').length, 1);
});

test('a day outside min/max cannot be committed even if it is clicked', async (t) => {
    const {ui} = await boot(t);
    const label = ui.document.querySelector('.board-start-editable');
    const input = label.querySelector('input[type="datetime-local"]');

    click(label);
    const popover = ui.document.querySelector('.sg-date-picker');
    click(popover.querySelector('[data-date-value="2026-09-11"]'));

    assert.equal(input.value, '2026-09-01T09:00', 'วันเกินขอบเขตต้องไม่ถูกเขียนลง input');
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
    // กำหนดการของงานมีเวลาจริงแล้ว ช่องทุกช่องจึงต้องเป็น datetime-local
    // และต้องประกาศเวลาตั้งต้นของตัวเอง (ต้นวันทำการ / เวลาเลิกงาน) ไม่ใช่ตกไปใช้ค่าของหน้าประชุม
    assert.match(await read('resources/views/tasks/partials/notion-task-row.blade.php'), /type="datetime-local" data-date-picker data-default-time="[^"]+" data-field="due"/);
    assert.match(board, /<input type="datetime-local" data-date-picker data-default-time="[^"]+" data-board-field="start"/);
    assert.match(board, /<input type="datetime-local" data-date-picker data-default-time="[^"]+" data-board-field="due"/);
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

test('a project schedule field offers the time row too', async (t) => {
    const {ui} = await boot(t);

    click(ui.document.querySelector('.board-start-editable'));

    // งานในโปรเจกต์กำหนดเวลาส่งได้แล้ว แถวเวลาจึงต้องมาพร้อมกับวันในกล่องเดียวกัน
    assert.equal(ui.document.querySelector('.sg-date-picker__time').hidden, false);
    assert.equal(ui.document.querySelector('.sg-date-picker__time-input').value, '09:00');
});

test('an empty schedule field starts at the time the field declares', async () => {
    const {initialTimeFor, defaultMeetingTime} = await import('../../resources/js/components/date-picker.js');

    // ช่องกำหนดส่งประกาศเวลาเลิกงานของตัวเอง จึงต้องไม่ตกไปใช้ "ชั่วโมงถัดไป" ของหน้าประชุม
    assert.equal(initialTimeFor({dataset: {defaultTime: '17:00'}}), '17:00');
    assert.equal(initialTimeFor({dataset: {defaultTime: 'ไม่ใช่เวลา'}}, new Date(2026, 8, 4, 9, 42)), '10:00');
    assert.equal(initialTimeFor({dataset: {}}, new Date(2026, 8, 4, 9, 42)), defaultMeetingTime(new Date(2026, 8, 4, 9, 42)));
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

test('the picker floats above every overlay that can open it', async () => {
    const css = await read('resources/css/components/date-picker.css');
    const workspace = await read('resources/css/components/task-workspace/workspace-modal.css');

    /*
     * ปฏิทินเป็นของกล่องที่เปิดมันเสมอ ถ้ามันอยู่ใต้กล่องนั้น ผู้ใช้กดช่องวันที่แล้วจะเหมือน
     * ไม่มีอะไรเกิดขึ้น เพราะปฏิทินถูกทับไว้ทั้งใบ — เคยเกิดกับ Task Workspace
     */
    const pickerLayers = [...css.matchAll(/\.sg-date-picker\s*\{[^}]*z-index:\s*(\d+)/gs)].map(([, value]) => Number(value));
    assert.ok(pickerLayers.length > 0, 'ปฏิทินต้องประกาศ z-index ของตัวเอง');

    const overlayLayers = [...workspace.matchAll(/z-index:\s*(\d+)/g)].map(([, value]) => Number(value));
    const highestOverlay = Math.max(...overlayLayers);

    for (const layer of pickerLayers) {
        assert.ok(layer > highestOverlay, `ปฏิทินอยู่ที่ ${layer} ซึ่งต่ำกว่า overlay สูงสุด ${highestOverlay}`);
    }
});

test('Escape closes only the picker, never the dialog that opened it', async () => {
    const source = await read('resources/js/components/date-picker.js');
    const stack = await read('resources/js/components/modal-stack.js');

    // modalStack ดัก Escape ที่ document ใน capture phase เพื่อปิดโมดัลชั้นบนสุด
    assert.match(stack, /doc\.addEventListener\('keydown'[\s\S]*?\}, true\)/);

    // ปฏิทินจึงต้องฟังที่ window ซึ่งมาก่อน document ในเส้นทาง capture เสมอ
    // ฟังที่ document จะแพ้ให้ modalStack เพราะมันผูก listener ไว้ก่อนตั้งแต่โหลดหน้า
    assert.match(source, /window\.addEventListener\('keydown',[\s\S]*?event\.stopPropagation\(\)[\s\S]*?\}, true\)/);
});

test('the board due-time cell is editable and bound to the same due field', async () => {
    const board = await read('resources/views/tasks/partials/project-board-card.blade.php');
    const subtask = await read('resources/views/tasks/components/task-detail-row.blade.php');
    const script = await read('resources/js/mytasks-project-board.js');
    const css = await read('resources/css/pages/mytasks/project-board.css');

    // ช่องวันที่และช่องเวลาเป็นมุมมองคนละด้านของ job_due_at ค่าเดียวกัน จึงใช้ field เดียวกัน
    for (const markup of [board, subtask]) {
        assert.match(markup, /class="board-due-time board-due-time-editable[^"]*"[\s\S]{0,600}?data-board-field="due"/);
    }

    // ทั้งสองช่องต้องถูกเขียนค่าใหม่พร้อมกัน ไม่งั้นช่องที่ไม่ถูกอัปเดตจะส่งค่าเก่ากลับไปทับ
    assert.match(script, /ownControls\(task, `\[data-board-field="\$\{field\}"\]`\)\.forEach/);

    /*
     * แถวบอร์ดใช้ align-items:start ความสูงของกล่องแต่ละช่องจึงเป็นตัวกำหนดระดับข้อความ
     * ช่องกำหนดส่งกับช่องเวลาต้องประกาศความสูงจากกฎ "เดียวกัน" ไม่ใช่คัดลอกตัวเลขไปคนละที่
     * ซึ่งจะเพี้ยนทันทีที่มีคนแก้ค่าฝั่งเดียว
     */
    assert.match(css, /\.board-due-editable,[^{]*\.board-due-time-editable\{[^}]*min-height:\d+px/);
    assert.match(css, /align-items: start/, 'กฎที่ทำให้ความสูงกล่องมีผลต้องยังอยู่จริง');

    // และห้ามมี min-height ที่คลาสฐาน ไม่งั้นแถวแบบอ่านอย่างเดียวจะมีช่องเวลาสูงกว่าเพื่อน
    // เพราะ .board-due ของแถวนั้นไม่มี min-height ของตัวเอง
    assert.doesNotMatch(css, /\.board-due-time\{[^}]*min-height/);
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
        // Blade แทรกนิพจน์ไว้ก่อน data-board-field จึงหาแอตทริบิวต์นั้นก่อนแล้วย้อนกลับไปหาต้นแท็ก
        const field_at = blade.indexOf(`data-board-field="${field}"`);
        const start = field_at === -1 ? -1 : blade.lastIndexOf('<input', field_at);
        assert.notEqual(start, -1, `ไม่พบช่อง ${field} ในบอร์ด`);
        assert.match(blade.slice(start, field_at), /type="datetime-local"/, `ช่อง ${field} ต้องเลือกเวลาได้ด้วย`);

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
