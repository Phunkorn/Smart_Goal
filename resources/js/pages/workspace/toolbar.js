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

export const initToolbar = (toolbar, {
    onSelectTool,
    onSelectColor,
    onSelectStickyColor,
    onSelectWidth,
    onSelectFontSize,
    onCommand,
    fontSizeRange = {min: 8, max: 96},
    capabilities = {},
}) => {
    if (! toolbar) {
        return null;
    }

    const editable = capabilities.canEdit === true;

    // ปิดปุ่มที่แก้เนื้อหาสำหรับผู้ที่ดูอย่างเดียว ทำครั้งเดียวตอนผูก เพราะสิทธิ์
    // ไม่เปลี่ยนระหว่างที่หน้าเปิดอยู่
    if (! editable) {
        toolbar.querySelectorAll('[data-requires-edit]').forEach((control) => {
            control.disabled = true;
            control.setAttribute('aria-disabled', 'true');
        });
    }

    toolbar.addEventListener('click', (event) => {
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
            const field = toolbar.querySelector('[data-font-size-input]');
            const next = clampFontSize(Number(field?.value) + Number(stepButton.dataset.fontStep), fontSizeRange);

            onSelectFontSize?.(next);

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
    });

    const fontField = toolbar.querySelector('[data-font-size-input]');

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

    const customColor = toolbar.querySelector('[data-custom-color]');

    if (customColor) {
        // input ยิงถี่ระหว่างลากในกล่องเลือกสี ซึ่งดีสำหรับการดูผลสด
        customColor.addEventListener('input', () => onSelectColor?.(customColor.value));
    }

    return {
        /** สะท้อนสถานะปัจจุบันกลับมาที่ปุ่ม */
        sync({tool, style, canUndo, canRedo, hasSelection, scale, isFullscreen}) {
            setActive(toolbar, '[data-tool]', (node) => node.dataset.tool === tool);
            setActive(toolbar, '[data-color]', (node) => node.dataset.color === style.stroke);
            setActive(
                toolbar,
                '[data-sticky-color]',
                (node) => node.dataset.stickyColor === style.stickyColor
            );
            setActive(
                toolbar,
                '[data-stroke-width]',
                (node) => Number(node.dataset.strokeWidth) === style.strokeWidth
            );
            // ไม่เขียนทับช่องขณะที่ผู้ใช้กำลังพิมพ์อยู่ในนั้น เคอร์เซอร์จะกระโดด
            if (fontField && toolbar.ownerDocument.activeElement !== fontField) {
                fontField.value = String(style.fontSize);
            }

            if (customColor && customColor.value !== style.stroke) {
                customColor.value = style.stroke;
            }

            // ปุ่มที่ทำงานไม่ได้ในสถานะนี้ถูกปิด ไม่ใช่ซ่อน ตำแหน่งของปุ่มอื่นจะ
            // ได้ไม่ขยับไปมาระหว่างใช้งาน
            toggle(toolbar, '[data-command="undo"]', editable && canUndo);
            toggle(toolbar, '[data-command="redo"]', editable && canRedo);
            toggle(toolbar, '[data-command="delete"]', editable && hasSelection);

            const fullscreenButton = toolbar.querySelector('[data-workspace-fullscreen]');

            if (fullscreenButton) {
                fullscreenButton.setAttribute('aria-pressed', isFullscreen ? 'true' : 'false');
                fullscreenButton.classList.toggle('is-active', Boolean(isFullscreen));
                // ไอคอนต้องบอกว่า "กดแล้วจะเกิดอะไร" ไม่ใช่ "ตอนนี้เป็นอะไร"
                fullscreenButton.querySelector('i')?.setAttribute(
                    'class',
                    isFullscreen ? 'bi bi-fullscreen-exit' : 'bi bi-arrows-fullscreen'
                );
            }

            const zoomLabel = toolbar.querySelector('[data-zoom-label]');

            if (zoomLabel) {
                zoomLabel.textContent = `${Math.round(scale * 100)}%`;
            }
        },
    };
};

const setActive = (toolbar, selector, predicate) => {
    toolbar.querySelectorAll(selector).forEach((node) => {
        const active = predicate(node);
        node.classList.toggle('is-active', active);
        node.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
};

const toggle = (toolbar, selector, enabled) => {
    const node = toolbar.querySelector(selector);

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
