import test from 'node:test';
import assert from 'node:assert/strict';
import {commentDeepLink, prependComment, shouldMarkCommentsRead, unreadCountAfterRead} from '../../resources/js/pages/mytasks/task-comments-model.js';

test('opening a modal alone does not mark comments read but selecting Updates does', () => {
    assert.equal(shouldMarkCommentsRead('modal', 'updates'), false);
    assert.equal(shouldMarkCommentsRead('tab', 'updates'), true);
    assert.equal(shouldMarkCommentsRead('tab', 'activity'), false);
});

test('notification deep link opens the requested task on Updates', () => {
    assert.deepEqual(commentDeepLink('?open_task=42&task_tab=updates'), {taskId: '42', tab: 'updates'});
    assert.equal(shouldMarkCommentsRead('deep-link', 'updates'), true);
});

test('a returned comment is rendered from the front of the Updates collection', () => {
    const timeline = {'42': {updates: [{id: 1, note: 'old'}], activity: []}};
    assert.deepEqual(prependComment(timeline, 42, {id: 2, note: 'new'}).map((item) => item.id), [2, 1]);
});

test('successful read state clears the task unread badge count', () => {
    assert.equal(unreadCountAfterRead(5), 0);
});
