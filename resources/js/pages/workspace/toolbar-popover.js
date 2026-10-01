/*
 * แผงตัวเลือกบนแถบเครื่องมือ (สีทั้งหมด สีโน้ต ความหนาเส้น) - popover ไม่ใช่ modal
 *
 * ไม่มีฉากหลัง ไม่ล็อกการเลื่อนหน้า วางตามตำแหน่งปุ่มและตามปุ่มไปเมื่อเลื่อน
 * ปิดเมื่อกดข้างนอก กด Escape (คืนโฟกัสให้ปุ่ม) เลือกค่าเสร็จ หรือปุ่มหลุดจอ
 * เปิดได้ทีละแผง
 *
 * แผงใช้ position: fixed ตามพิกัดของปุ่ม เพราะบนจอแคบแถบเครื่องมือเลื่อน
 * แนวนอนได้ (overflow-x: auto) ถ้าวางแบบ absolute แผงจะถูกขอบแถบตัดทิ้ง
 * การคำนวณตำแหน่งใช้ panelPosition ตัวเดียวกับ popover รายชื่อผู้เข้าร่วม
 *
 * การใช้ค่าจริงไม่อยู่ที่นี่ ปุ่มในแผงเป็น data-color / data-sticky-color /
 * data-stroke-width ตัวเดียวกับที่ toolbar.js จัดการด้วยตัวจัดการเดียว
 */

import {panelPosition} from '../../components/participants-popover.js';

/** ปุ่มที่ "เลือกค่า" ในแผง กดแล้วแผงปิดให้วาดต่อได้ทันที */
const CHOICE_SELECTOR = '[data-color], [data-sticky-color], [data-stroke-width]';

const ready = new WeakSet();

/**
 * @param {HTMLElement} toolbar
 * @param {object} [options]
 * @param {Function} [options.onBeforeOpen] เรียกก่อนเปิดแผง ใช้ให้เมนู (menu.js)
 *   ปิดตัวเองก่อน เพราะเมนูกับแผงค่าต้องไม่เปิดค้างพร้อมกันสองอัน การต่อสายไขว้
 *   อยู่ที่ index.js จุดเดียว ไม่ใช่ให้สองโมดูลรู้จักกันเอง
 */
export const initToolbarPopovers = (toolbar, {onBeforeOpen} = {}) => {
    if (! toolbar || ready.has(toolbar)) {
        return null;
    }

    ready.add(toolbar);

    const doc = toolbar.ownerDocument;
    const win = doc.defaultView;
    let current = null;

    const close = ({restoreFocus = false} = {}) => {
        if (! current) {
            return;
        }

        const {toggle, panel} = current;

        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        current = null;

        if (restoreFocus) {
            toggle.focus();
        }
    };

    const viewport = () => ({width: win?.innerWidth ?? 0, height: win?.innerHeight ?? 0});

    const place = ({toggle, panel}) => {
        const {top, left} = panelPosition(
            toggle.getBoundingClientRect(),
            {width: panel.offsetWidth, height: panel.offsetHeight},
            viewport(),
        );

        panel.style.top = `${top}px`;
        panel.style.left = `${left}px`;
    };

    const open = (toggle) => {
        const panel = toggle.closest('[data-picker]')?.querySelector('[data-picker-panel]');

        if (! panel) {
            return;
        }

        close();
        onBeforeOpen?.();
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        current = {toggle, panel};
        place(current);
    };

    /*
     * เลื่อนหน้าหรือแถบแล้วแผงตามปุ่มไป แทนการปิดทิ้ง
     *
     * บนจอแคบแถบเครื่องมือเป็นแถบเลื่อนแนวนอน ผู้ใช้ปัดแถบมาหาปุ่มแล้วแตะ
     * เหตุการณ์ scroll ของการปัดยังตามมาหลังการแตะ ถ้าปิดแผงทุกครั้งที่เลื่อน
     * แผงจะเปิดแล้วปิดทันทีจนดูเหมือนปุ่มกดไม่ติด (พบในเบราว์เซอร์จริงที่ 375px)
     * ปิดเฉพาะเมื่อปุ่มหลุดออกนอกจอไปแล้ว เพราะแผงที่ลอยอยู่โดยไม่มีปุ่มให้เห็น
     * ทำให้งงว่าเป็นของอะไร
     */
    const follow = () => {
        if (! current) {
            return;
        }

        const rect = current.toggle.getBoundingClientRect();
        const {width, height} = viewport();

        if (rect.bottom < 0 || rect.top > height || rect.right < 0 || rect.left > width) {
            close();

            return;
        }

        place(current);
    };

    toolbar.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-picker-toggle]');

        if (toggle) {
            if (toggle.disabled) {
                return;
            }

            if (current?.toggle === toggle) {
                close();
            } else {
                open(toggle);
            }

            return;
        }

        // เลือกค่าแล้วปิดแผงทันที ผู้ใช้จะได้วาดต่อโดยไม่ต้องกดปิดเอง
        // (toolbar.js ได้รับการคลิกเดียวกันนี้ไปใช้ค่าแล้ว เพราะผูกไว้ที่แถบเดียวกัน)
        if (current?.panel.contains(event.target) && event.target.closest(CHOICE_SELECTOR)) {
            close();
        }
    });

    // ใช้ pointerdown ไม่ใช่ click เพราะการลากบนผืนผ้าใบจบที่อื่น แผงต้องปิดตั้งแต่เริ่มลาก
    doc.addEventListener('pointerdown', (event) => {
        if (current && ! current.panel.contains(event.target) && ! current.toggle.contains(event.target)) {
            close();
        }
    });

    /*
     * Escape ปิดแผงก่อน และยกเลิกเหตุการณ์ไว้ keyboard.js จึงไม่ล้างการเลือกชิ้นงาน
     * ไปพร้อมกัน การกด Escape หนึ่งครั้งควรปิดสิ่งที่อยู่บนสุดเพียงอย่างเดียว
     */
    doc.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && current) {
            event.preventDefault();
            close({restoreFocus: true});
        }
    });

    win?.addEventListener('scroll', follow, true);
    win?.addEventListener('resize', follow);

    return {
        close,
        get current() {
            return current;
        },
    };
};
