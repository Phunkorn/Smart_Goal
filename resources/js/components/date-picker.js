/**
 * ตัวเลือกวันที่ของ Task Workspace — popover ไม่ใช่ modal
 *
 * ของเดิมช่องวันที่เป็น <input type="date"> ขนาด 1×1px ที่ซ่อนอยู่ใต้ป้ายวันที่
 * แล้วอาศัย input.showPicker() เรียกปฏิทินของเบราว์เซอร์ ผลคือ
 *   - หน้าตาและภาษาเปลี่ยนไปตามเบราว์เซอร์และเครื่องของผู้ใช้ ไม่ใช่ พ.ศ. แบบที่ทั้งระบบใช้
 *   - showPicker() ไม่มีในทุกเบราว์เซอร์ บางเครื่องกดแล้วไม่มีอะไรเกิดขึ้นเลย
 *   - ปฏิทินของเบราว์เซอร์วางตำแหน่งเอง ไม่อิงกับปุ่มที่กด และเปลี่ยนเดือนได้ทีละเดือนเท่านั้น
 *
 * ที่นี่จึงวาดปฏิทินเอง โดยยังใช้ <input type="date"> เดิมเป็นแหล่งข้อมูลจริงเสมอ:
 * เลือกวันแล้วเขียนค่าลง input แล้ว dispatch 'change' แบบ bubbles ตัวจัดการเดิม
 * (mytasks-project-board.js) จึงบันทึกและอัปเดตหน้าจอด้วยเส้นทางเดียวกับที่เคยเป็นทุกประการ
 * min/max ของ input ก็ยังเป็นกติกาเดียวที่กำหนดว่าวันไหนเลือกได้ ไม่มีกติกาซ้อนในนี้
 *
 * เป็น popover ตามกฎของโปรเจกต์: ยึดตำแหน่งกับตัวเปิด ไม่ล็อกการเลื่อนหน้า ไม่บังทั้งจอ
 * ปิดเมื่อคลิกนอกกรอบหรือกด Escape แล้วคืนโฟกัสให้ตัวเปิดเสมอ
 * และมี popover เพียงตัวเดียวในหน้า การเปิดซ้ำจึงไม่ผูก listener เพิ่ม
 */

const THAI_MONTHS = [
    'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
];

const THAI_WEEKDAYS = ['จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส', 'อา'];

/** ปีที่เลือกได้: ย้อนหลังและล่วงหน้าอย่างละ 5 ปี เท่ากับตัวเลือกปีของปฏิทินรายเดือน */
const YEAR_RANGE = 5;

/** 'Y-m-d' ตามเวลาท้องถิ่น — ห้ามใช้ toISOString() เพราะมันแปลงเป็น UTC ก่อนแล้ววันจะเพี้ยน */
export const toDateValue = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
].join('-');

/** แปลง 'Y-m-d' เป็น Date เที่ยงคืนท้องถิ่น คืน null เมื่อรูปแบบหรือค่าไม่ถูกต้อง */
export const parseDateValue = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || '').trim());
    if (!match) return null;

    const [, year, month, day] = match.map(Number);
    const date = new Date(year, month - 1, day);

    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
        ? date
        : null;
};

/**
 * ช่องประเภท datetime-local เก็บค่าเป็น 'Y-m-dTH:i' — แยกส่วนวันกับส่วนเวลาออกจากกัน
 *
 * ปฏิทินทำงานกับส่วนวันอย่างเดียวเสมอ ส่วนเวลาถูกส่งต่อโดยไม่แตะ
 * เพื่อไม่ให้การเลือกวันใหม่ไปรีเซ็ตเวลาที่ผู้ใช้ตั้งไว้แล้ว
 */
export const splitDateTimeValue = (value) => {
    const [datePart = '', timePart = ''] = String(value || '').split('T');

    return {date: datePart, time: /^\d{2}:\d{2}/.test(timePart) ? timePart.slice(0, 5) : ''};
};

export const joinDateTimeValue = (date, time) => (date && time ? `${date}T${time}` : date);

/** เวลาเริ่มต้นเมื่อช่องยังว่าง — ชั่วโมงถัดไปแบบเต็มชั่วโมง ซึ่งเป็นเวลานัดประชุมที่พบบ่อยที่สุด */
export const defaultMeetingTime = (now = new Date()) => {
    const next = new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours() + 1, 0);

    return `${String(next.getHours()).padStart(2, '0')}:00`;
};

/** ป้ายวันที่แบบไทย พ.ศ. ให้ตรงกับที่ Blade แสดงในตารางและบอร์ด */
export const thaiDateLabel = (date) => `${date.getDate()} ${THAI_MONTHS[date.getMonth()]} ${date.getFullYear() + 543}`;

/**
 * ตารางหกสัปดาห์ของเดือนหนึ่ง ๆ เริ่มสัปดาห์ที่วันจันทร์ให้ตรงกับปฏิทินหน้า "งานของฉัน"
 *
 * @returns {Array<{date: Date, value: string, isCurrentMonth: boolean}>}
 */
export const monthGrid = (year, month) => {
    const first = new Date(year, month, 1);
    // getDay(): 0 = อาทิตย์ ปฏิทินนี้ขึ้นต้นด้วยวันจันทร์ อาทิตย์จึงเป็นช่องที่เจ็ด
    const leading = (first.getDay() + 6) % 7;
    const start = new Date(year, month, 1 - leading);

    return Array.from({length: 42}, (_, index) => {
        const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);

        return {date, value: toDateValue(date), isCurrentMonth: date.getMonth() === month};
    });
};

/** วันนั้นเลือกได้ไหม ตัดสินจาก min/max ของ input เท่านั้น ไม่มีกติกาซ้อนที่นี่ */
export const isSelectable = (value, {min = '', max = ''} = {}) => {
    if (min && value < min) return false;
    if (max && value > max) return false;

    return true;
};

const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
};

let popover = null;
let parts = null;
let state = null;

const close = ({restoreFocus = false} = {}) => {
    if (!popover || popover.hidden) return;

    const anchor = state?.anchor;
    popover.hidden = true;
    state = null;
    if (restoreFocus) anchor?.focus?.();
};

/** วางกล่องใต้ตัวเปิด แล้วพลิกขึ้นด้านบนหรือเลื่อนเข้าในจอเมื่อพื้นที่ไม่พอ */
const position = (anchor) => {
    const rect = anchor.getBoundingClientRect();
    const box = popover.getBoundingClientRect();
    const margin = 8;

    let top = rect.bottom + 6;
    if (top + box.height > window.innerHeight - margin) {
        top = Math.max(margin, rect.top - box.height - 6);
    }

    let left = rect.left;
    if (left + box.width > window.innerWidth - margin) {
        left = Math.max(margin, window.innerWidth - box.width - margin);
    }

    popover.style.top = `${Math.round(top)}px`;
    popover.style.left = `${Math.round(left)}px`;
};

const render = () => {
    if (!state) return;

    const {input, year, month, withTime} = state;
    // ช่องที่มีเวลาด้วยเก็บค่าเป็น 'Y-m-dTH:i' ปฏิทินจึงเทียบเฉพาะครึ่งหน้าที่เป็นวัน
    const selected = withTime ? splitDateTimeValue(input.value).date : (input.value || '');
    const today = toDateValue(new Date());
    const bounds = {
        min: withTime ? splitDateTimeValue(input.min).date : (input.min || ''),
        max: withTime ? splitDateTimeValue(input.max).date : (input.max || ''),
    };

    parts.monthSelect.value = String(month);
    parts.yearSelect.replaceChildren(...Array.from({length: YEAR_RANGE * 2 + 1}, (_, index) => {
        const value = year - YEAR_RANGE + index;
        const option = element('option', null, String(value + 543));
        option.value = String(value);

        return option;
    }));
    parts.yearSelect.value = String(year);

    parts.grid.replaceChildren(...monthGrid(year, month).map(({date, value, isCurrentMonth}) => {
        const day = element('button', 'sg-date-picker__day', String(date.getDate()));
        day.type = 'button';
        day.dataset.dateValue = value;
        day.classList.toggle('is-outside', !isCurrentMonth);
        day.classList.toggle('is-today', value === today);
        day.classList.toggle('is-selected', value === selected);
        day.disabled = !isSelectable(value, bounds);
        day.setAttribute('aria-label', thaiDateLabel(date));
        if (value === selected) day.setAttribute('aria-current', 'date');

        return day;
    }));

    parts.todayButton.disabled = !isSelectable(today, bounds);
    parts.timeRow.hidden = !withTime;
    if (withTime) parts.timeInput.value = state.time;
    position(state.anchor);
};

const move = (months) => {
    if (!state) return;

    const moved = new Date(state.year, state.month + months, 1);
    state.year = moved.getFullYear();
    state.month = moved.getMonth();
    render();
};

const commit = (dateValue) => {
    if (!state) return;

    const {input, withTime} = state;
    // เลือกวันใหม่ต้องไม่ทำให้เวลาที่ตั้งไว้หายไป จึงประกอบกลับด้วยเวลาที่ค้างอยู่ในกล่อง
    const value = withTime ? joinDateTimeValue(dateValue, state.time) : dateValue;

    if (input.value !== value) {
        input.value = value;
        // ตัวจัดการเดิมของบอร์ดฟัง 'change' แบบ delegated จึงต้อง bubble ขึ้นไปถึง document
        input.dispatchEvent(new Event('change', {bubbles: true}));
    }
    close({restoreFocus: true});
};

const build = () => {
    popover = element('div', 'sg-date-picker');
    popover.hidden = true;
    popover.setAttribute('role', 'dialog');
    popover.setAttribute('aria-label', 'เลือกวันที่');

    const header = element('div', 'sg-date-picker__header');
    const previous = element('button', 'sg-date-picker__nav');
    previous.type = 'button';
    previous.append(element('i', 'bi bi-chevron-left'));
    previous.setAttribute('aria-label', 'เดือนก่อนหน้า');

    const next = element('button', 'sg-date-picker__nav');
    next.type = 'button';
    next.append(element('i', 'bi bi-chevron-right'));
    next.setAttribute('aria-label', 'เดือนถัดไป');

    const monthSelect = element('select', 'sg-date-picker__month');
    monthSelect.setAttribute('aria-label', 'เลือกเดือน');
    monthSelect.replaceChildren(...THAI_MONTHS.map((name, index) => {
        const option = element('option', null, name);
        option.value = String(index);

        return option;
    }));

    const yearSelect = element('select', 'sg-date-picker__year');
    yearSelect.setAttribute('aria-label', 'เลือกปี');

    header.append(previous, monthSelect, yearSelect, next);

    const weekdays = element('div', 'sg-date-picker__weekdays');
    weekdays.append(...THAI_WEEKDAYS.map((name) => element('span', null, name)));

    const grid = element('div', 'sg-date-picker__grid');
    grid.setAttribute('role', 'group');

    /*
     * แถวเวลามีเฉพาะช่องประเภท datetime-local (หน้าประชุม)
     * งานในโปรเจกต์ใช้ความละเอียดระดับวัน จึงไม่ต้องเลือกเวลา และแถวนี้ถูกซ่อนไว้
     *
     * ใช้ <input type="time"> ของเบราว์เซอร์ เพราะการเลือกเวลาไม่มีปัญหาเรื่องปฏิทิน พ.ศ.
     * และคีย์บอร์ดของมือถือก็เปิดแป้นตัวเลขให้เองอยู่แล้ว
     */
    const timeRow = element('div', 'sg-date-picker__time');
    timeRow.hidden = true;
    const timeLabel = element('span', null, 'เวลา');
    const timeInput = element('input', 'sg-date-picker__time-input');
    timeInput.type = 'time';
    timeInput.step = '300';
    timeInput.setAttribute('aria-label', 'เลือกเวลา');
    timeRow.append(timeLabel, timeInput);

    const footer = element('div', 'sg-date-picker__footer');
    const todayButton = element('button', 'sg-date-picker__today', 'วันนี้');
    todayButton.type = 'button';
    const closeButton = element('button', 'sg-date-picker__close', 'ปิด');
    closeButton.type = 'button';
    footer.append(todayButton, closeButton);

    popover.append(header, weekdays, grid, timeRow, footer);
    document.body.append(popover);

    previous.addEventListener('click', () => move(-1));
    next.addEventListener('click', () => move(1));
    monthSelect.addEventListener('change', () => {
        if (!state) return;
        state.month = Number(monthSelect.value);
        render();
    });
    yearSelect.addEventListener('change', () => {
        if (!state) return;
        state.year = Number(yearSelect.value);
        render();
    });
    todayButton.addEventListener('click', () => commit(toDateValue(new Date())));
    closeButton.addEventListener('click', () => close({restoreFocus: true}));
    grid.addEventListener('click', (event) => {
        const day = event.target.closest('[data-date-value]');
        if (day && !day.disabled) commit(day.dataset.dateValue);
    });

    // ปิดเมื่อคลิกนอกกรอบและเมื่อกด Escape — ผูกครั้งเดียวตลอดอายุของหน้า
    document.addEventListener('pointerdown', (event) => {
        if (popover.hidden) return;
        if (popover.contains(event.target) || state?.anchor?.contains(event.target)) return;
        close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !popover.hidden) {
            event.stopPropagation();
            close({restoreFocus: true});
        }
    });
    window.addEventListener('resize', () => close());
    // เลื่อนหน้าแล้วตำแหน่งของตัวเปิดเปลี่ยน ปิดอย่างปลอดภัยแทนการคำนวณใหม่ทุกเฟรม
    window.addEventListener('scroll', () => close(), true);

    /*
     * เปลี่ยนเวลาแล้วบันทึกทันทีโดยไม่ปิดกล่อง ผู้ใช้จึงปรับเวลาซ้ำได้จนกว่าจะพอใจ
     * ใช้ 'input' ไม่ใช่ 'change' เพราะช่อง time ของเบราว์เซอร์ยิง change เมื่อออกจากช่องเท่านั้น
     */
    timeInput.addEventListener('input', () => {
        if (!state?.withTime || !timeInput.value) return;

        state.time = timeInput.value;
        const currentDate = splitDateTimeValue(state.input.value).date || toDateValue(new Date());
        const value = joinDateTimeValue(currentDate, state.time);
        if (state.input.value === value) return;

        state.input.value = value;
        state.input.dispatchEvent(new Event('change', {bubbles: true}));
    });

    return {monthSelect, yearSelect, grid, todayButton, timeRow, timeInput};
};

/**
 * เปิดตัวเลือกวันที่ให้ <input type="date"> ตัวหนึ่ง
 *
 * @param {HTMLInputElement} input ช่องวันที่จริงที่ถือทั้งค่าและกติกา min/max
 *                                 รองรับทั้ง type="date" และ type="datetime-local"
 *                                 แบบหลังจะมีแถวเลือกเวลาเพิ่มมาให้ (ใช้ในหน้าประชุม)
 * @param {HTMLElement} [anchor]   สิ่งที่ใช้ยึดตำแหน่งและคืนโฟกัส (ค่าตั้งต้นคือ input)
 */
export const openDatePicker = (input, anchor = input) => {
    if (!input || input.disabled || input.readOnly) return;
    if (!popover) parts = build();

    /*
     * ช่องที่อยู่ในโมดัลของ Bootstrap ต้องให้ popover อยู่ในโมดัลนั้นด้วย
     *
     * Bootstrap ดักโฟกัสไว้ในโมดัล (focustrap) ถ้า popover อยู่นอกโมดัล
     * โฟกัสที่เราให้ปุ่มวันจะถูกดึงกลับเข้าโมดัลทันที จนกดเลือกวันด้วยคีย์บอร์ดไม่ได้เลย
     *
     * โมดัลที่เปิดอยู่ไม่มี transform (.modal.show .modal-dialog ตั้ง transform: none)
     * position: fixed ของ popover จึงยังอิงกับ viewport ตามเดิม การคำนวณตำแหน่งไม่เปลี่ยน
     */
    const host = input.closest('.modal') || document.body;
    if (popover.parentElement !== host) host.append(popover);

    const withTime = input.type === 'datetime-local';
    const {date: datePart, time: timePart} = splitDateTimeValue(input.value);
    const current = parseDateValue(withTime ? datePart : input.value) || new Date();

    state = {
        input,
        anchor,
        withTime,
        // ช่องที่ยังว่างเริ่มที่ชั่วโมงถัดไป ผู้ใช้จึงไม่ต้องพิมพ์เวลาจากศูนย์ทุกครั้ง
        time: withTime ? (timePart || defaultMeetingTime()) : '',
        year: current.getFullYear(),
        month: current.getMonth(),
    };

    popover.hidden = false;
    render();
    popover.querySelector('.sg-date-picker__day.is-selected, .sg-date-picker__day.is-today, .sg-date-picker__day:not(:disabled)')?.focus();
};

export const closeDatePicker = close;

let delegated = false;

/**
 * ให้ทุกช่องที่ประกาศ data-date-picker ใช้ปฏิทินนี้แทนของเบราว์เซอร์
 *
 * ใช้ event delegation ที่ document จึงครอบคลุมแถวที่ถูกวาดเพิ่มภายหลังด้วย
 * และเรียกซ้ำกี่ครั้งก็ผูก listener ชุดเดียว
 */
export const useDatePickers = () => {
    if (delegated) return;
    delegated = true;

    document.addEventListener('click', (event) => {
        if (popover && popover.contains(event.target)) return;

        const label = event.target.closest('label');
        const input = event.target.closest('input[data-date-picker]')
            || label?.querySelector('input[data-date-picker]');
        if (!input) return;

        event.preventDefault();
        openDatePicker(input, label || input);
    });

    // เปิดด้วยคีย์บอร์ดได้เท่ากับเมาส์ ไม่เช่นนั้นช่องที่ซ่อน input ไว้จะใช้ไม่ได้เลย
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const input = event.target.closest('input[data-date-picker]');
        if (!input) return;

        event.preventDefault();
        openDatePicker(input);
    });
};
