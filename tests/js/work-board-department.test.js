import test from 'node:test';
import assert from 'node:assert/strict';
import {initializeDepartmentWorkBoard} from '../../resources/js/pages/work-board/department.js';
import {click, mountDom, pressKey} from './helpers/dom.js';

function markup() {
    return `<!doctype html><html><body>
        <div id="outside">untouched</div>
        <div data-work-board-directory>
            <button type="button" data-member-preview-trigger data-preview-url="/work-board/departments/1/members/10" data-member-name="Member A">A</button>
            <button type="button" data-member-preview-trigger data-preview-url="/work-board/departments/1/members/20" data-member-name="Member B">B</button>
            <aside data-member-preview-panel aria-busy="false">
                <span data-preview-header-avatar hidden></span>
                <h2 data-preview-panel-title>งานของสมาชิก</h2>
                <div data-preview-header-meta hidden></div>
                <div data-preview-header-action hidden></div>
                <div data-preview-loading hidden>loading</div>
                <div data-preview-error hidden><button type="button" data-preview-retry>retry</button></div>
                <div data-preview-body hidden></div>
            </aside>
        </div>
    </body></html>`;
}

const response = (html, ok = true, status = 200) => ({
    ok,
    status,
    async text() {
        return html;
    },
});

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

test('member trigger requests the correct preview and renders User tasks without links', async (t) => {
    const dom = mountDom(markup());
    t.after(dom.cleanup);
    const calls = [];
    const fetch = async (url, options) => {
        calls.push({url, options});
        return response('<article data-preview-task>User task</article><div data-preview-empty></div>');
    };
    initializeDepartmentWorkBoard(dom.document, {fetch});

    const trigger = dom.document.querySelector('[data-member-name="Member A"]');
    click(trigger);

    assert.equal(dom.document.querySelector('[data-preview-loading]').hidden, false);
    assert.equal(dom.document.querySelector('[data-member-preview-panel]').getAttribute('aria-busy'), 'true');
    await tick();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/work-board/departments/1/members/10');
    assert.equal(calls[0].options.headers.Accept, 'text/html');
    // ส่วนหัวแสดงชื่อสมาชิกตรง ๆ คำว่า "ภาพรวมงานสมาชิก" อยู่ที่ป้ายเล็กด้านบนแล้ว
    assert.equal(dom.document.querySelector('[data-preview-panel-title]').textContent, 'Member A');
    assert.equal(dom.document.querySelector('[data-preview-body]').hidden, false);
    assert.equal(dom.document.querySelector('[data-preview-task]').tagName, 'ARTICLE');
    assert.equal(dom.document.querySelector('[data-preview-task-link]'), null);
    assert.equal(dom.document.querySelector('#outside').textContent, 'untouched');
});

test('Admin task remains clickable only when the backend response supplies a link', async (t) => {
    const dom = mountDom(markup());
    t.after(dom.cleanup);
    initializeDepartmentWorkBoard(dom.document, {
        fetch: async () => response('<a data-preview-task data-preview-task-link href="/admin/work-board/departments/1/members/10?open_task=77">Admin task</a>'),
    });

    click(dom.document.querySelector('[data-member-name="Member A"]'));
    await tick();

    const link = dom.document.querySelector('[data-preview-task-link]');
    assert.equal(link.tagName, 'A');
    assert.equal(link.getAttribute('href'), '/admin/work-board/departments/1/members/10?open_task=77');
});

test('a stale member response cannot overwrite the newly selected member', async (t) => {
    const dom = mountDom(markup());
    t.after(dom.cleanup);
    const pending = [];
    initializeDepartmentWorkBoard(dom.document, {
        fetch: (url) => new Promise((resolve) => pending.push({url, resolve})),
    });

    click(dom.document.querySelector('[data-member-name="Member A"]'));
    click(dom.document.querySelector('[data-member-name="Member B"]'));

    pending[1].resolve(response('<article data-preview-task>Member B task</article>'));
    await tick();
    assert.match(dom.document.querySelector('[data-preview-body]').textContent, /Member B task/);

    pending[0].resolve(response('<article data-preview-task>Member A stale task</article>'));
    await tick();
    assert.match(dom.document.querySelector('[data-preview-body]').textContent, /Member B task/);
    assert.doesNotMatch(dom.document.querySelector('[data-preview-body]').textContent, /stale/);
});

test('failed preview shows retry and a retry can render the empty state', async (t) => {
    const dom = mountDom(markup());
    t.after(dom.cleanup);
    let attempts = 0;
    initializeDepartmentWorkBoard(dom.document, {
        fetch: async () => {
            attempts += 1;
            return attempts === 1
                ? response('', false, 500)
                : response('<div data-preview-empty>No assigned tasks</div>');
        },
    });

    click(dom.document.querySelector('[data-member-name="Member A"]'));
    await tick();
    assert.equal(dom.document.querySelector('[data-preview-error]').hidden, false);

    click(dom.document.querySelector('[data-preview-retry]'));
    await tick();
    assert.equal(attempts, 2);
    assert.equal(dom.document.querySelector('[data-preview-empty]').textContent, 'No assigned tasks');
    assert.equal(dom.document.querySelector('[data-preview-error]').hidden, true);
});

test('closing the offcanvas aborts work and restores focus to the last trigger', async (t) => {
    const dom = mountDom(markup());
    t.after(dom.cleanup);
    initializeDepartmentWorkBoard(dom.document, {
        fetch: async () => response('<div data-preview-empty></div>'),
    });

    const trigger = dom.document.querySelector('[data-member-name="Member B"]');
    click(trigger);
    await tick();
    dom.document.querySelector('[data-member-preview-panel]').dispatchEvent(
        new dom.window.CustomEvent('hidden.bs.offcanvas', {bubbles: true}),
    );

    assert.equal(dom.document.activeElement, trigger);
});

const headerTemplate = (name, role) => `<template data-preview-header data-member-name="${name}">
    <span data-preview-header-avatar><span class="wb-avatar">${name[0]}</span></span>
    <div data-preview-header-meta><span data-role>${role}</span></div>
</template><article data-preview-task>${name} task</article>`;

test('the server header moves into the panel header once and is cleared before the next member loads', async (t) => {
    const dom = mountDom(markup());
    t.after(dom.cleanup);
    const pending = [];
    initializeDepartmentWorkBoard(dom.document, {
        fetch: () => new Promise((resolve) => pending.push(resolve)),
    });
    const avatar = dom.document.querySelector('[data-preview-header-avatar]');
    const meta = dom.document.querySelector('[data-preview-header-meta]');
    const body = dom.document.querySelector('[data-preview-body]');

    click(dom.document.querySelector('[data-member-name="Member A"]'));
    pending[0](response(headerTemplate('Member A', 'หัวหน้าแผนก')));
    await tick();

    assert.equal(avatar.hidden, false);
    assert.equal(meta.hidden, false);
    assert.equal(meta.querySelector('[data-role]').textContent, 'หัวหน้าแผนก');
    assert.equal(avatar.querySelector('.wb-avatar').textContent, 'M');
    // ย้ายมา ไม่ใช่คัดลอก — เนื้อหาที่เลื่อนได้ต้องไม่มีส่วนหัวซ้ำอีกชุด
    assert.equal(body.querySelector('template[data-preview-header]'), null);
    assert.match(body.textContent, /Member A task/);

    // ระหว่างโหลดคนถัดไป ข้อมูลของคนก่อนต้องหายไปจากส่วนหัวทันที
    click(dom.document.querySelector('[data-member-name="Member B"]'));
    assert.equal(avatar.hidden, true);
    assert.equal(meta.hidden, true);
    assert.equal(meta.textContent.trim(), '');
    assert.equal(dom.document.querySelector('[data-preview-panel-title]').textContent, 'Member B');

    pending[1](response(headerTemplate('Member B', 'พนักงาน')));
    await tick();
    assert.equal(meta.querySelector('[data-role]').textContent, 'พนักงาน');
    assert.equal(meta.querySelectorAll('[data-role]').length, 1, 'ส่วนหัวต้องไม่สะสมของคนก่อนไว้');
});

test('a response without a header template keeps the header slots hidden', async (t) => {
    const dom = mountDom(markup());
    t.after(dom.cleanup);
    initializeDepartmentWorkBoard(dom.document, {
        fetch: async () => response('<div data-preview-empty>No assigned tasks</div>'),
    });

    click(dom.document.querySelector('[data-member-name="Member A"]'));
    await tick();

    assert.equal(dom.document.querySelector('[data-preview-header-avatar]').hidden, true);
    assert.equal(dom.document.querySelector('[data-preview-header-meta]').hidden, true);
    assert.equal(dom.document.querySelector('[data-preview-panel-title]').textContent, 'Member A');
});

const tabbedPreview = `<template data-preview-header data-member-name="Member A">
    <span data-preview-header-avatar><span class="wb-avatar">M</span></span>
    <div data-preview-header-meta><span>พนักงาน</span></div>
    <div data-preview-header-action><a data-preview-workspace-link href="/ws">open</a></div>
</template>
<div role="tablist">
    <button type="button" role="tab" data-preview-tab="today" aria-selected="true" tabindex="0">today</button>
    <button type="button" role="tab" data-preview-tab="all" aria-selected="false" tabindex="-1">all</button>
    <button type="button" role="tab" data-preview-tab="created" aria-selected="false" tabindex="-1">created</button>
</div>
<div data-preview-tabpanel="today">T</div>
<div data-preview-tabpanel="all" hidden>A</div>
<div data-preview-tabpanel="created" hidden>C</div>`;

test('the workspace button moves into the header action slot only when the server sends it', async (t) => {
    const dom = mountDom(markup());
    t.after(dom.cleanup);
    const responses = [response(tabbedPreview), response('<div data-preview-empty></div>')];
    initializeDepartmentWorkBoard(dom.document, {fetch: async () => responses.shift()});
    const action = dom.document.querySelector('[data-preview-header-action]');

    click(dom.document.querySelector('[data-member-name="Member A"]'));
    await tick();
    assert.equal(action.hidden, false);
    assert.equal(action.querySelector('[data-preview-workspace-link]').getAttribute('href'), '/ws');

    // คนถัดไปที่ผู้ดูไม่มีสิทธิ์เปิดพื้นที่งาน ปุ่มของคนก่อนต้องไม่ค้างอยู่
    click(dom.document.querySelector('[data-member-name="Member B"]'));
    await tick();
    assert.equal(action.hidden, true);
    assert.equal(action.querySelector('[data-preview-workspace-link]'), null);
});

test('preview tabs switch panels on click and with arrow keys', async (t) => {
    const dom = mountDom(markup());
    t.after(dom.cleanup);
    initializeDepartmentWorkBoard(dom.document, {fetch: async () => response(tabbedPreview)});

    click(dom.document.querySelector('[data-member-name="Member A"]'));
    await tick();
    const tab = (key) => dom.document.querySelector(`[data-preview-tab="${key}"]`);
    const panel = (key) => dom.document.querySelector(`[data-preview-tabpanel="${key}"]`);

    click(tab('all'));
    assert.equal(panel('all').hidden, false);
    assert.equal(panel('today').hidden, true);
    assert.equal(tab('all').getAttribute('aria-selected'), 'true');
    assert.equal(tab('today').getAttribute('aria-selected'), 'false');
    assert.equal(tab('all').tabIndex, 0);

    pressKey(tab('all'), 'ArrowRight');
    assert.equal(panel('created').hidden, false);
    assert.equal(dom.document.activeElement, tab('created'));

    pressKey(tab('created'), 'ArrowRight');
    assert.equal(panel('today').hidden, false, 'ลูกศรขวาที่แท็บสุดท้ายวนกลับแท็บแรก');

    pressKey(tab('today'), 'End');
    assert.equal(panel('created').hidden, false);
    pressKey(tab('created'), 'Home');
    assert.equal(panel('today').hidden, false);
    assert.equal(panel('all').hidden, true);
});
