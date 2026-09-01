import test from 'node:test';
import assert from 'node:assert/strict';
import {canDragTask, canTransitionTo, isModalStatusOptionDisabled} from '../../resources/js/pages/mytasks/task-transitions.js';

test('server allowed_statuses controls every status surface', () => {
    const worker = {can_edit: true, is_final: false, allowed_statuses: [2, 5]};

    assert.equal(canTransitionTo(2, 5, worker), true);
    assert.equal(canTransitionTo(2, 1, worker), false);
    assert.equal(isModalStatusOptionDisabled(2, 5, worker), false);
    assert.equal(isModalStatusOptionDisabled(2, 1, worker), true);
});

test('kanban only drags a worker that has a real destination', () => {
    assert.equal(canDragTask(2, {can_edit: true, is_final: false, allowed_statuses: [2, 5]}), true);
    assert.equal(canDragTask(2, {can_edit: false, is_final: false, allowed_statuses: [2]}), false);
    assert.equal(canDragTask(4, {can_edit: true, is_final: true, allowed_statuses: [4]}), false);
    assert.equal(canDragTask(3, {can_edit: true, is_final: false, allowed_statuses: [3]}), false);
});
