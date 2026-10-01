/*
 * ปุ่มลัดบนแป้นพิมพ์ของหน้าวาด
 *
 * โมดูลนี้ตอบแค่สองคำถาม "การกดครั้งนี้ควรถูกปล่อยผ่านไหม" และ "มันหมายถึง
 * คำสั่งอะไร" ส่วนการทำคำสั่งจริงและการตรวจสิทธิ์อยู่ที่ index.js ซึ่งถือสถานะ
 * เพียงที่เดียว ปุ่มลัดกับปุ่มบนแถบเครื่องมือจึงวิ่งผ่านทางเดียวกันเสมอ
 *
 * ตัวอักษรของเครื่องมือมาจาก WorkspaceDesign::TOOLS (ค่าเดียวกับที่ tooltip แสดง)
 * ผ่าน JSON island ไม่ได้เขียนซ้ำไว้ในไฟล์นี้
 *
 * Ctrl+C / Ctrl+V ไม่อยู่ที่นี่โดยตั้งใจ ดู clipboard-events.js การดัก keydown
 * ของสองปุ่มนี้ทำให้เบราว์เซอร์ไม่ยิงเหตุการณ์ paste และวางรูปจากคลิปบอร์ดไม่ได้
 */

/**
 * ตัวอักษรภาษาอังกฤษของปุ่มที่กด
 *
 * ผู้ใช้หน้านี้พิมพ์ด้วยแป้นภาษาไทยเป็นหลัก เมื่อแป้นไทยเปิดอยู่ event.key ของ
 * ปุ่ม V จะเป็น "อ" ปุ่มลัดจะใช้ไม่ได้เลยจนกว่าจะสลับภาษา จึงถอยไปอ่านตำแหน่ง
 * ปุ่มจริงจาก event.code แต่ยังอ่าน event.key ก่อน เพื่อให้แป้นอย่าง AZERTY
 * ที่ตัวอักษรไม่ตรงตำแหน่งกับ QWERTY ยังได้ตัวอักษรที่พิมพ์อยู่บนปุ่ม
 */
export const letterOf = (event) => {
    const key = typeof event.key === 'string' && event.key.length === 1 ? event.key.toLowerCase() : '';

    if (/^[a-z]$/.test(key)) {
        return key;
    }

    const match = /^Key([A-Z])$/.exec(event.code || '');

    return match ? match[1].toLowerCase() : '';
};

/** แปลง WorkspaceDesign::TOOLS เป็นตาราง ตัวอักษร -> ชื่อเครื่องมือ */
export const toolShortcutsFrom = (tools = {}) => Object.fromEntries(
    Object.entries(tools)
        .filter(([, tool]) => typeof tool?.shortcut === 'string' && /^[A-Za-z]$/.test(tool.shortcut))
        .map(([name, tool]) => [tool.shortcut.toLowerCase(), name])
);

/**
 * การกดครั้งนี้เกิดขณะผู้ใช้กำลังพิมพ์หรือโต้ตอบกับสิ่งอื่นอยู่หรือไม่
 *
 * ต้องตรวจทั้งช่องกรอก กล่องข้อความบนกระดาน (contenteditable) และกล่องโต้ตอบ
 * ที่เปิดทับอยู่ ไม่งั้นการพิมพ์ตัว r ในโน้ตจะเปลี่ยนเป็นเครื่องมือสี่เหลี่ยม
 * หรือ Ctrl+V ในโน้ตจะวางชิ้นงานแทนการวางข้อความ
 */
export const isTypingContext = (doc, event) => {
    if (event.isComposing || event.keyCode === 229) {
        return true;
    }

    return [event.target, doc.activeElement].some((node) => isEditableNode(node) || isInsideDialog(node));
};

export const isEditableNode = (node) => {
    if (! node || node.nodeType !== 1) {
        return false;
    }

    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(node.tagName) || node.isContentEditable === true) {
        return true;
    }

    // jsdom ไม่ได้ทำ isContentEditable ไว้ จึงต้องตรวจ attribute ด้วย
    return Boolean(node.closest?.('[contenteditable]:not([contenteditable="false"])'));
};

const isInsideDialog = (node) =>
    Boolean(node?.closest?.('[aria-modal="true"], [role="dialog"], [role="alertdialog"], .swal2-container'));

/**
 * ความหมายของการกดครั้งนี้ คืน {tool} หรือ {command} หรือ null ถ้าไม่ใช่ปุ่มลัด
 *
 * ปุ่มลัดของเครื่องมือต้องไม่มีปุ่มเสริมกดค้างเลย เพราะ Ctrl+V คือการวาง
 * ไม่ใช่การเลือกเครื่องมือเลือก และ Alt+ตัวอักษรเป็นของเมนูเบราว์เซอร์
 */
export const resolveShortcut = (event, toolShortcuts = {}) => {
    if (event.altKey) {
        return null;
    }

    const letter = letterOf(event);
    const modifier = event.ctrlKey || event.metaKey;

    if (modifier) {
        return modifierShortcut(letter, event.shiftKey);
    }

    if (event.key === 'Delete' || event.key === 'Backspace') {
        return {command: 'delete'};
    }

    if (event.key === 'Escape') {
        return {command: 'escape'};
    }

    if (event.shiftKey || ! letter) {
        return null;
    }

    // F = เต็มจอ ตามพฤติกรรมเดิมก่อนมีปุ่มลัดของเครื่องมือ
    if (letter === 'f') {
        return {command: 'fullscreen'};
    }

    return toolShortcuts[letter] ? {tool: toolShortcuts[letter]} : null;
};

const modifierShortcut = (letter, shift) => {
    switch (letter) {
        case 'z':
            return {command: shift ? 'redo' : 'undo'};
        case 'y':
            return {command: 'redo'};
        case 'd':
            return shift ? null : {command: 'duplicate'};
        // จัดบรรทัด ใช้ชุดเดียวกับ Word และ Google Docs เพราะเป็นชุดที่ผู้ใช้
        // ส่วนใหญ่ติดมืออยู่แล้ว (L = left, E = center, R = right)
        case 'l':
            return shift ? {command: 'align-left'} : null;
        case 'e':
            return shift ? {command: 'align-center'} : null;
        case 'r':
            return shift ? {command: 'align-right'} : null;
        default:
            return null;
    }
};

/**
 * คำสั่งที่ยังต้องทำงานได้แม้เคอร์เซอร์อยู่ในกล่องข้อความของกระดาน
 *
 * ปุ่มลัดเกือบทั้งหมดต้องเงียบขณะพิมพ์ ไม่งั้นการพิมพ์ตัว r ในโน้ตจะกลายเป็น
 * เครื่องมือสี่เหลี่ยม แต่การจัดบรรทัดเป็นคำสั่งที่มีความหมายเฉพาะ "ระหว่าง
 * พิมพ์" เท่านั้น ถ้าปิดไปด้วยก็ไม่เหลือประโยชน์อะไรเลย
 *
 * ด่านที่แคบเข้ามาอีกชั้นคือ isTextEditing ซึ่งผู้เรียกเป็นคนตอบว่าเคอร์เซอร์
 * อยู่ในกล่องข้อความของกระดานจริงหรือไม่ ช่องกรอกอื่นบนหน้า (เช่นขนาดตัวอักษร
 * หรือชื่อกระดานในกล่องโต้ตอบ) จึงไม่ถูกแย่งปุ่มไป
 */
export const TYPING_SAFE_COMMANDS = ['align-left', 'align-center', 'align-right'];

/**
 * ผูกปุ่มลัดเข้ากับเอกสาร
 *
 * @param {object} handlers
 *   onSelectTool(name) -> boolean  เปลี่ยนเครื่องมือได้หรือไม่
 *   onCommand(name)    -> boolean  คำสั่งนั้นทำงานจริงหรือไม่ (ใช้ตัดสินว่าจะ
 *                                  ยกเลิกพฤติกรรมเดิมของเบราว์เซอร์หรือเปล่า)
 *   isGestureActive()  -> boolean  กำลังลากอะไรอยู่หรือไม่
 *   isTextEditing()    -> boolean  เคอร์เซอร์อยู่ในกล่องข้อความของกระดานหรือไม่
 *                                  (ใช้เปิดทางให้ TYPING_SAFE_COMMANDS เท่านั้น)
 */
export const initKeyboardShortcuts = (doc, {
    toolShortcuts = {},
    onSelectTool,
    onCommand,
    isGestureActive = () => false,
    isTextEditing = () => false,
}) => {
    const handleKeydown = (event) => {
        if (event.defaultPrevented) {
            return;
        }

        const shortcut = resolveShortcut(event, toolShortcuts);

        if (! shortcut) {
            return;
        }

        // ขณะพิมพ์ ปล่อยผ่านได้เฉพาะคำสั่งที่มีความหมายระหว่างพิมพ์ และเฉพาะ
        // เมื่อที่พิมพ์อยู่คือกล่องข้อความของกระดานจริง ๆ
        if (isTypingContext(doc, event)
            && ! (TYPING_SAFE_COMMANDS.includes(shortcut.command) && isTextEditing())) {
            return;
        }

        if (shortcut.tool) {
            // เปลี่ยนเครื่องมือกลางท่าลากจะทิ้งท่านั้นไปครึ่งทาง (เช่นชิ้นที่ลากย้าย
            // ค้างอยู่กลางทางโดยไม่ได้บันทึก) จึงรอให้ปล่อยเมาส์ก่อน
            if (! event.repeat && ! isGestureActive() && onSelectTool?.(shortcut.tool)) {
                event.preventDefault();
            }

            return;
        }

        // กดค้างแล้วแป้นยิงซ้ำ ไม่ควรกลายเป็นสำเนาสามสิบชุดโดยไม่ตั้งใจ
        if (event.repeat && shortcut.command === 'duplicate') {
            event.preventDefault();

            return;
        }

        if (onCommand?.(shortcut.command)) {
            event.preventDefault();
        }
    };

    doc.addEventListener('keydown', handleKeydown);

    return {
        destroy() {
            doc.removeEventListener('keydown', handleKeydown);
        },
    };
};
