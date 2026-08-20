import test from 'node:test';
import assert from 'node:assert/strict';
import {
    boardFilterStateFrom,
    boardTaskMatches,
    normalizeTaskScope,
    parametersForTaskWorkspace,
} from '../../resources/js/pages/mytasks/task-filter-state.js';

test('task scope normalization only accepts the five supported values', () => {
    assert.equal(normalizeTaskScope('assigned_by_me'), 'assigned_by_me');
    assert.equal(normalizeTaskScope('collaborating'), 'collaborating');
    assert.equal(normalizeTaskScope('someone_else'), 'all');
});

test('board filter state restores valid URL values and rejects invalid state', () => {
    assert.deepEqual(
        boardFilterStateFrom(new URLSearchParams('search=Printer&status=late&due_sort=desc')),
        {search: 'Printer', status: 'late', dueSort: 'desc'},
    );
    assert.deepEqual(
        boardFilterStateFrom(new URLSearchParams('status=99&due_sort=random')),
        {search: '', status: '', dueSort: ''},
    );
});

test('workspace parameters combine scope search status and due sorting', () => {
    const parameters = parametersForTaskWorkspace(
        new URLSearchParams('open_task=42'),
        {search: 'Printer', status: '6', dueSort: 'asc'},
        'assigned_by_me',
    );

    assert.equal(parameters.get('task_scope'), 'assigned_by_me');
    assert.equal(parameters.get('search'), 'Printer');
    assert.equal(parameters.has('status'), false);
    assert.equal(parameters.get('due_sort'), 'asc');
    assert.equal(parameters.get('open_task'), '42');

    const defaults = parametersForTaskWorkspace(parameters, {search: '', status: '', dueSort: ''}, 'all');
    assert.equal(defaults.has('task_scope'), false);
    assert.equal(defaults.has('search'), false);
    assert.equal(defaults.has('due_sort'), false);
});

test('board matching combines text and status including late semantics', () => {
    const task = {searchable: 'Hardware Printer User B', status: '2', late: '1'};

    assert.equal(boardTaskMatches(task, {search: 'printer', status: ''}), true);
    assert.equal(boardTaskMatches(task, {search: 'printer', status: 'late'}), true);
    assert.equal(boardTaskMatches(task, {search: 'printer', status: '3'}), false);
    assert.equal(boardTaskMatches(task, {search: 'scanner', status: 'late'}), false);
});
