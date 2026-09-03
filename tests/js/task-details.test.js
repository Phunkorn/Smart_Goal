import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {JSDOM} from 'jsdom';

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

test('task details expand and a new detail is added without reloading the board', async () => {
    const dom = new JSDOM(`
        <meta name="csrf-token" content="token">
        <div data-toast></div>
        <section data-project-board>
            <article data-board-task data-task-id="12" data-detail-target="1" data-project-key="project-1" data-project-name="Project" data-topic="Task">
                <div data-task-details data-work-order-id="12">
                    <button type="button" data-task-details-toggle aria-expanded="false"></button>
                    <div data-task-details-panel hidden>
                        <ol data-task-details-list></ol>
                        <p data-task-details-empty>ยังไม่มีรายละเอียดงาน</p>
                        <form data-task-detail-create data-url="/my-tasks/12/details">
                            <input name="title" value="ซื้ออุปกรณ์">
                            <button type="submit">เพิ่ม</button>
                        </form>
                    </div>
                    <b data-task-details-count>0</b>
                </div>
            </article>
        </section>
    `, {url: 'http://localhost/my-tasks?view=board'});

    globalThis.window = dom.window;
    globalThis.document = dom.window.document;
    globalThis.Swal = {fire: async () => ({isConfirmed: false})};
    globalThis.fetch = async () => ({
        ok: true,
        json: async () => ({
            message: 'เพิ่มรายละเอียดงานแล้ว',
            detail: {
                id: 9,
                work_order_id: 12,
                title: 'ซื้ออุปกรณ์',
                update_url: '/details/9',
                delete_url: '/details/9',
                move_url: '/details/9/move',
            },
        }),
    });

    await import(`../../resources/js/pages/mytasks/task-details.js?test=${Date.now()}`);

    const toggle = document.querySelector('[data-task-details-toggle]');
    const panel = document.querySelector('[data-task-details-panel]');
    toggle.click();
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(panel.hidden, false);

    document.querySelector('[data-task-detail-create]').dispatchEvent(new dom.window.Event('submit', {
        bubbles: true,
        cancelable: true,
    }));
    await new Promise((resolve) => dom.window.setTimeout(resolve, 0));

    assert.equal(document.querySelector('[data-task-details-count]').textContent, '1');
    assert.equal(document.querySelector('[data-task-detail-title]').textContent, 'ซื้ออุปกรณ์');
    assert.equal(document.querySelector('[data-task-details-empty]').hidden, true);
});

test('task detail module keeps drag, project drop, editing, deletion and keyboard move controls wired', async () => {
    const [javascript, blade, css] = await Promise.all([
        read('resources/js/pages/mytasks/task-details.js'),
        read('resources/views/tasks/components/task-details.blade.php'),
        read('resources/css/pages/mytasks/task-details.css'),
    ]);

    assert.match(javascript, /addEventListener\('dragstart'/);
    assert.match(javascript, /addEventListener\('drop'/);
    assert.match(javascript, /data-detail-project-target/);
    assert.match(javascript, /target_work_order_id/);
    assert.match(javascript, /data-task-detail-edit/);
    assert.match(javascript, /data-task-detail-delete/);
    assert.match(blade, /data-task-detail-move/);
    assert.match(blade, /aria-expanded="false"/);
    assert.match(css, /\.is-detail-drop-target/);
    assert.match(css, /@media \(max-width: 760px\)/);
});
