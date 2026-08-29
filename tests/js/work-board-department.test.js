import test from 'node:test';
import assert from 'node:assert/strict';
import {initializeDepartmentWorkBoard} from '../../resources/js/pages/work-board/department.js';
import {click, mountDom} from './helpers/dom.js';

function markup() {
    return `<!doctype html><html><body>
        <div id="outside">untouched</div>
        <div data-work-board-directory>
            <button type="button" data-member-preview-trigger data-preview-url="/work-board/departments/1/members/10" data-member-name="Member A">A</button>
            <button type="button" data-member-preview-trigger data-preview-url="/work-board/departments/1/members/20" data-member-name="Member B">B</button>
            <aside data-member-preview-panel aria-busy="false">
                <h2 data-preview-panel-title>งานของสมาชิก</h2>
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
    assert.equal(dom.document.querySelector('[data-preview-panel-title]').textContent, 'งานของ Member A');
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
