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
    'department',
]);

const boardStatuses = new Set(['', '1', '2', '3', '4', '5', 'late']);
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
    };
};

export const parametersForTaskWorkspace = (parameters, state, scope) => {
    const result = new URLSearchParams(parameters);
    const normalizedScope = normalizeTaskScope(scope);
    const normalizedState = {
        search: String(state?.search || '').trim(),
        status: boardStatuses.has(String(state?.status || '')) ? String(state.status || '') : '',
        dueSort: dueSorts.has(String(state?.dueSort || '')) ? String(state.dueSort || '') : '',
    };

    if (normalizedScope === 'all') result.delete('task_scope');
    else result.set('task_scope', normalizedScope);

    for (const [key, value] of [
        ['search', normalizedState.search],
        ['status', normalizedState.status],
        ['due_sort', normalizedState.dueSort],
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
    const statusMatch = !status
        || (status === 'late' ? String(task.late) === '1' : String(task.status) === status);

    return textMatch && statusMatch;
};
