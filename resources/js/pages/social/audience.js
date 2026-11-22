import {createPopover} from './popover.js';

/*
 * ตัวเลือกผู้ที่มองเห็นโพสต์ (ใต้ชื่อผู้โพสต์ใน modal) — ทุกคนในองค์กร / เฉพาะแผนกของฉัน
 *
 * ตัวเลือกจริงเป็น radio ใน <fieldset> ของ Blade จึงได้การเลื่อนด้วยลูกศร การอ่านของ screen reader
 * และสถานะ disabled (ผู้ที่ไม่มีแผนก) จากเบราว์เซอร์เอง ไฟล์นี้แค่ซิงก์ปุ่มสรุปกับตัวเลือก
 * ชื่อแผนกมาจาก server (data-department-label) ไม่มีชื่อแผนกฝังในสคริปต์
 *
 * ค่านี้เป็นแค่ "สิ่งที่ผู้ใช้ขอ" — SocialPostService ตรวจและกำหนดแผนกเป้าหมายจากแผนกจริงของผู้โพสต์
 * การซ่อน/แสดงโพสต์ตัดสินที่ server ทั้งหมด (SocialPost::scopeVisibleTo)
 */
export const EVERYONE = 'everyone';
export const DEPARTMENT = 'department';

export function createAudienceSelector(root) {
    if (! root) return null;

    const toggle = root.querySelector('[data-social-audience-toggle]');
    const panel = root.querySelector('[data-social-audience-panel]');
    const icon = root.querySelector('[data-social-audience-icon]');
    const label = root.querySelector('[data-social-audience-label]');
    const departmentName = root.querySelector('[data-social-audience-department-name]');
    const options = [...root.querySelectorAll('[data-social-audience-option]')];
    const ownDepartmentLabel = departmentName?.textContent.trim() || '';
    let pointerChoice = false;

    const checked = () => options.find((option) => option.checked) ?? options[0];
    const value = () => checked()?.value ?? EVERYONE;

    const render = () => {
        const current = value();
        icon.classList.toggle('bi-people', current === DEPARTMENT);
        icon.classList.toggle('bi-globe2', current !== DEPARTMENT);
        label.textContent = current === DEPARTMENT ? departmentName.textContent.trim() : 'ทุกคนในองค์กร';
        options.forEach((option) => option.closest('[data-social-audience-choice]')?.classList.toggle('is-selected', option.checked));
    };

    const popover = createPopover({
        root,
        toggle,
        panel,
        focusOnOpen: () => checked()?.focus(),
    });

    /*
     * เลือกด้วยเมาส์/นิ้ว → ปิด · เลื่อนด้วยลูกศรบนคีย์บอร์ด → เปิดค้างไว้ ปิดด้วย Enter หรือ Escape
     *
     * ปิดใน task ถัดไป ไม่ใช่ใน change ทันที: การคลิก <label> ย้ายโฟกัสไปที่ radio "หลัง" change
     * ถ้าปิดและคืนโฟกัสให้ปุ่มตรงนี้ เบราว์เซอร์จะดึงโฟกัสกลับไปที่ radio ในแผงที่กำลังปิด (วัดใน Chrome จริง)
     */
    panel.addEventListener('pointerdown', () => { pointerChoice = true; });
    panel.addEventListener('change', () => {
        render();
        if (pointerChoice) setTimeout(() => popover.close(), 0);
        pointerChoice = false;
    });
    panel.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target.matches('[data-social-audience-option]')) {
            event.preventDefault();
            popover.close();
        }
    });

    /**
     * ตั้งค่าตอนเปิด modal — แก้ไขโพสต์ที่จำกัดแผนกแสดงชื่อแผนกที่ตรึงไว้กับโพสต์
     * (เจ้าของอาจย้ายแผนกไปแล้ว) ส่วนโพสต์ใหม่แสดงแผนกปัจจุบันของผู้ใช้
     */
    const set = (next = EVERYONE, {departmentLabel = null} = {}) => {
        departmentName.textContent = departmentLabel || ownDepartmentLabel;
        const wanted = options.find((option) => option.value === next) ?? options[0];
        options.forEach((option) => { option.checked = option === wanted; });
        popover.close({restoreFocus: false});
        render();
    };

    render();

    return {value, set, close: popover.close, isOpen: popover.isOpen};
}
