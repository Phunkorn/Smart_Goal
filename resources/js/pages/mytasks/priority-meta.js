export const statusMeta = {
    1: {className: 'status-todo', label: 'ยังไม่เริ่ม'},
    2: {className: 'status-progress', label: 'กำลังทำ'},
    3: {className: 'status-review', label: 'รอตรวจสอบ'},
    4: {className: 'status-done', label: 'เสร็จแล้ว'},
    5: {className: 'status-paused', label: 'พักงาน'},
};

export const projectPriorityMeta = {
    1: {className: 'priority-low', tone: 'project-tone-low', label: 'ต่ำ', projectLabel: 'สำคัญ/ต่ำ'},
    2: {className: 'priority-medium', tone: 'project-tone-medium', label: 'กลาง', projectLabel: 'สำคัญ/กลาง'},
    3: {className: 'priority-high', tone: 'project-tone-high', label: 'สูง', projectLabel: 'สำคัญ/สูง'},
};

export const taskPriorityMeta = {
    1: {className: 'priority-routine', label: 'routine'},
    2: {className: 'priority-important', label: 'สำคัญไม่ด่วน'},
    3: {className: 'priority-urgent', label: 'สำคัญด่วน'},
    4: {className: 'priority-quick', label: 'ด่วนไม่ค่อยสำคัญ'},
    5: {className: 'priority-flexible', label: 'ไม่รีบ ไม่มีกำหนด'},
};

export const statusClasses = [...Object.values(statusMeta).map(({className}) => className), 'status-late'];
export const taskPriorityClasses = Object.values(taskPriorityMeta).map(({className}) => className);
export const projectPriorityClasses = Object.values(projectPriorityMeta).map(({className}) => className);