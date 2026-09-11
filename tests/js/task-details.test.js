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
                    <small data-task-details-progress class="is-pending">งานย่อย <b data-task-details-count>0</b>/<span data-task-details-total>0</span></small>
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

    /*
     * ตัวนับเป็น "เสร็จแล้ว/ทั้งหมด" เพราะงานแม่จะปิดได้ต่อเมื่องานย่อยเสร็จครบ
     * งานย่อยที่เพิ่งเพิ่มยังไม่เสร็จ ตัวหารจึงขึ้นเป็น 1 แต่ตัวเศษต้องยังเป็น 0
     */
    assert.equal(document.querySelector('[data-task-details-count]').textContent, '0');
    assert.equal(document.querySelector('[data-task-details-total]').textContent, '1');
    assert.ok(document.querySelector('[data-task-details-progress]').classList.contains('is-pending'));
    assert.equal(document.querySelector('[data-task-detail-title]').textContent, 'ซื้ออุปกรณ์');
    assert.equal(document.querySelector('[data-task-details-empty]').hidden, true);
});

test('task detail module keeps drag, project drop, editing, deletion and keyboard move controls wired', async () => {
    // หัวข้องานอยู่ใน task-details.blade.php ส่วนรายการงานย่อยอยู่ในแผงที่กินทั้งแถวบอร์ด
    const [javascript, blade, panel, row, css] = await Promise.all([
        read('resources/js/pages/mytasks/task-details.js'),
        read('resources/views/tasks/components/task-details.blade.php'),
        read('resources/views/tasks/components/task-details-panel.blade.php'),
        read('resources/views/tasks/components/task-detail-row.blade.php'),
        read('resources/css/pages/mytasks/task-details.css'),
    ]);

    assert.match(javascript, /addEventListener\('dragstart'/);
    assert.match(javascript, /addEventListener\('drop'/);
    assert.match(javascript, /data-detail-project-target/);
    assert.match(javascript, /target_work_order_id/);
    assert.match(javascript, /data-task-detail-edit/);
    assert.match(javascript, /data-task-detail-delete/);
    assert.match(row, /data-task-detail-move/);
    assert.match(blade, /aria-expanded="false"/);
    assert.match(css, /\.is-detail-drop-target/);
    assert.match(css, /@media \(max-width: 760px\)/);

    // แผงงานย่อยต้องกินทั้งแถวและใช้คอลัมน์ชุดเดียวกับ .board-reference-row
    // ไม่งั้นชิปทุกตัวจะถูกบีบอยู่ในคอลัมน์ "ชื่องาน"
    assert.match(css, /\.board-task-details__panel\s*\{[^}]*grid-column:\s*1 \/ -1/s);
    // งานย่อยต้องมีเส้นโยงกลับไปหาชื่องานแม่ ไม่ใช่แค่แถวที่ลอยอยู่ใต้กัน
    assert.match(css, /board-task-detail__name::before[^{]*\{[^}]*background:\s*#cbd7e6/s);
    assert.match(css, /board-task-detail__name::after[^{]*\{[^}]*height:\s*1px/s);
    assert.match(css, /board-task-detail:last-child > \.board-task-detail__name::before[^{]*\{[^}]*bottom:\s*50%/s);

    // คอลัมน์ของบอร์ดจัดเนื้อหากึ่งกลาง แถวงานย่อยจึงห้ามย่อขนาดตัวอักษรหรือกล่องของเซลล์
    // ไม่งั้นกล่องที่เล็กกว่าจะถูกวางกึ่งกลางคนละตำแหน่งจนดูเหมือนคอลัมน์ไม่ตรงกัน
    assert.doesNotMatch(css, /\.board-task-detail \.board-(status-pill|priority|start|due|attachments|comments|owner)/);

    // ช่องไฟล์แนบและคอมเมนต์ถูกล็อกไว้กับ "ท้ายแถว" (-4, -3) กฎนั้นต้องครอบแถวงานย่อยด้วย
    // นับถอยจากขอบขวาเพื่อให้การเพิ่มคอลัมน์กลางแถวไม่ดันสองช่องนี้ตกไปแถวที่สอง
    const board = await read('resources/css/pages/mytasks/project-board.css');
    assert.match(board, /\.board-task-detail > \.board-attachments\s*\{[^}]*grid-column:\s*-4/s);
    assert.match(board, /\.board-task-detail > \.board-comments\s*\{[^}]*grid-column:\s*-3/s);

    // คอลัมน์ต้องมาจากตัวแปรเดียวกับแถวงานแม่ ห้ามคัดลอกตัวเลขมาไว้ที่นี่อีก
    assert.match(css, /\.board-task-detail\s*\{[^}]*grid-template-columns:\s*var\(--board-columns\)/s);

    // งานย่อยต้องใช้ปุ่มควบคุมชุดเดียวกับแถวงานแม่ ไม่ใช่ชิปอ่านอย่างเดียวชุดใหม่
    assert.match(row, /data-board-status-value/);
    assert.match(row, /data-board-priority-value/);
    assert.match(row, /data-board-field="due"/);
    assert.match(row, /data-board-open-attachments/);
    assert.match(row, /data-task-tab="updates"/);
    // แถวงานย่อยเป็น [data-board-task] ของตัวเอง แต่ต้องถูกข้ามโดยโค้ดที่ไล่รายการงาน
    assert.match(row, /data-board-subtask="1"/);
    assert.match(javascript, /\[data-board-task\]:not\(\[data-board-subtask\]\)/);
});
