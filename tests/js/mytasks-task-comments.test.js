import test from 'node:test';
import assert from 'node:assert/strict';
import {canComposeComment, commentDeepLink, prependComment, shouldMarkCommentsRead, shouldSubmitOnEnter, unreadCountAfterRead, withoutTaskDeepLink} from '../../resources/js/pages/mytasks/task-comments-model.js';
import {click, pressKey} from './helpers/dom.js';
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


const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

/** ดักทุก fetch เพื่อพิสูจน์จำนวนครั้งจริง และคุมจังหวะ resolve เองสำหรับเคสกดรัว */
function captureFetch({defer = false} = {}) {
    const calls = [];
    let release = () => {};
    globalThis.fetch = (url, options = {}) => {
        calls.push({url, options});
        const payload = {
            ok: true,
            json: async () => ({unread_count: 0, comment: {id: 99, author: 'ผู้ทดสอบ', note: 'ข้อความ', at: '1 ม.ค. 2569 09:00'}}),
        };
        if (!defer) return Promise.resolve(payload);
        return new Promise((resolve) => { release = () => resolve(payload); });
    };

    return {
        calls,
        release: () => release(),
        commentPosts: () => calls.filter((call) => String(call.url).endsWith('/comments')),
    };
}

test('Enter สั่งส่ง ส่วน Shift+Enter และ IME ต้องไม่ส่ง', () => {
    assert.equal(shouldSubmitOnEnter({key: 'Enter'}), true);
    assert.equal(shouldSubmitOnEnter({key: 'Enter', shiftKey: true}), false);
    assert.equal(shouldSubmitOnEnter({key: 'Enter', isComposing: true}), false);
    assert.equal(shouldSubmitOnEnter({key: 'Enter', keyCode: 229}), false);
    assert.equal(shouldSubmitOnEnter({key: 'a'}), false);
    assert.equal(shouldSubmitOnEnter(), false);
});

test('การกดไอคอนคอมเมนต์ถือว่าอ่านแล้ว แต่การเปิดงานเฉย ๆ ยังไม่ถือว่าอ่าน', () => {
    assert.equal(shouldMarkCommentsRead('comment-icon', 'updates'), true);
    assert.equal(shouldMarkCommentsRead('comment-icon', 'activity'), false);
    assert.equal(shouldMarkCommentsRead('modal', 'updates'), false);
});

test('กดชื่องานบนบอร์ดเปิด Task Workspace ตัวเดิม', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board');

    assert.equal(ui.taskModal.hidden, true);
    click(ui.boardTitle());
    assert.equal(ui.taskModal.hidden, false);
});

test('กดไอคอนคอมเมนต์เปิดโมดัลตัวเดียวกันและเข้าแท็บอัปเดตทันที', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board');
    const fetches = captureFetch();

    click(ui.boardComment());
    await flush();

    assert.equal(ui.document.querySelectorAll('[data-task-modal]').length, 1);
    assert.equal(ui.taskModal.hidden, false);
    assert.equal(ui.taskModal.querySelector('[data-timeline-tab="updates"]').getAttribute('aria-selected'), 'true');
    assert.equal(fetches.calls.some((call) => String(call.url).endsWith('/comments/read')), true);
});

test('ไอคอนคอมเมนต์ถือ task id และ metadata ของ opener ครบ', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board', 7);
    const icon = ui.boardComment();

    assert.equal(icon.hasAttribute('data-open-task-modal'), true);
    assert.equal(icon.dataset.taskId, '7');
    assert.equal(icon.dataset.taskTab, 'updates');
    assert.equal(icon.dataset.unreadComments, '7');
});

test('เมื่ออ่านแล้ว ช่องคอมเมนต์ต้องยังอยู่ในกริดและจำนวนรวมไม่เปลี่ยน', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board');
    captureFetch();

    const before = ui.boardRow().children.length;
    assert.equal(ui.boardComment().classList.contains('has-unread'), true);

    click(ui.boardComment());
    await flush();

    const icon = ui.boardComment();
    assert.notEqual(icon, null);
    assert.equal(ui.boardRow().children.length, before);
    assert.equal(icon.classList.contains('has-unread'), false);
    assert.equal(icon.querySelector('strong').textContent, '2');
    assert.equal(icon.getAttribute('aria-label'), 'ดูคอมเมนต์ 2 รายการ');
});

test('Enter ในกล่องคอมเมนต์ส่งข้อความและล้างกล่อง', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board');
    click(ui.boardTitle());
    const fetches = captureFetch();

    ui.compose().value = 'ความคืบหน้าวันนี้';
    pressKey(ui.compose(), 'Enter');
    await flush();

    assert.equal(fetches.commentPosts().length, 1);
    assert.equal(JSON.parse(fetches.commentPosts()[0].options.body).message, 'ความคืบหน้าวันนี้');
    assert.equal(ui.compose().value, '');
});

test('Shift+Enter ขึ้นบรรทัดใหม่และต้องไม่ส่ง', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board');
    click(ui.boardTitle());
    const fetches = captureFetch();

    ui.compose().value = 'บรรทัดแรก';
    const event = pressKey(ui.compose(), 'Enter', {shiftKey: true});
    await flush();

    assert.equal(fetches.commentPosts().length, 0);
    assert.equal(ui.compose().value, 'บรรทัดแรก');
    if (event) assert.equal(event.defaultPrevented, false);
});

test('ข้อความว่างหรือมีแต่ช่องว่างกด Enter แล้วต้องไม่ส่ง', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board');
    click(ui.boardTitle());
    const fetches = captureFetch();

    ui.compose().value = '   \n  ';
    pressKey(ui.compose(), 'Enter');
    await flush();

    assert.equal(fetches.commentPosts().length, 0);
});

test('Enter ระหว่าง IME กำลังประกอบคำต้องไม่ส่ง', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board');
    click(ui.boardTitle());
    const fetches = captureFetch();

    ui.compose().value = 'กำลังพิมพ์';
    pressKey(ui.compose(), 'Enter', {isComposing: true});
    await flush();

    assert.equal(fetches.commentPosts().length, 0);
    assert.equal(ui.compose().value, 'กำลังพิมพ์');
});

test('กด Enter รัว ๆ ระหว่างรอ response ต้องไม่เกิดคอมเมนต์ซ้ำ', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board');
    click(ui.boardTitle());
    const fetches = captureFetch({defer: true});

    ui.compose().value = 'ส่งครั้งเดียว';
    pressKey(ui.compose(), 'Enter');
    pressKey(ui.compose(), 'Enter');
    pressKey(ui.compose(), 'Enter');
    await flush();

    assert.equal(fetches.commentPosts().length, 1);
    fetches.release();
    await flush();
});

test('ปุ่มส่งเดิมยังทำงานได้ตามปกติ', async (t) => {
    const ui = await bootTimeline(t, 'http://localhost/my-tasks?view=board');
    click(ui.boardTitle());
    const fetches = captureFetch();

    ui.compose().value = 'ส่งด้วยปุ่ม';
    click(ui.sendUpdate());
    await flush();

    assert.equal(fetches.commentPosts().length, 1);
    assert.equal(ui.compose().value, '');
});
