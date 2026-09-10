/*
 * ดร็อปดาวน์ที่เปิดแบบสไลด์ ใช้แทนหน้าตาแข็ง ๆ ของ <select> มาตรฐาน
 *
 * เสริมบน <select> เดิมแทนการสร้าง input ชุดใหม่ ค่าที่ฟอร์มส่งจึงยังมาจาก <select>
 * ตัวเดียวเป็นแหล่งความจริงเดียว ถ้า JavaScript ไม่ทำงาน ผู้ใช้ยังได้ dropdown ปกติ
 * ของเบราว์เซอร์และฟอร์มยังส่งค่าได้ครบ
 *
 * เป็น popover ไม่ใช่ modal ตามกติกาของระบบ: ไม่ล็อกการเลื่อนหน้า ไม่มีฉากหลังคลุมจอ
 * เกาะอยู่กับปุ่มที่เปิดมัน ปิดเมื่อคลิกนอกกรอบหรือกด Escape และคืนโฟกัสให้ปุ่มเสมอ
 * สถานะเปิด/ปิดมีเจ้าของเดียวคือ setOpen() ไม่มีที่อื่นแตะ class หรือ aria เอง
 */

/**
 * เลื่อนตำแหน่งตัวเลือกที่กำลังชี้ — ฟังก์ชันบริสุทธิ์ ไม่แตะ DOM จึงเขียนเทสต์ได้ตรง ๆ
 *
 * วนกลับหัวท้ายเพื่อให้กดลูกศรค้างแล้วไม่ตัน และคืนตัวแรกเมื่อยังไม่มีตัวเลือกที่ชี้อยู่
 */
export function nextOptionIndex({total = 0, current = -1, step = 1} = {}) {
    if (total <= 0) {
        return -1;
    }

    if (current < 0) {
        return step > 0 ? 0 : total - 1;
    }

    return (current + step + total) % total;
}

/** ตัวเลือกที่เลือกได้จริง — ตัด option ที่ถูก disable ออกตั้งแต่ชั้นข้อมูล */
export function readableOptions(select) {
    return [...select.options].filter((option) => ! option.disabled);
}

/**
 * ผูก <select> หนึ่งตัวเข้ากับดร็อปดาวน์ที่วาดเอง
 *
 * คืน null เมื่อผูกไปแล้วหรือ element ไม่รองรับ เพื่อให้เรียกซ้ำได้โดยไม่เกิด listener ซ้อน
 */
export function enhanceSelect(select, index = 0) {
    if (! select || select.dataset.sgSelectReady === 'true' || select.multiple) {
        return null;
    }

    select.dataset.sgSelectReady = 'true';

    const root = document.createElement('div');
    root.className = 'sg-select';

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'sg-select__trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');

    const label = document.createElement('span');
    label.className = 'sg-select__value';
    const caret = document.createElement('i');
    caret.className = 'bi bi-chevron-down sg-select__caret';
    caret.setAttribute('aria-hidden', 'true');
    trigger.append(label, caret);

    const panel = document.createElement('div');
    panel.className = 'sg-select__panel';
    panel.id = (select.id || 'sg-select-' + index) + '-listbox';
    panel.setAttribute('role', 'listbox');
    trigger.setAttribute('aria-controls', panel.id);

    if (select.id) {
        const owner = document.querySelector('label[for="' + select.id + '"]');

        if (owner) {
            if (! owner.id) {
                owner.id = select.id + '-label';
            }

            trigger.setAttribute('aria-labelledby', owner.id + ' ' + panel.id);
            panel.setAttribute('aria-labelledby', owner.id);
        }
    }

    select.parentNode.insertBefore(root, select);
    root.append(trigger, panel, select);
    select.classList.add('sg-select__native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    let items = [];
    let activeIndex = -1;
    let open = false;

    const syncLabel = () => {
        const selected = select.options[select.selectedIndex];
        label.textContent = selected ? selected.text : '';
    };

    const setActive = (position) => {
        activeIndex = position;
        items.forEach((item, current) => {
            const isActive = current === position;
            item.classList.toggle('is-active', isActive);

            if (isActive) {
                trigger.setAttribute('aria-activedescendant', item.id);
                // เบราว์เซอร์เก่าและ jsdom ไม่มี scrollIntoView ถ้าเรียกตรง ๆ ลูปจะหยุดกลางคัน
                // แล้วตัวเลือกที่เหลือจะไม่ถูกอัปเดตสถานะ
                item.scrollIntoView?.({block: 'nearest'});
            }
        });

        if (position < 0) {
            trigger.removeAttribute('aria-activedescendant');
        }
    };

    const renderOptions = () => {
        panel.textContent = '';
        items = readableOptions(select).map((option, position) => {
            const item = document.createElement('div');
            item.id = panel.id + '-option-' + position;
            item.className = 'sg-select__option';
            item.setAttribute('role', 'option');
            item.dataset.value = option.value;
            item.textContent = option.text;
            item.setAttribute('aria-selected', String(option.selected));
            panel.append(item);

            return item;
        });
    };

    const commit = (position) => {
        const item = items[position];

        if (! item) {
            return;
        }

        select.value = item.dataset.value;
        select.dispatchEvent(new Event('change', {bubbles: true}));
        items.forEach((entry, current) => entry.setAttribute('aria-selected', String(current === position)));
        syncLabel();
    };

    // เจ้าของสถานะเปิด/ปิดเพียงจุดเดียว รวมทั้ง class, aria และการคืนโฟกัส
    const setOpen = (next, {restoreFocus = true} = {}) => {
        if (next === open) {
            return;
        }

        open = next;
        root.classList.toggle('is-open', open);
        trigger.setAttribute('aria-expanded', String(open));

        if (open) {
            renderOptions();
            setActive(items.findIndex((item) => item.dataset.value === select.value));

            return;
        }

        setActive(-1);

        if (restoreFocus) {
            trigger.focus();
        }
    };

    trigger.addEventListener('click', () => setOpen(! open));

    trigger.addEventListener('keydown', (event) => {
        if (! open) {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                setOpen(true);
            }

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setActive(nextOptionIndex({
                total: items.length,
                current: activeIndex,
                step: event.key === 'ArrowDown' ? 1 : -1,
            }));

            return;
        }

        if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            setActive(event.key === 'Home' ? 0 : items.length - 1);

            return;
        }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            commit(activeIndex);
            setOpen(false);

            return;
        }

        if (event.key === 'Escape' || event.key === 'Tab') {
            setOpen(false, {restoreFocus: event.key === 'Escape'});
        }
    });

    panel.addEventListener('click', (event) => {
        const item = event.target.closest('.sg-select__option');

        if (! item) {
            return;
        }

        commit(items.indexOf(item));
        setOpen(false);
    });

    panel.addEventListener('mousemove', (event) => {
        const item = event.target.closest('.sg-select__option');

        if (item) {
            setActive(items.indexOf(item));
        }
    });

    // ปิดเมื่อคลิกนอกกรอบ ไม่คืนโฟกัสเพราะผู้ใช้กำลังจะไปกดที่อื่นอยู่แล้ว
    document.addEventListener('pointerdown', (event) => {
        if (open && ! root.contains(event.target)) {
            setOpen(false, {restoreFocus: false});
        }
    });

    // ค่าที่ถูกเปลี่ยนจากที่อื่น (เช่นกดล้างตัวกรอง) ต้องสะท้อนบนปุ่มด้วย
    select.addEventListener('change', syncLabel);

    syncLabel();

    return {root, trigger, panel, setOpen: (next) => setOpen(next)};
}

/**
 * ผูกทุก <select> ที่อยู่ในขอบเขตที่กำหนด เรียกซ้ำได้โดยไม่เกิด listener ซ้อน
 */
export function initSelectDropdowns(root = document, selector = '[data-sg-select] select') {
    return [...root.querySelectorAll(selector)]
        .map((select, index) => enhanceSelect(select, index))
        .filter(Boolean);
}
