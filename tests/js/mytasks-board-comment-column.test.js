import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/** นับ track ของ grid โดยไม่นับช่องว่างที่อยู่ในวงเล็บ เช่น minmax(0, 1fr) คือหนึ่ง track */
function countTracks(value) {
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

    return tracks.length;
}

/** ทุกกฎที่จัดกริดของแถวบอร์ด พร้อมจำนวน track ที่ประกาศไว้ */
function rowGridRules(source) {
    return [...source.matchAll(/([^{}]*)\{([^{}]*)\}/g)]
        .filter(([, selector]) => selector.includes('board-reference-row'))
        .flatMap(([, selector, body]) => [...body.matchAll(/grid-template-columns:([^;}]+)/g)]
            .map(([, value]) => ({selector: selector.trim(), tracks: countTracks(value)})));
}

test('ทุก breakpoint ของแถวบอร์ดประกาศคอลัมน์ครบ 10 ช่อง หรือใช้เลย์เอาต์การ์ดมือถือ', async () => {
    const rules = rowGridRules(await read('resources/css/pages/mytasks/project-board.css'));

    // desktop = 10 ช่องตามหัวตาราง, มือถือ = 2 ช่อง (fallback เดิม) หรือ 4 ช่องของเลย์เอาต์การ์ด
    assert.ok(rules.length >= 9, `พบกฎกริดของแถวบอร์ดเพียง ${rules.length} กฎ`);
    for (const rule of rules) {
        assert.ok([10, 2, 4].includes(rule.tracks), `${rule.selector} ประกาศ ${rule.tracks} track`);
    }
    assert.equal(rules.filter((rule) => rule.tracks === 10).length, 9);
});

test('หัวตารางจัดกึ่งกลางครอบคลุมถึงคอลัมน์คอมเมนต์', async () => {
    const source = await read('resources/css/pages/mytasks/project-board.css');

    assert.match(source, /\.board-reference-columns > span:nth-child\(10\)/);
});

test('เลย์เอาต์การ์ดมือถือกำหนดตำแหน่งของช่องคอมเมนต์ไว้ชัดเจน', async () => {
    const source = await read('resources/css/pages/mytasks/project-board.css');
    const mobile = source.slice(source.indexOf('/* Mobile board cards'));

    assert.match(mobile, /\.board-reference-row > \.board-comments\s*\{[^}]*grid-column:\s*4;[^}]*grid-row:\s*4/s);
});

test('สไตล์คอมเมนต์ใต้ชื่องานแบบเดิมถูกลบออกจริง ไม่ใช่แค่ซ่อน', async () => {
    const css = await read('resources/css/pages/mytasks/project-board.css');
    const blade = await read('resources/views/tasks/partials/project-board-card.blade.php');

    assert.equal(css.includes('board-reference-comments'), false);
    assert.equal(blade.includes('board-reference-comments'), false);
});

test('หัวตารางบอร์ดวางคอลัมน์คอมเมนต์ไว้ถัดจากไฟล์แนบ', async () => {
    const blade = await read('resources/views/tasks/partials/project-board-card.blade.php');
    const header = blade.slice(blade.indexOf('board-reference-columns'), blade.indexOf('@foreach($projectGroups'));

    assert.match(header, /<span>ไฟล์แนบ<\/span><span>คอมเมนต์<\/span><span><\/span>/);
});

test('ช่องคอมเมนต์ของแต่ละแถวใช้ opener เดิมและคงอยู่เสมอแม้ไม่มีคอมเมนต์', async () => {
    const blade = await read('resources/views/tasks/partials/project-board-card.blade.php');

    // ไม่มี @if ครอบ เพราะการซ่อนช่องจะทำให้คอลัมน์ที่เหลือของกริดเลื่อนตำแหน่ง
    assert.match(blade, /class="board-comments\{\{[^"]*\}\}" data-open-task-modal data-task-id="\{\{ \$task->job_id \}\}" data-task-tab="updates"/);
    assert.match(blade, /\$commentCount \? '-'|\{\{ \$commentCount \?: '-' \}\}/);
    assert.match(blade, /data-unread-persistent/);
});

test('การล้างสถานะอ่านแล้วต้องไม่ลบช่องคอมเมนต์ของบอร์ดออกจากกริด', async () => {
    const source = await read('resources/js/pages/mytasks/task-timeline.js');

    assert.match(source, /data-unread-persistent/);
    assert.match(source, /classList\.remove\('has-unread'\)/);
});
