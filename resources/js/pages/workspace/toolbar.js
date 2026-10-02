/*
 * แถบเครื่องมือ - เลือกเครื่องมือ สี ความหนา และปุ่มคำสั่ง
 *
 * ใช้ event delegation ที่ตัวแถบ ปุ่มจึงถูกเพิ่มหรือเปลี่ยนได้ในอนาคตโดยไม่ต้อง
 * ผูก listener ใหม่
 *
 * ไฟล์นี้ไม่รู้จักคำว่า role เลย สิ่งที่มันรู้คือ capabilities.canEdit ซึ่ง
 * คำนวณมาจาก WorkspaceBoardPolicy ฝั่งเซิร์ฟเวอร์ การซ่อนหรือปิดปุ่มที่นี่เป็น
 * เรื่องของหน้าจอเท่านั้น การบังคับใช้จริงอยู่ที่ endpoint บันทึกซึ่งตรวจ
 * สิทธิ์ update ทุกครั้ง
 */

import {contextGroupsFor} from './toolbar-context.js';
import {initToolbarPopovers} from './toolbar-popover.js';

/**
 * ปิดตัวควบคุมที่แก้เนื้อหาทั้งชุดภายใต้ราก
 *
 * แยกออกมาเป็นฟังก์ชันเพราะตัวควบคุมที่แก้เนื้อหาไม่ได้อยู่แต่บนแถบเครื่องมือ
 * อีกต่อไป เมนูคลิกขวาก็มี ถ้าปล่อยให้แต่ละที่เขียนเงื่อนไขเอง วันหนึ่งจะมี
 * สักที่ที่ลืมไป แล้วผู้ที่ดูอย่างเดียวจะกดปุ่มที่ไม่ควรกดได้
 *
 * เป็นเรื่องของหน้าจอเท่านั้น การบังคับสิทธิ์จริงอยู่ที่ runCommand และที่
 * endpoint บันทึกฝั่งเซิร์ฟเวอร์
 */
export const disableEditControls = (root) => {
    root?.querySelectorAll('[data-requires-edit]').forEach((control) => {
        control.disabled = true;
        control.setAttribute('aria-disabled', 'true');
    });
};

export const initToolbar = (toolbar, {
    contextToolbar = null,
    onSelectTool,
    onSelectColor,
    onSelectStickyColor,
    onSelectWidth,
    onSelectFontSize,
    onToggleBold,
    onToggleItalic,
    onSelectLetterSpacing,
    onSelectAlign,
    onCommand,
    // เรียกก่อนเปิดแผงค่า ใช้ให้เมนูปิดตัวเองก่อน (ต่อสายไขว้ที่ index.js)
    onBeforeOpen,
    fontSizeRange = {min: 8, max: 96},
    letterSpacingRange = {min: -2, max: 8},
    capabilities = {},
}) => {
    if (! toolbar) {
        return null;
    }

    const editable = capabilities.canEdit === true;

    // ปิดปุ่มที่แก้เนื้อหาสำหรับผู้ที่ดูอย่างเดียว ทำครั้งเดียวตอนผูก เพราะสิทธิ์
    // ไม่เปลี่ยนระหว่างที่หน้าเปิดอยู่
    if (! editable) {
        disableEditControls(toolbar);
        disableEditControls(contextToolbar);
    }

    /*
     * ตัวควบคุมอยู่สองแถว (แถวหลัก กับแถวรูปแบบ) การค้นหาและการผูกเหตุการณ์จึง
     * ต้องครอบทั้งสองราก ไม่ใช่แค่แถวหลัก ไม่งั้นช่องขนาดตัวอักษรและจานสีที่ย้าย
     * ไปอยู่แถวที่สองจะหาไม่เจอแล้วกดไม่ติดแบบเงียบ ๆ
     */
    const roots = [toolbar, contextToolbar].filter(Boolean);
    const find = (selector) => roots.reduce((found, root) => found ?? root.querySelector(selector), null);
    const findAll = (selector) => roots.flatMap((root) => Array.from(root.querySelectorAll(selector)));

    const handleClick = (event) => {
        const toolButton = event.target.closest('[data-tool]');

        if (toolButton && ! toolButton.disabled) {
            onSelectTool?.(toolButton.dataset.tool);

            return;
        }

        const stickyButton = event.target.closest('[data-sticky-color]');

        if (stickyButton && ! stickyButton.disabled) {
            onSelectStickyColor?.(stickyButton.dataset.stickyColor);

            return;
        }

        const colorButton = event.target.closest('[data-color]');

        if (colorButton && ! colorButton.disabled) {
            onSelectColor?.(colorButton.dataset.color);

            return;
        }

        const stepButton = event.target.closest('[data-font-step]');

        if (stepButton && ! stepButton.disabled) {
            const field = find('[data-font-size-input]');
            const next = clampFontSize(Number(field?.value) + Number(stepButton.dataset.fontStep), fontSizeRange);

            onSelectFontSize?.(next);

            return;
        }

        const boldButton = event.target.closest('[data-bold-toggle]');

        if (boldButton && ! boldButton.disabled) {
            onToggleBold?.();

            return;
        }

        const italicButton = event.target.closest('[data-italic-toggle]');

        if (italicButton && ! italicButton.disabled) {
            onToggleItalic?.();

            return;
        }

        const spacingStepButton = event.target.closest('[data-letter-spacing-step]');

        if (spacingStepButton && ! spacingStepButton.disabled) {
            const field = find('[data-letter-spacing-input]');
            const next = clampLetterSpacing(
                Number(field?.value) + Number(spacingStepButton.dataset.letterSpacingStep),
                letterSpacingRange
            );

            onSelectLetterSpacing?.(next);

            return;
        }

        const alignButton = event.target.closest('[data-align]');

        if (alignButton && ! alignButton.disabled) {
            onSelectAlign?.(alignButton.dataset.align);

            return;
        }

        const widthButton = event.target.closest('[data-stroke-width]');

        if (widthButton && ! widthButton.disabled) {
            onSelectWidth?.(Number(widthButton.dataset.strokeWidth));

            return;
        }

        const commandButton = event.target.closest('[data-command]');

        if (commandButton && ! commandButton.disabled) {
            onCommand?.(commandButton.dataset.command);
        }
    };

    roots.forEach((root) => root.addEventListener('click', handleClick));

    /*
     * ปุ่มบนแถบรูปแบบต้องไม่ขโมยเคอร์เซอร์ไปจากกล่องข้อความที่กำลังพิมพ์อยู่
     *
     * เบราว์เซอร์ย้ายโฟกัสไปที่ปุ่มตั้งแต่ mousedown กล่องข้อความจึง blur แล้ว
     * บันทึกและปิดโหมดแก้ไขทิ้งไปก่อนที่เหตุการณ์ click จะมาถึงด้วยซ้ำ พอถึงคิว
     * ของปุ่มจัดบรรทัด ก็ไม่เหลือเคอร์เซอร์ให้รู้ว่าผู้ใช้หมายถึงบรรทัดไหน
     * คำสั่งจึงตกไปที่ทางเลือกสำรองคือ "จัดทั้งกล่อง" แล้วบรรทัดที่ผู้ใช้ตั้งใจ
     * จัดไว้คนละแบบก็ถูกกลืนตามไปหมด (ตรงกับอาการที่ผู้ใช้รายงาน: ตั้งบรรทัดแรก
     * กึ่งกลางไว้ พอสั่งบรรทัดที่สองชิดขวา บรรทัดแรกก็ย้ายไปชิดขวาด้วย)
     *
     * ยกเลิก mousedown ปุ่มจึงไม่รับโฟกัส เคอร์เซอร์ค้างอยู่ในกล่องเดิม และการ
     * กดยังทำงานครบเหมือนเดิมเพราะ click ยังยิงตามปกติ ทำเฉพาะแถวรูปแบบ ซึ่ง
     * เป็นแถวที่ใช้ระหว่างพิมพ์ ส่วนแถวหลัก (เปลี่ยนเครื่องมือ) ยังรับโฟกัสตามปกติ
     * เพราะการเปลี่ยนเครื่องมือควรจบการพิมพ์อยู่แล้ว
     *
     * ไม่ครอบช่องกรอก (input) เพราะช่องขนาดตัวอักษรกับระยะห่างต้องคลิกเข้าไป
     * พิมพ์ได้
     */
    contextToolbar?.addEventListener('mousedown', (event) => {
        if (event.target.closest('button')) {
            event.preventDefault();
        }
    });

    const fontField = find('[data-font-size-input]');

    if (fontField) {
        /*
         * ตอบสนองตั้งแต่ตอนพิมพ์ ไม่ใช่รอ change เพราะผู้ใช้ที่กำลังจัดขนาด
         * ต้องการเห็นผลทันทีเพื่อตัดสินใจว่าพอหรือยัง
         *
         * แต่ยังไม่บีบค่าให้อยู่ในช่วงระหว่างพิมพ์ ไม่งั้นการพิมพ์ "1" เพื่อจะ
         * ไปเป็น "16" จะถูกดันเป็น "8" ทันทีแล้วพิมพ์ต่อไม่ได้
         */
        fontField.addEventListener('input', () => {
            const value = Number(fontField.value);

            if (Number.isFinite(value) && value >= fontSizeRange.min && value <= fontSizeRange.max) {
                onSelectFontSize?.(value);
            }
        });

        // บีบค่าให้เข้าช่วงเมื่อออกจากช่อง เพื่อไม่ให้เหลือค่าที่ใช้ไม่ได้ค้างไว้
        fontField.addEventListener('change', () => {
            const value = clampFontSize(Number(fontField.value), fontSizeRange);

            fontField.value = String(value);
            onSelectFontSize?.(value);
        });

        // Enter ในช่องตัวเลขไม่ควรไปกระตุ้นปุ่มอื่นบนแถบ
        fontField.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                fontField.blur();
            }
        });
    }

    const spacingField = find('[data-letter-spacing-input]');

    if (spacingField) {
        spacingField.addEventListener('input', () => {
            const value = Number(spacingField.value);

            if (Number.isFinite(value) && value >= letterSpacingRange.min && value <= letterSpacingRange.max) {
                onSelectLetterSpacing?.(value);
            }
        });

        spacingField.addEventListener('change', () => {
            const value = clampLetterSpacing(Number(spacingField.value), letterSpacingRange);

            spacingField.value = String(value);
            onSelectLetterSpacing?.(value);
        });

        spacingField.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                spacingField.blur();
            }
        });
    }

    const customColor = find('[data-custom-color]');

    if (customColor) {
        // input ยิงถี่ระหว่างลากในกล่องเลือกสี ซึ่งดีสำหรับการดูผลสด
        customColor.addEventListener('input', () => onSelectColor?.(customColor.value));
    }

    /*
     * ผูกแผงที่เชลล์ ไม่ใช่ที่แถวใดแถวหนึ่ง เพราะแผงสีและแผงความหนาย้ายไปอยู่
     * แถวรูปแบบแล้ว แต่ยังต้องเปิดได้ทีละแผงร่วมกันทั้งสองแถว
     * ผูกหลังตัวจัดการคลิกด้านบน ค่าจึงถูกใช้ก่อนแล้วแผงค่อยปิด
     */
    const pickers = initToolbarPopovers(
        toolbar.closest('[data-workspace-toolbar-shell]') ?? toolbar,
        {onBeforeOpen}
    );

    const strokeCurrent = find('[data-picker-current="stroke"]');
    const stickyCurrent = find('[data-picker-current="sticky"]');
    const widthCurrent = find('[data-picker-current="width"]');
    const fullscreenButton = find('[data-workspace-fullscreen]');
    const zoomLabel = find('[data-zoom-label]');

    return {
        /** ปิดแผงค่าที่เปิดอยู่ ใช้ตอนเมนูจะเปิด เพื่อไม่ให้ค้างพร้อมกันสองอัน */
        closePickers: () => pickers?.close(),

        /** สะท้อนสถานะปัจจุบันกลับมาที่ปุ่ม */
        sync({tool, style, canUndo, canRedo, hasSelection, selectedTypes = [], scale, isFullscreen, activeAlign, editingText = false}) {
            setActive(findAll('[data-tool]'), (node) => node.dataset.tool === tool);

            /*
             * ปุ่มเปิดแผงต้องบอกให้ได้ว่ากำลังถือเครื่องมือไหนอยู่ ไม่ใช่แสดง
             * ไอคอนกลาง ๆ ของกลุ่ม ปุ่มจึงทั้งสว่างขึ้นและสลับไอคอนเป็นของ
             * เครื่องมือที่เลือก (ไอคอนมาจาก data-tool-icon ที่ Blade ใส่ไว้
             * ไฟล์นี้จึงไม่ต้องรู้จักชื่อไอคอนของเครื่องมือใด ๆ เลย)
             *
             * ก่อนหน้านี้ปุ่มแสดงไอคอนกลุ่มตายตัว ผู้ใช้เลือกยางลบจากเมนูแล้ว
             * ยังเห็นไอคอนดินสอค้างอยู่ จึงไม่รู้ว่าตอนนี้ลบได้แล้วหรือยัง
             */
            findAll('[data-menu-tools]').forEach((trigger) => {
                const owns = trigger.dataset.menuTools.split(' ').includes(tool);
                const icon = trigger.querySelector('[data-menu-icon]');

                if (! icon) {
                    return;
                }

                const active = owns
                    ? trigger.parentElement?.querySelector(`[data-tool="${tool}"]`)?.dataset.toolIcon
                    : null;

                icon.setAttribute('class', `${active ?? icon.dataset.defaultIcon} wsb-menu__icon`);
            });

            setActive(
                findAll('[data-menu-tools]'),
                (node) => node.dataset.menuTools.split(' ').includes(tool)
            );
            setActive(findAll('[data-color]'), (node) => node.dataset.color === style.stroke);
            setActive(
                findAll('[data-sticky-color]'),
                (node) => node.dataset.stickyColor === style.stickyColor
            );
            setActive(
                findAll('[data-stroke-width]'),
                (node) => Number(node.dataset.strokeWidth) === style.strokeWidth
            );
            // ไม่เขียนทับช่องขณะที่ผู้ใช้กำลังพิมพ์อยู่ในนั้น เคอร์เซอร์จะกระโดด
            if (fontField && toolbar.ownerDocument.activeElement !== fontField) {
                fontField.value = String(style.fontSize);
            }

            setActive(findAll('[data-bold-toggle]'), () => style.bold === true);
            setActive(findAll('[data-italic-toggle]'), () => style.italic === true);
            // activeAlign มาจากย่อหน้าที่เคอร์เซอร์อยู่ตอนกำลังแก้ไข ถ้าไม่ได้
            // แก้ไขอยู่ (เป็น null) ถอยไปใช้ style.align ซึ่งคือค่าตั้งต้นของ
            // กล่องที่เลือกอยู่/กล่องถัดไปที่จะสร้าง
            setActive(findAll('[data-align]'), (node) => node.dataset.align === (activeAlign ?? style.align ?? 'left'));

            /*
             * จัดบรรทัดกดได้เฉพาะขณะพิมพ์อยู่ในกล่องเท่านั้น
             *
             * เดิมกดได้ตอนที่แค่เลือกกล่องด้วย แล้วคำสั่งจะกลายเป็น "จัดทุกบรรทัดให้เหมือนกัน"
             * ซึ่งลบการจัดรายบรรทัดที่ผู้ใช้ตั้งไว้ทิ้งทั้งหมด ปุ่มเดียวกันจึงทำสองอย่างโดย
             * หน้าจอไม่บอกว่าตอนนี้อยู่โหมดไหน (ผู้ใช้รายงานว่า "กดแล้วมันไปทั้งบรรทัดเลย")
             *
             * ปิดปุ่มไว้แทนการซ่อน ตำแหน่งปุ่มอื่นจะได้ไม่ขยับ และ title บอกวิธีเปิดใช้งาน
             * ตามกติกาเดิมของแถบนี้ (ดู toggle() ข้างล่าง) การบังคับจริงอยู่ที่ chooseAlign ใน index.js
             */
            findAll('[data-align]').forEach((button) => {
                toggle(button, editable && editingText);
                button.title = editingText
                    ? button.dataset.tooltip ?? ''
                    : 'ดับเบิลคลิกที่กล่องเพื่อพิมพ์ก่อน จึงจัดบรรทัดได้';
            });

            if (spacingField && toolbar.ownerDocument.activeElement !== spacingField) {
                spacingField.value = String(style.letterSpacing ?? 0);
            }

            if (customColor && customColor.value !== style.stroke) {
                customColor.value = style.stroke;
            }

            // ปุ่มเปิดแผงบอกสีที่ใช้อยู่ แม้สีนั้นจะไม่อยู่ในแถวลัดบนแถบ
            strokeCurrent?.style.setProperty('--wsb-swatch', style.stroke);
            stickyCurrent?.style.setProperty('--wsb-swatch', style.stickyColor);
            // หนีบไว้ที่ 12px เท่ากับตัวอย่างในแผง เส้น 16px เต็มปุ่มจนดูไม่ออกว่าเป็นเส้น
            widthCurrent?.style.setProperty('--wsb-width', `${Math.min(style.strokeWidth, 12)}px`);

            /*
             * แถวรูปแบบและแต่ละกลุ่มในแถวโผล่เฉพาะที่เกี่ยวข้องกับสิ่งที่กำลังทำอยู่
             * ผู้ตัดสินคือ contextGroupsFor ซึ่งเป็นตรรกะบริสุทธิ์และทดสอบแยกได้
             *
             * ที่นี่เป็นเจ้าของ attribute hidden ของแถวนี้เพียงผู้เดียว การพับ/กาง
             * แถบเป็นสถานะระดับเชลล์ (ดู initToolbarCollapse) จึงไม่ชนกัน
             */
            if (contextToolbar) {
                const groups = contextGroupsFor({tool, selectedTypes, canEdit: editable});

                contextToolbar.querySelectorAll('[data-context-group]').forEach((group) => {
                    group.hidden = ! groups.includes(group.dataset.contextGroup);
                });

                contextToolbar.hidden = groups.length === 0;
            }

            // ปุ่มที่ทำงานไม่ได้ในสถานะนี้ถูกปิด ไม่ใช่ซ่อน ตำแหน่งของปุ่มอื่นจะ
            // ได้ไม่ขยับไปมาระหว่างใช้งาน
            toggle(find('[data-command="undo"]'), editable && canUndo);
            toggle(find('[data-command="redo"]'), editable && canRedo);
            toggle(find('[data-command="delete"]'), editable && hasSelection);

            if (fullscreenButton) {
                fullscreenButton.setAttribute('aria-pressed', isFullscreen ? 'true' : 'false');
                fullscreenButton.classList.toggle('is-active', Boolean(isFullscreen));
                // ไอคอนต้องบอกว่า "กดแล้วจะเกิดอะไร" ไม่ใช่ "ตอนนี้เป็นอะไร"
                fullscreenButton.querySelector('i')?.setAttribute(
                    'class',
                    isFullscreen ? 'bi bi-fullscreen-exit' : 'bi bi-arrows-fullscreen'
                );
            }

            if (zoomLabel) {
                zoomLabel.textContent = `${Math.round(scale * 100)}%`;
            }
        },
    };
};

/*
 * มือจับพับ/กางแถบเครื่องมือ — แยกจาก initToolbar เพราะไม่เกี่ยวกับสถานะ
 * เครื่องมือวาดเลย และต้องทำงานได้แม้เป็นผู้ดูอย่างเดียว (capabilities.canEdit
 * เป็นเท็จ) จึงไม่ผ่านการตรวจสิทธิ์ใด ๆ ในนี้
 *
 * พับเป็นสถานะระดับเชลล์ (data-collapsed) ไม่ใช่ attribute hidden ของแต่ละแถว
 * เพราะแถวรูปแบบมี hidden เป็นของ sync() อยู่แล้ว ถ้าการพับไปเขียนทับตัวเดียวกัน
 * สองฝ่ายจะแย่งกันเป็นเจ้าของสถานะ แล้วการกางกลับจะทำให้แถวรูปแบบโผล่มาทั้งที่
 * ไม่มีอะไรให้ปรับ (CLAUDE.md: overlay ต้องมีเจ้าของเดียว) ตัวที่ซ่อนจริงคือ CSS
 *
 * ข้อความไทยของทั้งสองสถานะมาจาก data-label-* ใน Blade ไฟล์นี้จึงไม่ต้อง
 * พิมพ์ข้อความไทยซ้ำ ตามกติกาของ WorkspaceDesign
 */
export const initToolbarCollapse = (toolbar) => {
    const shell = toolbar?.closest('[data-workspace-toolbar-shell]');
    const handle = shell?.querySelector('[data-workspace-toolbar-toggle]');

    if (! toolbar || ! handle) {
        return;
    }

    handle.addEventListener('click', () => {
        const expanded = shell.dataset.collapsed === 'on';

        if (expanded) {
            delete shell.dataset.collapsed;
        } else {
            shell.dataset.collapsed = 'on';
        }

        handle.setAttribute('aria-expanded', String(expanded));
        handle.setAttribute('aria-label', expanded ? handle.dataset.labelExpanded : handle.dataset.labelCollapsed);
        handle.querySelector('i')?.setAttribute('class', expanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down');
    });
};

const setActive = (nodes, predicate) => {
    nodes.forEach((node) => {
        const active = predicate(node);
        node.classList.toggle('is-active', active);
        node.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
};

const toggle = (node, enabled) => {
    if (node) {
        node.disabled = ! enabled;
    }
};

/**
 * บีบขนาดตัวอักษรให้อยู่ในช่วงที่ใช้ได้
 *
 * ค่าที่ไม่ใช่ตัวเลข (ช่องว่าง หรือพิมพ์ตัวหนังสือ) ถอยไปที่ค่าต่ำสุด แทนการ
 * ปล่อย NaN ออกไป ซึ่งจะกลายเป็นขนาดที่เรนเดอร์ไม่ได้
 */
const clampFontSize = (value, {min, max}) => {
    if (! Number.isFinite(value)) {
        return min;
    }

    return Math.round(Math.min(max, Math.max(min, value)));
};

/**
 * บีบระยะห่างตัวอักษรให้อยู่ในช่วงที่ใช้ได้
 *
 * ค่าที่ไม่ใช่ตัวเลขถอยไปที่ 0 (ค่าปกติ) ไม่ใช่ min เหมือน clampFontSize
 * เพราะระยะห่างมีทั้งค่าบวกและลบ ไม่มีขอบล่างที่นับเป็น "ค่าต่ำสุดที่ใช้งานได้"
 */
const clampLetterSpacing = (value, {min, max}) => {
    if (! Number.isFinite(value)) {
        return 0;
    }

    return Math.round(Math.min(max, Math.max(min, value)));
};
