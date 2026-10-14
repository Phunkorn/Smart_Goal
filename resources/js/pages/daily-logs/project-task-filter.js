/*
 * ช่อง "งาน" ตามโปรเจกต์ที่เลือก — ใช้กับทั้งฟอร์มบันทึกงานและฟอร์มงานประจำ
 *
 * ไม่ได้เลือกโปรเจกต์ = เห็นงานทั้งหมด เลือกโปรเจกต์แล้ว = เห็นเฉพาะงานของโปรเจกต์นั้น
 * ตัวเลือกที่ไม่ตรงถูก disable + hidden ไม่ได้ลบทิ้ง จึงคืนกลับมาได้เมื่อเปลี่ยนโปรเจกต์
 * ดร็อปดาวน์แบบสไลด์ (select-dropdown.js) อ่านเฉพาะ option ที่ไม่ disabled ตอนเปิดแผง
 * จึงไม่ต้องวาดแผงใหม่ที่นี่ ผูกแบบ delegated ที่ root เพื่อรองรับช่องที่ถูกย้ายตำแหน่งในขั้นตอนของฟอร์ม
 */
const PROJECT_SELECTOR = 'select[name="work_order_list_id"]';
const TASK_SELECTOR = 'select[name="job_id"]';

/** ตัวเลือกงานที่ยังเลือกได้เมื่อเลือกโปรเจกต์นี้ — ฟังก์ชันบริสุทธิ์ */
export function taskMatchesProject(taskProjectId, projectId) {
    return ! projectId || String(taskProjectId ?? '') === String(projectId);
}

/** กรองช่องงานของฟอร์มหนึ่งตามโปรเจกต์ที่เลือกอยู่ และล้างงานที่เลือกค้างซึ่งไม่ใช่ของโปรเจกต์นั้น */
export function applyProjectTaskFilter(form) {
    const project = form?.querySelector(PROJECT_SELECTOR);
    const task = form?.querySelector(TASK_SELECTOR);

    if (! project || ! task) return;

    [...task.options].forEach((option) => {
        if (option.value === '') return;

        const visible = taskMatchesProject(option.dataset.projectId, project.value);
        option.disabled = ! visible;
        option.hidden = ! visible;
    });

    if (task.selectedOptions[0]?.disabled) {
        task.value = '';
        // ป้ายของดร็อปดาวน์แบบสไลด์ตามค่า <select> ผ่าน change เท่านั้น
        task.dispatchEvent(new task.ownerDocument.defaultView.Event('change', {bubbles: true}));
    }
}

export function initProjectTaskFilters({root = document.querySelector('[data-daily-log]')} = {}) {
    if (! root || root.dataset.projectTaskFilterReady === 'on') return null;

    root.dataset.projectTaskFilterReady = 'on';

    root.addEventListener('change', (event) => {
        if (event.target.matches?.(PROJECT_SELECTOR)) applyProjectTaskFilter(event.target.form);
    });

    // form.reset() ไม่ยิง change และคืนค่าหลังเหตุการณ์ reset จบ จึงกรองใหม่ในไมโครทาสก์ถัดไป
    root.addEventListener('reset', (event) => {
        queueMicrotask(() => applyProjectTaskFilter(event.target));
    });

    root.querySelectorAll(PROJECT_SELECTOR).forEach((project) => applyProjectTaskFilter(project.form));

    return {apply: applyProjectTaskFilter};
}
