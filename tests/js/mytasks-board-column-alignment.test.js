import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

/** นับ track ของ grid โดยไม่นับช่องว่างที่อยู่ในวงเล็บ เช่น minmax(0, 1fr) คือหนึ่ง track */
const tracksOf = (value) => {
    let depth = 0;
    let current = '';
    const tracks = [];

    for (const character of value.trim()) {
        if (character === '(') depth += 1;
        if (character === ')') depth -= 1;
        if (depth === 0 && /\s/.test(character)) {
            if (current) tracks.push(current);
            current = '';
            continue;
        }
        current += character;
    }
    if (current) tracks.push(current);

    return tracks;
};

/** ความกว้างสูงสุดของ track หนึ่ง ๆ เป็น px — minmax(0, 116px) คือ 116 */
const widthOf = (track) => Number(track.match(/(\d+)px\)?$/)?.[1] ?? 0);

/*
 * แถวของบอร์ด (data-view="board") เป็น grid ที่ใช้ track ชุดเดียวกับหัวตาราง
 * ถ้าช่องใดจัดแนวไม่ตรงกับหัวตาราง หรือแคบกว่าเนื้อหาที่ตัวเองวาด
 * ค่าจะไปโผล่ใต้ชื่อคอลัมน์อื่น หรือยื่นออกไปทับคอลัมน์ถัดไป
 */

test('read-only status and priority sit in the same place as the editable menus', async () => {
    const css = await read('resources/css/pages/mytasks/project-board.css');
    const desktop = css.slice(css.indexOf('@media (min-width: 761px)'));

    // งานที่แก้ไขได้ใช้ <details class="board-status-menu"> ซึ่งถูกจัดกึ่งกลางอยู่แล้ว
    assert.match(css, /\.board-status-menu,[\s\S]{0,120}?\.board-priority-menu \{[^}]*justify-self: center/);

    // งานแบบอ่านอย่างเดียวใช้ <span> เปล่า ๆ จึงต้องถูกสั่งให้จัดกึ่งกลางเหมือนกัน
    for (const cell of ['board-status-pill', 'board-priority', 'board-attachments', 'board-comments']) {
        assert.ok(
            desktop.includes('.my-tasks-page .board-reference-row > .' + cell),
            cell + ' ต้องถูกจัดแนวให้ตรงกับหัวคอลัมน์ของตัวเอง',
        );
    }
    assert.match(desktop, /justify-self: center;/);
});

test('the collaborator column is wide enough that avatars never cover the paperclip', async () => {
    const css = await read('resources/css/pages/mytasks/project-board.css');

    /*
     * เนื้อหาที่กว้างที่สุดของช่องผู้ร่วมงาน: ปุ่ม padding-left 7px + avatar 27px สองใบ
     * และป้าย "+N" อีกใบ (แต่ละใบ margin-left -7px จึงกินเพิ่มใบละ 20px)
     * บวกปุ่มจัดการผู้ร่วมงานอีก 27px ที่ margin-left 2px
     * รวม 96px และบวก padding ซ้ายขวาของช่องอีก 16px เป็น 112px
     */
    const required = 7 + 20 * 3 + (2 + 27) + 16;

    const boardRules = [...css.matchAll(/\[data-view="board"\][^{]*\{([^}]*)\}/g)]
        .flatMap(([, body]) => [...body.matchAll(/grid-template-columns:([^;]+)/g)].map(([, value]) => tracksOf(value)));

    // กฎของจอแคบยุบเหลือสองคอลัมน์แบบการ์ด จึงดูเฉพาะกฎที่ยังเป็นตารางสิบคอลัมน์
    const tenColumnRules = boardRules.filter((tracks) => tracks.length === 10);
    assert.ok(tenColumnRules.length >= 2, 'ต้องมีกฎกริดของมุมมองบอร์ดทั้งตอน Sidebar ย่อและกาง');

    for (const tracks of tenColumnRules) {

        const collaborators = widthOf(tracks[6]);
        const attachments = widthOf(tracks[7]);

        assert.ok(
            collaborators >= required,
            'คอลัมน์ผู้ร่วมงานกว้าง ' + collaborators + 'px แต่เนื้อหาต้องการ ' + required + 'px จึงล้นไปทับคอลัมน์ไฟล์แนบ',
        );
        assert.ok(attachments > 0, 'คอลัมน์ไฟล์แนบต้องมีความกว้างของตัวเอง');
    }
});

test('the detail input stays a one-line field instead of stretching the whole column', async () => {
    const css = await read('resources/css/pages/mytasks/task-details.css');

    // ยังหดได้ตามคอลัมน์ (ขอบล่างของ minmax เป็น 0) แต่ต้องมีเพดานความกว้าง
    const cap = Number(css.match(/grid-template-columns: minmax\(0, (\d+)px\) auto/)?.[1]);
    assert.ok(Number.isFinite(cap), 'ช่องเพิ่มรายละเอียดต้องมีเพดานความกว้าง ไม่ใช่ยืดเต็มคอลัมน์');
    assert.ok(cap > 0 && cap <= 420, 'ช่องเพิ่มรายละเอียดกว้างได้ถึง ' + cap + 'px ซึ่งยังยาวเกินไป');

    // จอแคบไม่มีคอลัมน์ให้ยืดอยู่แล้ว จึงกลับไปใช้เต็มความกว้างของการ์ด
    const mobile = css.slice(css.lastIndexOf('@media (max-width: 760px)'));
    assert.match(mobile, /\.board-task-details__create \{[^}]*grid-template-columns: minmax\(0, 1fr\) auto/s);
});

test('column headings never wrap, and every cell stays level with the task title', async () => {
    const css = await read('resources/css/pages/mytasks/project-board.css');
    const desktop = css.slice(css.indexOf('@media (min-width: 761px)'));

    /*
     * ชื่อคอลัมน์เป็นคำไทยคำเดียว เบราว์เซอร์ตัดบรรทัดกลางคำได้ตามกฎการตัดคำไทย
     * "คอมเมนต์" จึงเคยกลายเป็นสองบรรทัดเมื่อคอลัมน์แคบกว่าความยาวคำเพียงไม่กี่พิกเซล
     */
    assert.match(desktop, /\.board-reference-columns > span \{[^}]*white-space: nowrap/s);

    /*
     * แถวต้องยึดขอบบน ไม่ใช่กึ่งกลาง
     *
     * ช่องแรกสูงขึ้นตามจำนวนรายละเอียดงานที่กางออก ถ้าแถวยังจัดกึ่งกลาง
     * ช่องที่เหลือจะไหลลงตามความสูงใหม่ทุกครั้งที่เพิ่มรายละเอียดอีกหนึ่งรายการ
     * จนไม่ตรงกับชื่องานที่อยู่บรรทัดบนสุดอีกต่อไป
     */
    assert.match(desktop, /\.board-reference-row \{[^}]*align-items: start/s);
    assert.match(desktop, /\.board-reference-row \{[^}]*padding-block:\s*\d+px/s);
    assert.match(desktop, /\.board-reference-row > \.board-reference-task \{[^}]*padding-block: 0/s);

    // ค่าตั้งต้นของไฟล์ยังเป็น center ไว้สำหรับเลย์เอาต์การ์ดบนจอแคบ
    assert.match(css, /\.board-reference-columns,\.my-tasks-page \.board-reference-row\{display:grid;[^}]*align-items:center/);
});

test('every column is wide enough for its own heading', async () => {
    const css = await read('resources/css/pages/mytasks/project-board.css');

    /*
     * ความกว้างที่ชื่อคอลัมน์ต้องใช้ ประมาณจากจำนวนพยัญชนะที่กินความกว้างจริงที่ 11px
     * (สระบนล่างและวรรณยุกต์ไม่กินความกว้าง) บวก padding ซ้ายขวาของช่องอีก 16px
     */
    const headingWidth = {1: 56, 2: 78, 3: 68, 4: 68, 5: 74, 6: 62, 7: 64, 8: 68};

    const boardRules = [...css.matchAll(/\[data-view="board"\][^{]*\{([^}]*)\}/g)]
        .flatMap(([, body]) => [...body.matchAll(/grid-template-columns:([^;]+)/g)].map(([, value]) => tracksOf(value)))
        .filter((tracks) => tracks.length === 10);

    for (const tracks of boardRules) {
        for (const [index, required] of Object.entries(headingWidth)) {
            const width = widthOf(tracks[Number(index)]);
            assert.ok(
                width >= required,
                `คอลัมน์ที่ ${Number(index) + 1} กว้าง ${width}px แต่ชื่อคอลัมน์ต้องการ ${required}px จึงตัดเป็นสองบรรทัด`,
            );
        }
    }
});
