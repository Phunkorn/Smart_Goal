import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

/*
 * ชื่องานในมุมมองตารางของ "งานของฉัน" ต้องมองเห็นได้ทุกแถว
 *
 * ปัญหาที่ผู้ใช้เจอจริง (จอ 882px): แถวงานเหลือแค่ลูกศร ตัวนับ "งานย่อย 0/0" และปุ่มเปิดงาน
 * ชื่องานหายไปทั้งที่ถูก render ครบใน DOM คอลัมน์ชื่องานมีขั้นต่ำ 140px แต่ของที่กว้างคงที่
 * ในคอลัมน์นั้น (ลูกศร ตัวนับ ปุ่มเปิด ระยะขอบ) รวมกันราว 135px ชื่อจึงเหลือความกว้าง 0
 *
 * jsdom ไม่คำนวณ layout test นี้จึงตรึงตัวเลขและกฎที่เป็นตัวแก้
 * ผลจริงวัดบนเบราว์เซอร์ที่ 375 / 768 / 882 / 1366 / 1920px
 */

const read = async (path) => (await readFile(new URL(`../../${path}`, import.meta.url), 'utf8'))
    .replace(/\/\*[\s\S]*?\*\//g, '');

/** ลูกศร 16 + ช่องไฟ 5 + ระยะขอบปุ่ม 8 + ปุ่มเปิดงาน 28 + ช่องไฟ 5 + ระยะขอบช่อง 16 */
const FIXED_PARTS = 16 + 5 + 8 + 28 + 5 + 16;
/** ชื่องานต้องได้อย่างน้อยเท่านี้ จึงอ่านได้โดยไม่แตกเป็นคำละบรรทัด */
const MIN_READABLE_TITLE = 180;

const tracksOf = (value) => value.trim().split(/\s+(?![^(]*\))/);
const pixels = (track) => Number(track.match(/(\d+)px/)?.[1]);

/** กฎของมุมมองตาราง (data-view="board") พร้อม selector เพื่อจับคู่คอลัมน์กับ min-width */
function boardRules(css) {
    return [...css.matchAll(/([^{}]*\[data-view="board"\][^{}]*)\{([^{}]*)\}/g)]
        .map(([, selector, body]) => ({selector: selector.trim(), body}));
}

test('คอลัมน์ชื่องานมีขั้นต่ำที่เหลือที่ให้ชื่องานจริง ไม่ใช่แค่กันคอลัมน์หาย', async () => {
    const css = await read('resources/css/pages/mytasks/project-board.css');
    const grids = boardRules(css)
        .map(({body}) => body.match(/--board-columns:([^;]+)/)?.[1])
        .filter(Boolean)
        .map(tracksOf)
        .filter((tracks) => tracks.length === 11);

    assert.ok(grids.length >= 2, 'ต้องมีกฎคอลัมน์ทั้งตอน Sidebar พับและกาง');

    for (const tracks of grids) {
        const nameMinimum = pixels(tracks[0]);

        assert.ok(
            nameMinimum - FIXED_PARTS >= MIN_READABLE_TITLE,
            `คอลัมน์ชื่องานขั้นต่ำ ${nameMinimum}px เหลือให้ชื่อ ${nameMinimum - FIXED_PARTS}px ต้องได้อย่างน้อย ${MIN_READABLE_TITLE}px`,
        );
    }
});

test('min-width ของตารางเท่ากับผลรวมของทุกคอลัมน์ คอลัมน์ชื่องานจึงไม่ถูกบีบต่ำกว่าขั้นต่ำ', async () => {
    const css = await read('resources/css/pages/mytasks/project-board.css');
    const rules = boardRules(css);
    const sidebarOpen = (selector) => selector.includes('body:not(.sidebar-collapsed)');

    for (const open of [false, true]) {
        const tracks = rules
            .filter(({selector, body}) => sidebarOpen(selector) === open && /--board-columns:/.test(body))
            .map(({body}) => tracksOf(body.match(/--board-columns:([^;]+)/)[1]))
            .find((candidate) => candidate.length === 11);
        const minWidth = rules
            .filter(({selector}) => sidebarOpen(selector) === open && selector.endsWith('.board-reference-list'))
            .map(({body}) => pixels(body.match(/min-width:\s*([^;]+)/)?.[1] || ''))
            .find((value) => value > 0);

        const sum = tracks.reduce((total, track) => total + pixels(track), 0);

        assert.equal(minWidth, sum, `Sidebar ${open ? 'กาง' : 'พับ'}: min-width ${minWidth}px ต้องเท่ากับผลรวมคอลัมน์ ${sum}px`);
    }
});

test('บนมือถือ min-width ของตารางถูกคืนเป็น 0 การ์ดงานจึงไม่กว้างเกินจอ', async () => {
    const css = await read('resources/css/pages/mytasks/project-board.css');
    const mobile = css.slice(css.lastIndexOf('@media (max-width: 760px)'));

    assert.match(mobile, /\[data-view="board"\] \.board-reference-list \{\s*min-width:\s*0;/);
});

test('ชื่องานแม่และงานย่อยขึ้นบรรทัดใหม่ได้ ไม่ถูกตัดด้วยจุดไข่ปลา', async () => {
    const css = await read('resources/css/pages/mytasks/task-details.css');

    for (const selector of [
        '.board-task-details__toggle > .board-reference-task__title',
        '.board-task-detail [data-task-detail-title]',
    ]) {
        const body = css.match(new RegExp(`${selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} \\{([^}]*)\\}`))?.[1] || '';

        assert.match(body, /white-space:\s*normal/, `${selector} ต้องยกเลิก nowrap ของกฎกลาง`);
        assert.match(body, /overflow-wrap:\s*anywhere/, `${selector} ต้องตัดคำยาวได้`);
        assert.match(body, /overflow:\s*visible/, `${selector} ต้องไม่ซ่อนส่วนที่เกิน`);
    }
});

test('เมื่อช่องชื่องานแคบ ตัวนับงานย่อยลงไปอยู่ใต้ชื่อ ชื่องานได้ความกว้างก่อน', async () => {
    const css = await read('resources/css/pages/mytasks/task-details.css');

    assert.match(css, /\.board-reference-row > \.board-reference-task \{\s*container-type:\s*inline-size;/);

    const narrow = css.slice(css.indexOf('@container (max-width: 360px)'));
    assert.match(narrow, /\.board-task-details__toggle > small \{\s*grid-column:\s*2 \/ -1;\s*grid-row:\s*2;/);
});
