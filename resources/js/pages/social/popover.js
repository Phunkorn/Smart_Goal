/*
 * popover ของหน้าชุมชน — ที่เดียวของพฤติกรรม เปิด/ปิด, Escape, คลิกนอกกล่อง และคืนโฟกัส
 * ใช้ร่วมกันโดยตัวเลือกอีโมจิ (emoji-picker.js) และตัวเลือกผู้ที่มองเห็นโพสต์ (audience.js)
 *
 * เป็น popover ไม่ใช่ modal: อยู่ติดกับปุ่มที่เปิด ไม่ล็อกการเลื่อน ไม่บังทั้งหน้า
 * สถานะอยู่ที่ data-open ของแผง (CSS ทำแอนิเมชันทั้งตอนเปิดและปิด และซ่อนแผงที่ปิดจาก
 * คีย์บอร์ดและ screen reader ด้วย visibility: hidden) ไม่ใช่ attribute hidden ซึ่งตัดแอนิเมชันทิ้ง
 *
 * Escape ขณะเปิดถูกหยุดไว้ที่นี่ จึงปิดแค่ popover ไม่ปิด modal ของ Bootstrap ที่ครอบอยู่
 * listener ผูกครั้งเดียวตอนสร้าง เปิดกี่ครั้งก็ไม่ผูกซ้ำ
 */
export function createPopover({root, toggle, panel, onOpen = () => {}, focusOnOpen = () => {}}) {
    const doc = root.ownerDocument;
    const isOpen = () => panel.dataset.open === 'true';

    const setOpen = (open) => {
        panel.dataset.open = open ? 'true' : 'false';
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    const close = ({restoreFocus = true} = {}) => {
        if (! isOpen()) return;
        setOpen(false);
        if (restoreFocus) toggle.focus();
    };

    const open = () => {
        onOpen();
        setOpen(true);
        focusOnOpen();
    };

    setOpen(false);
    toggle.addEventListener('click', () => (isOpen() ? close() : open()));

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            event.preventDefault();
            event.stopPropagation();
            close();
        }
    });

    /*
     * คลิกนอกกล่อง (รวมถึงส่วนอื่นของ modal) และ Tab ออกจากกล่อง ปิดโดยไม่ดึงโฟกัสกลับ
     *
     * ระหว่างกดเมาส์ภายในกล่องต้องไม่ปิดจาก focusout: กดที่ <label> ซึ่งโฟกัสไม่ได้ เบราว์เซอร์ย้ายโฟกัส
     * ไปที่บรรพบุรุษที่โฟกัสได้ (กล่อง .modal) ก่อน แล้วจึงให้ radio ทีหลัง (วัดลำดับ event ใน Chrome จริง)
     * ถ้าปิดตรงนั้น โฟกัสจะตกไปที่ <body> แทนที่จะกลับมาที่ปุ่ม
     */
    let pressingInside = false;
    root.addEventListener('pointerdown', () => { pressingInside = true; }, true);
    doc.addEventListener('pointerup', () => setTimeout(() => { pressingInside = false; }, 0), true);
    doc.addEventListener('pointercancel', () => { pressingInside = false; }, true);

    doc.addEventListener('pointerdown', (event) => {
        if (isOpen() && ! root.contains(event.target)) close({restoreFocus: false});
    });
    root.addEventListener('focusout', (event) => {
        if (pressingInside) return;
        if (isOpen() && event.relatedTarget && ! root.contains(event.relatedTarget)) close({restoreFocus: false});
    });

    return {open, close, isOpen};
}
