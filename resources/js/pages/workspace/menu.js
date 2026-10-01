/*
 * เมนูคำสั่ง - popover ไม่ใช่ modal
 *
 * ใช้ร่วมกันสามที่: แผงรูปทรงบนแถบเครื่องมือ, เมนูจัดการกระดานบนหัวเรื่อง
 * และเมนูคลิกขวาบนผืนผ้าใบ ทั้งสามใช้คำศัพท์ markup ชุดเดียวกัน
 *   [data-menu]        กล่องครอบปุ่มกับแผง (เมนูคลิกขวาไม่มีปุ่ม จึงอยู่บนแผงเอง)
 *   [data-menu-toggle] ปุ่มที่กดเปิด
 *   [data-menu-panel]  ตัวแผง
 *
 * ไม่มีเมนูซ้อนเมนู เคยมีมาก่อนแล้วถอดออก เพราะการต้องเล็งเมาส์จากรายการแม่เข้า
 * แผงย่อยโดยไม่ให้หลุดออกนอกทางทำให้กดไม่ติดในการใช้งานจริง
 *
 * ไฟล์นี้เป็นเจ้าของสถานะเปิด/ปิด โฟกัส Escape และการซ้อนกันของเมนูทุกตัวบนหน้านี้
 * เพียงผู้เดียว (CLAUDE.md: overlay ต้องมีเจ้าของเดียว) ส่วนการตัดสินว่ารายการไหน
 * กดได้ และการทำงานของรายการ เป็นของ context-menu.js กับ toolbar.js
 *
 * ไม่รวมเข้ากับ toolbar-popover.js โดยตั้งใจ ตัวนั้นเป็นตะแกรงให้เลือก "ค่า"
 * ที่ผูกกับปุ่มใดปุ่มหนึ่ง ส่วนตัวนี้เป็นรายการ "คำสั่ง" ที่เดินด้วยลูกศรและ
 * เปิดที่ตำแหน่งเคอร์เซอร์ได้ ถ้ายัดรวมกันโมดูลเดียวจะมีสองคำศัพท์
 * ในที่เดียว ซึ่งเป็นสิ่งที่กติกาเรื่องเจ้าของเดียวพยายามเลี่ยง
 *
 * ไม่มีข้อความไทยในไฟล์นี้ ป้ายทุกคำมาจาก Blade ตาม WorkspaceDesign
 */

import {panelPosition} from '../../components/participants-popover.js';

/** รายการที่เดินด้วยลูกศรได้ ต้องไม่รวมรายการที่ถูกปิด */
const FOCUSABLE_SELECTOR = 'button:not(:disabled)';

const ready = new WeakSet();

export const initMenus = (root, {onBeforeOpen} = {}) => {
    if (! root || ready.has(root)) {
        return null;
    }

    ready.add(root);

    const doc = root.ownerDocument;
    const win = doc.defaultView;

    // เปิดได้ทีละแผง
    let current = null;

    const viewport = () => ({width: win?.innerWidth ?? 0, height: win?.innerHeight ?? 0});

    const place = (panel, rect) => {
        const {top, left} = panelPosition(
            rect,
            {width: panel.offsetWidth, height: panel.offsetHeight},
            viewport(),
        );

        panel.style.top = `${top}px`;
        panel.style.left = `${left}px`;
    };

    /** กรอบขนาดศูนย์ที่พิกัดหนึ่ง ใช้เปิดเมนูที่ตำแหน่งเคอร์เซอร์ */
    const pointRect = ({x, y}) => ({top: y, bottom: y, left: x, right: x, width: 0, height: 0});

    const itemsOf = (panel) => Array.from(panel.querySelectorAll(FOCUSABLE_SELECTOR));

    const close = ({restoreFocus = false} = {}) => {
        if (! current) {
            return;
        }

        const {toggle, panel} = current;

        panel.hidden = true;
        toggle?.setAttribute('aria-expanded', 'false');
        current = null;

        if (restoreFocus) {
            toggle?.focus();
        }
    };

    const openPanel = (panel, rect, toggle = null) => {
        if (! panel) {
            return null;
        }

        // ปิดของเดิมก่อนแจ้งฝ่ายอื่น ไม่งั้นตัวที่เพิ่งเปิดจะถูกปิดตามไปด้วย
        close();
        onBeforeOpen?.();

        panel.hidden = false;
        toggle?.setAttribute('aria-expanded', 'true');
        current = {toggle, panel, rect};
        place(panel, rect);

        /*
         * ย้ายโฟกัสไปรายการแรกทุกครั้งที่เปิด ทั้งจากเมาส์และจากคีย์บอร์ด ตามแบบแผน
         * menu button ของ WAI-ARIA (แผงนี้ประกาศ role="menu" ไว้) ผู้ใช้คีย์บอร์ด
         * จึงเดินรายการต่อได้ทันทีโดยไม่ต้องกดลูกศรลงหนึ่งครั้งเพื่อ "เข้า" แผงก่อน
         */
        itemsOf(panel)[0]?.focus();

        return current;
    };

    /*
     * เลื่อนหน้าหรือแถบแล้วแผงตามปุ่มไป แทนการปิดทิ้ง ด้วยเหตุผลเดียวกับแผงสี
     * (ดู toolbar-popover.js) เมนูที่เปิดที่ตำแหน่งเคอร์เซอร์ไม่มีปุ่มให้ตาม
     * จึงปิดไปเลยเมื่อมีการเลื่อน เพราะพิกัดเดิมไม่ได้ชี้ไปที่อะไรอีกต่อไป
     */
    const follow = () => {
        if (! current) {
            return;
        }

        if (! current.toggle) {
            close();

            return;
        }

        const rect = current.toggle.getBoundingClientRect();
        const {width, height} = viewport();

        if (rect.bottom < 0 || rect.top > height || rect.right < 0 || rect.left > width) {
            close();

            return;
        }

        current.rect = rect;
        place(current.panel, rect);
    };

    /** เดินโฟกัสในแผงที่เปิดอยู่ (วนรอบ) */
    const moveFocus = (step) => {
        const panel = current?.panel;

        if (! panel) {
            return;
        }

        const items = itemsOf(panel);

        if (! items.length) {
            return;
        }

        const at = items.indexOf(doc.activeElement);
        const next = step === 'first' ? 0
            : step === 'last' ? items.length - 1
                : (at + step + items.length) % items.length;

        items[next]?.focus();
    };

    root.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-menu-toggle]');

        if (toggle && root.contains(toggle)) {
            if (toggle.disabled) {
                return;
            }

            if (current?.toggle === toggle) {
                close();

                return;
            }

            openPanel(
                toggle.closest('[data-menu]')?.querySelector('[data-menu-panel]'),
                toggle.getBoundingClientRect(),
                toggle,
            );

            return;
        }

        /*
         * เลือกรายการแล้วปิดเมนูทันที ผู้ใช้จะได้ทำงานต่อโดยไม่ต้องกดปิดเอง
         * ตัวจัดการของรายการ (toolbar.js หรือ context-menu.js) ได้รับการคลิก
         * เดียวกันนี้ไปแล้ว เพราะผูกไว้ที่ root เดียวกันหรือที่แผงเอง
         */
        if (current?.panel.contains(event.target) && event.target.closest('button')) {
            close();
        }
    });

    // ใช้ pointerdown ไม่ใช่ click เพราะการลากบนผืนผ้าใบจบที่อื่น เมนูต้องปิดตั้งแต่เริ่มลาก
    doc.addEventListener('pointerdown', (event) => {
        if (! current) {
            return;
        }

        const inside = current.panel.contains(event.target)
            || current.toggle?.contains(event.target) === true;

        if (! inside) {
            close();
        }
    });

    doc.addEventListener('keydown', (event) => {
        if (! current) {
            return;
        }

        /*
         * Escape ปิดเมนูและยกเลิกเหตุการณ์ไว้ keyboard.js จึงไม่ล้างการเลือก
         * ชิ้นงานไปพร้อมกัน การกด Escape หนึ่งครั้งควรปิดสิ่งที่อยู่บนสุดอย่างเดียว
         */
        if (event.key === 'Escape') {
            event.preventDefault();
            close({restoreFocus: true});

            return;
        }

        /*
         * แผงรูปทรงเรียงตัวเลือกเป็นแถวเดียว ลูกศรซ้าย/ขวาจึงต้องเดินรายการได้
         * เหมือนลูกศรขึ้น/ลง ผู้ใช้ไม่ควรต้องรู้ว่าแผงไหนเรียงแนวไหน
         */
        const steps = {
            ArrowDown: 1, ArrowRight: 1,
            ArrowUp: -1, ArrowLeft: -1,
            Home: 'first', End: 'last',
        };

        if (steps[event.key] !== undefined) {
            event.preventDefault();
            moveFocus(steps[event.key]);
        }
    });

    win?.addEventListener('scroll', follow, true);
    win?.addEventListener('resize', follow);

    return {
        close,

        /** เปิดแผงที่พิกัดหน้าจอหนึ่ง (เมนูคลิกขวา) */
        openAt: (panel, point) => openPanel(panel, pointRect(point)),

        get current() {
            return current;
        },
    };
};
