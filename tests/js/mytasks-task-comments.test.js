import test from 'node:test';
import assert from 'node:assert/strict';
import {canComposeComment, commentDeepLink, prependComment, shouldMarkCommentsRead, unreadCountAfterRead, withoutTaskDeepLink} from '../../resources/js/pages/mytasks/task-comments-model.js';
import {click} from './helpers/dom.js';
import {mountTaskWorkspace} from './helpers/task-workspace-fixture.js';

let timelineFixture = 0;

async function bootTimeline(t, url, taskId = 7) {
    const ui = await mountTaskWorkspace({taskId}, {url});
    t.after(ui.cleanup);
    timelineFixture += 1;
    await import(`../../resources/js/pages/mytasks/task-timeline.js?fixture=${timelineFixture}`);

    return ui;
}

test('opening a modal alone does not mark comments read but selecting Updates does', () => {
    assert.equal(shouldMarkCommentsRead('modal', 'updates'), false);
    assert.equal(shouldMarkCommentsRead('tab', 'updates'), true);
    assert.equal(shouldMarkCommentsRead('tab', 'activity'), false);
});

test('notification deep link opens the requested task on Updates', () => {
    assert.deepEqual(commentDeepLink('?open_task=42&task_tab=updates'), {taskId: '42', tab: 'updates'});
    assert.equal(shouldMarkCommentsRead('deep-link', 'updates'), true);
});

test('consuming a task deep link removes only its keys and preserves admin path, filters, and hash', () => {
    assert.equal(
        withoutTaskDeepLink('https://example.test/admin/work-board/3/member/9?view=calendar&status=late&search=alpha&page=2&open_task=7&task_tab=updates#schedule'),
        '/admin/work-board/3/member/9?view=calendar&status=late&search=alpha&page=2#schedule',
    );
});

test('valid deep link opens once, consumes URL state, and refresh state stays closed', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board&status=late&search=alpha&open_task=7&task_tab=updates#tasks');

    assert.equal(ui.taskModal.hidden, false);
    assert.equal(ui.document.querySelector('[data-workspace-title-text]').textContent.length > 0, true);
    assert.equal(ui.window.location.pathname, '/my-tasks');
    assert.equal(ui.window.location.search, '?view=board&status=late&search=alpha');
    assert.equal(ui.window.location.hash, '#tasks');

    click(ui.taskModal.querySelector('[data-close-task]'));
    assert.equal(ui.taskModal.hidden, true);

    timelineFixture += 1;
    await import(`../../resources/js/pages/mytasks/task-timeline.js?fixture=${timelineFixture}`);
    assert.equal(ui.taskModal.hidden, true);
});

test('missing or inaccessible deep-link target does not open another task', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=table&open_task=999&task_tab=updates');

    assert.equal(ui.taskModal.hidden, true);
    assert.equal(ui.window.location.search, '?view=table&open_task=999&task_tab=updates');
});

test('normal page load stays closed and a real task click still opens Workspace', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=table&search=alpha');

    assert.equal(ui.taskModal.hidden, true);
    click(ui.openTask());
    assert.equal(ui.taskModal.hidden, false);
});

test('a returned comment is rendered from the front of the Updates collection', () => {
    const timeline = {'42': {updates: [{id: 1, note: 'old'}], activity: []}};
    assert.deepEqual(prependComment(timeline, 42, {id: 2, note: 'new'}).map((item) => item.id), [2, 1]);
});

test('successful read state clears the task unread badge count', () => {
    assert.equal(unreadCountAfterRead(5), 0);
});

test('comment composer requires both policy permission and an actionable URL', () => {
    assert.equal(canComposeComment({can_comment: true, comment_url: '/tasks/42/comments'}), true);
    assert.equal(canComposeComment({can_comment: false, comment_url: null}), false);
    assert.equal(canComposeComment({can_comment: true, comment_url: null}), false);
    assert.equal(canComposeComment(), false);
});
