/*
 * โปรเจกต์บนมุมมองบอร์ดพับไว้เป็นค่าเริ่มต้น
 *
 * ค่าเริ่มต้นเป็นของ Blade (project-board-card.blade.php วาด is-collapsed มาจาก server)
 * โมดูลนี้จำเฉพาะ "โปรเจกต์ที่ผู้ใช้กางไว้เอง" เพราะงานหลายอย่างบนหน้านี้จบด้วยการโหลดหน้าใหม่
 * (เพิ่มรายการ จัดการทีม สร้างงาน) ถ้าไม่จำ โปรเจกต์ที่กำลังทำอยู่จะพับกลับทุกครั้ง
 *
 * ใช้ sessionStorage จึงจำแค่ในแท็บเดียว เปิดแท็บใหม่ทุกอย่างพับตามค่าเริ่มต้นอีกครั้ง
 * แยกตาม pathname เพราะหน้า "งานของฉัน" กับ Workspace ของสมาชิกแต่ละคนเป็นบอร์ดคนละใบ
 */
const storageKey = (view) => `smartgoal:board-expanded-projects:${view.location.pathname}`;

// sessionStorage โยน error ได้ (โหมดส่วนตัว หรือถูกปิดสิทธิ์) หน้าต้องทำงานต่อได้โดยไม่จำอะไร
const read = (view) => {
    try {
        const keys = JSON.parse(view.sessionStorage.getItem(storageKey(view)) || '[]');
        return new Set(Array.isArray(keys) ? keys.map(String) : []);
    } catch {
        return new Set();
    }
};

export const expandedProjectKeys = (view = window) => read(view);

export const rememberProjectExpanded = (projectKey, expanded = true, view = window) => {
    if (!projectKey) return;
    const keys = read(view);
    if (expanded) keys.add(String(projectKey));
    else keys.delete(String(projectKey));
    try {
        view.sessionStorage.setItem(storageKey(view), JSON.stringify([...keys]));
    } catch {
        // จำไม่ได้ก็ไม่เป็นไร โปรเจกต์แค่กลับไปพับตามค่าเริ่มต้นหลังโหลดหน้าใหม่
    }
};

// ต้องตรงกับ $projectKey ใน project-board-card.blade.php
export const projectKeyForList = (listId) => (listId ? `project-${listId}` : 'general');
