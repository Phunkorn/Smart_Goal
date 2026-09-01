import assert from 'node:assert/strict';
import test from 'node:test';
import {JSDOM} from 'jsdom';
import {applyRealtimePayload, initializeRealtimeSync, updateNotificationCount} from '../../resources/js/components/realtime-sync.js';

function dom() {
    const ui = new JSDOM(`<!doctype html><body data-realtime-sync-url="/realtime/sync" data-realtime-cursor="10">
        <span data-notification-count hidden></span>
        <span data-notification-summary class="gray">0 รายการ</span>
        <div data-notification-dropdown-list></div>
        <div data-notification-dropdown-empty>ไม่มีการแจ้งเตือน</div>
    </body>`, {url: 'http://localhost/my-tasks'});
    Object.defineProperty(ui.window.document, 'hidden', {configurable: true, value: false});
    return ui;
}

test('payload updates badges, dropdown, toast and emits a page event once', () => {
    const ui = dom();
    const received = [];
    ui.window.document.addEventListener('smartgoal:realtime-notification', (event) => received.push(event.detail));
    const payload = {unread_count: 3, events: [{
        id: 11, category: 'comment', title: 'ความคิดเห็นใหม่', message: 'มีข้อความใหม่',
        url: '/notifications/11/open', task_id: 7,
    }]};
    const seen = new Set();

    applyRealtimePayload(ui.window.document, payload, seen);
    applyRealtimePayload(ui.window.document, payload, seen);

    const badge = ui.window.document.querySelector('[data-notification-count]');
    assert.equal(badge.hidden, false);
    assert.equal(badge.textContent, '3');
    assert.equal(ui.window.document.querySelectorAll('[data-dropdown-notification-id="11"]').length, 1);
    assert.equal(ui.window.document.querySelectorAll('.realtime-toast').length, 1);
    assert.equal(received.length, 1);
});

test('payload emits comment read receipts even when there are no new notifications', () => {
    const ui = dom();
    const received = [];
    ui.window.document.addEventListener('smartgoal:comment-receipts', (event) => received.push(event.detail));

    applyRealtimePayload(ui.window.document, {
        unread_count: 0,
        events: [],
        comment_receipts: {task_id: 7, receipts: {'91': [{id: 2, name: 'Admin'}]}},
    });

    assert.deepEqual(received, [{task_id: 7, receipts: {'91': [{id: 2, name: 'Admin'}]}}]);
});

test('zero unread notifications hide every persistent badge', () => {
    const ui = dom();
    const badge = ui.window.document.querySelector('[data-notification-count]');
    badge.hidden = false;
    updateNotificationCount(ui.window.document, 0);
    assert.equal(badge.hidden, true);
    assert.equal(ui.window.document.querySelector('[data-notification-summary]').textContent, '0 รายการ');
});

test('poll uses the current cursor and advances it from the response', async () => {
    const ui = dom();
    ui.window.document.body.dataset.realtimeTaskId = '7';
    const calls = [];
    const timers = [];
    const client = initializeRealtimeSync(
        ui.window.document,
        async (url) => {
            calls.push(String(url));
            return {ok: true, status: 200, json: async () => ({cursor: 12, unread_count: 0, events: [], has_more: false})};
        },
        (callback) => { timers.push(callback); return timers.length; },
        () => {},
    );

    await client.poll();
    assert.match(calls[0], /after=10/);
    assert.match(calls[0], /task_id=7/);
    assert.equal(ui.window.document.body.dataset.realtimeCursor, '12');
    client.stop();
});

test('a hidden tab does not poll until it becomes visible again', () => {
    const ui = dom();
    Object.defineProperty(ui.window.document, 'hidden', {configurable: true, value: true});
    const timers = [];
    const client = initializeRealtimeSync(
        ui.window.document,
        async () => ({ok: true, status: 200, json: async () => ({cursor: 10, unread_count: 0, events: []})}),
        (callback) => { timers.push(callback); return timers.length; },
        () => {},
    );
    assert.equal(timers.length, 0);

    Object.defineProperty(ui.window.document, 'hidden', {configurable: true, value: false});
    ui.window.document.dispatchEvent(new ui.window.Event('visibilitychange'));
    assert.equal(timers.length, 1);
    client.stop();
});

test('opening a live comment panel requests an immediate sync', () => {
    const ui = dom();
    const timers = [];
    const client = initializeRealtimeSync(
        ui.window.document,
        async () => ({ok: true, status: 200, json: async () => ({cursor: 10, unread_count: 0, events: []})}),
        (callback, wait) => { timers.push({callback, wait}); return timers.length; },
        () => {},
    );

    assert.equal(timers[0].wait, 3000);
    ui.window.document.dispatchEvent(new ui.window.CustomEvent('smartgoal:realtime-refresh'));
    assert.equal(timers.at(-1).wait, 0);
    client.stop();
});
