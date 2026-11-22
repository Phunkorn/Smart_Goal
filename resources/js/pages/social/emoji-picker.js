import {createPopover} from './popover.js';

/*
 * ตัวเลือกอีโมจิของ modal สร้างโพสต์ — popover (พฤติกรรมเปิด/ปิดมาจาก popover.js)
 *
 * สร้างปุ่มอีโมจิครั้งเดียวตอนเปิดครั้งแรก เลือกแล้วแทรกที่ตำแหน่งเคอร์เซอร์และปิดกล่อง
 * ชุดอีโมจิเป็นค่าของหน้าจอ ไม่ใช่ข้อมูลธุรกิจ
 */
export const EMOJIS = [
    '😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '😉', '😊', '😇',
    '🥰', '😍', '🤩', '😘', '😋', '😎', '🤗', '🤔', '😐', '😴', '😌', '😮',
    '😢', '😭', '😤', '😡', '🥳', '😱', '🙏', '👍', '👎', '👏', '🙌', '💪',
    '👌', '✌️', '🤝', '👋', '❤️', '🧡', '💛', '💚', '💙', '💜', '🎉', '🎊',
    '✨', '🔥', '⭐', '🌟', '💯', '✅', '📌', '📣', '💡', '🚀', '🎯', '🏆',
    '☕', '🍰', '🎂', '🎁', '🌈', '☀️', '🌧️', '📷', '💻', '📊', '📝', '⏰',
];

/** แทรกข้อความที่ตำแหน่งเคอร์เซอร์ (หรือแทนที่ช่วงที่เลือก) แล้ววางเคอร์เซอร์ต่อท้ายข้อความที่แทรก */
export function insertAtCursor(field, text) {
    const start = Number.isInteger(field.selectionStart) ? field.selectionStart : field.value.length;
    const end = Number.isInteger(field.selectionEnd) ? field.selectionEnd : start;
    const max = Number(field.getAttribute('maxlength')) || Infinity;

    if (field.value.length - (end - start) + text.length > max) return false;

    field.value = field.value.slice(0, start) + text + field.value.slice(end);
    const caret = start + text.length;
    field.setSelectionRange?.(caret, caret);
    field.dispatchEvent(new field.ownerDocument.defaultView.Event('input', {bubbles: true}));

    return true;
}

export function createEmojiPicker(root, {target, onPick = () => {}} = {}) {
    const toggle = root?.querySelector('[data-social-emoji-toggle]');
    const panel = root?.querySelector('[data-social-emoji-panel]');
    if (! toggle || ! panel) return null;

    const doc = root.ownerDocument;

    const render = () => {
        if (panel.childElementCount > 0) return;

        EMOJIS.forEach((emoji) => {
            const button = doc.createElement('button');
            button.type = 'button';
            button.className = 'social-emoji__option';
            button.dataset.socialEmojiOption = emoji;
            button.textContent = emoji;
            button.setAttribute('aria-label', `แทรก ${emoji}`);
            panel.append(button);
        });
    };

    const popover = createPopover({
        root,
        toggle,
        panel,
        onOpen: render,
        focusOnOpen: () => panel.querySelector('[data-social-emoji-option]')?.focus(),
    });

    panel.addEventListener('click', (event) => {
        const option = event.target.closest('[data-social-emoji-option]');
        if (! option) return;

        const field = typeof target === 'function' ? target() : target;
        if (field) {
            insertAtCursor(field, option.dataset.socialEmojiOption);
            onPick(option.dataset.socialEmojiOption);
        }
        popover.close({restoreFocus: false});
        field?.focus();
    });

    return popover;
}
