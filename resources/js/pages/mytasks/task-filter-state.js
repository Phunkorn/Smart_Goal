/*
 * ค่าที่ยอมรับได้ของ ?task_scope=
 *
 * ต้องตรงกับ App\Support\TaskScopeOptions ฝั่งเซิร์ฟเวอร์ ซึ่งเป็นผู้บังคับตัวกรองจริง
 * รายการนี้มีไว้เพียงเพื่อไม่ให้ค่าที่พิมพ์ผิดถูกใส่กลับลง URL เท่านั้น
 */
export const taskScopes = Object.freeze([
    'all',
    'today',
    'responsible',
    'created',
    'assigned_by_me',
    'collaborating',
]);

/*
 * ขั้นตรวจสอบถูกแยกเป็นสองตัวเลือกที่ไม่ทับกัน แทนตัวเลือก '3' ตัวเดียวที่รวมทุกอย่าง
 *
 * ของเดิมมี 'my_review' (งานที่ฉันตรวจได้) คู่กับ '3' (งานสถานะรอตรวจทั้งหมด)
 * ซึ่ง '3' เป็นซูเปอร์เซ็ตของอีกตัว ผู้ใช้จึงเห็นสองบรรทัดที่ขึ้นต้นว่า "รอตรวจ" เหมือนกัน
 * แล้วเดาไม่ออกว่าต่างกันตรงไหน และเลือกผิดบ่อยเพราะผลลัพธ์ซ้อนกันจริง ๆ
 *
 *   my_review       — คนอื่นส่งงานกลับมาให้เราตรวจ  → ลูกบอลอยู่ที่เรา
 *   awaiting_review — เราส่งงานไปแล้ว รอคนอื่นตรวจ   → ลูกบอลอยู่ที่คนอื่น
 *
 * สองชุดนี้ตัดกันเป็นศูนย์โดยนิยาม งานหนึ่งใบจึงตกอยู่ในตัวเลือกเดียวเสมอ
 *
 *   cross_department — งานข้ามแผนก ตัดสินที่ server (App\Support\CrossDepartmentWork)
 *                      แล้วส่งมาเป็น data-cross-department บนแถวงาน
 */
const boardStatuses = new Set(['', '1', '2', '4', '5', 'late', 'my_review', 'awaiting_review', 'cross_department']);
const dueSorts = new Set(['', 'asc', 'desc']);

export const normalizeTaskScope = (scope) => taskScopes.includes(scope) ? scope : 'all';

export const boardFilterStateFrom = (parameters) => {
    const source = parameters instanceof URLSearchParams
        ? parameters
        : new URLSearchParams(parameters || '');
    const status = source.get('status') || '';
    const dueSort = source.get('due_sort') || '';

    return {
        search: (source.get('search') || '').trim(),
        status: boardStatuses.has(status) ? status : '',
        dueSort: dueSorts.has(dueSort) ? dueSort : '',
        /*
         * ?mine=1 — ปุ่ม "เฉพาะงานของฉัน" บนบอร์ด
         *
         * เทียบกับ '1' แบบเข้ม ค่าที่พิมพ์ผิดอย่าง mine=true หรือ mine=0 จึงกลายเป็น false
         * เหมือนที่ boardStatuses/dueSorts ปฏิเสธค่านอกรายการ ค่าเริ่มต้นคือปิดเสมอ
         */
        mine: source.get('mine') === '1',
    };
};

export const parametersForTaskWorkspace = (parameters, state, scope) => {
    const result = new URLSearchParams(parameters);
    const normalizedScope = normalizeTaskScope(scope);
    const normalizedState = {
        search: String(state?.search || '').trim(),
        status: boardStatuses.has(String(state?.status || '')) ? String(state.status || '') : '',
        dueSort: dueSorts.has(String(state?.dueSort || '')) ? String(state.dueSort || '') : '',
        mine: state?.mine === true,
    };

    if (normalizedScope === 'all') result.delete('task_scope');
    else result.set('task_scope', normalizedScope);

    for (const [key, value] of [
        ['search', normalizedState.search],
        ['status', normalizedState.status],
        ['due_sort', normalizedState.dueSort],
        ['mine', normalizedState.mine ? '1' : ''],
    ]) {
        if (value) result.set(key, value);
        else result.delete(key);
    }

    return result;
};

export const boardTaskMatches = (task, state) => {
    const searchable = String(task.searchable || '').toLowerCase();
    const query = String(state?.search || '').trim().toLowerCase();
    const status = String(state?.status || '');
    const textMatch = !query || searchable.includes(query);

    /*
     * ตัวกรอง "เฉพาะงานของฉัน" — ตัดสินจาก data-participate ที่ server ใส่มา
     * ซึ่งมาจาก WorkOrderPolicy::participate() ที่เดียว ห้ามเดาความเป็นเจ้าของจากฝั่ง client
     *
     * เป็น AND กับเงื่อนไขอื่นเสมอ มันจึงกรองได้แคบลงอย่างเดียว ไม่มีทางดึงงานที่ตัวกรอง
     * สถานะหรือคำค้นตัดทิ้งไปแล้วกลับขึ้นมา
     */
    const mineMatch = ! state?.mine || String(task.participate) === '1';

    // งานที่ "ถึงคิวเราตรวจ" คืองานรอตรวจที่เราตรวจได้ หรืองานแม่ที่มีงานย่อยรอเราตรวจอยู่
    const waitsForMe = (String(task.status) === '3' && String(task.canReview) === '1')
        || Number(task.reviewableSubtasks || 0) > 0;

    const statusMatch = !status
        || (status === 'cross_department'
            ? String(task.crossDepartment) === '1'
            : status === 'late'
            ? String(task.late) === '1'
            : status === 'my_review'
                ? waitsForMe
                // เราส่งไปแล้วรอคนอื่นตรวจ — ตัดงานที่รอเราตรวจออก สองตัวเลือกจึงไม่ทับกัน
                : status === 'awaiting_review'
                    ? String(task.status) === '3' && ! waitsForMe
                    : String(task.status) === status);

    return textMatch && statusMatch && mineMatch;
};
