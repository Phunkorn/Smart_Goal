import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile, readdir} from 'node:fs/promises';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/*
 * ภาษาไทยต้องการที่ว่างเหนือเส้นฐานมากกว่าละติน
 *
 * ไทยวางเครื่องหมายซ้อนกันได้ถึงสองชั้นเหนือพยัญชนะ คือสระบนกับวรรณยุกต์
 * เช่น "ที่" "น้ำ" "เกี๊ยว" ค่า line-height: normal ที่เบราว์เซอร์ให้มากับ Prompt
 * แคบเกินกว่าจะรองรับ เมื่ออยู่ในกล่องที่ overflow: hidden ส่วนบนของตัวอักษร
 * จึงถูกตัดหายไป ผู้ใช้อ่านอาการนี้ว่า "ฟอนต์ใหญ่จนไม่เห็นสระ" แต่การลดขนาด
 * ฟอนต์อย่างเดียวไม่ช่วย เพราะอัตราส่วนยังเท่าเดิม
 */

const MINIMUM = 1.4;

test('the shared foundation declares a Thai-safe line height for every form control', async () => {
    const css = await read('resources/css/foundations/typography.css');

    const token = Number(css.match(/--thai-line-height:\s*([\d.]+)/)?.[1]);
    assert.ok(token >= MINIMUM, `--thai-line-height เป็น ${token} ซึ่งแคบเกินไปสำหรับสระซ้อนวรรณยุกต์`);

    // <input> ที่ไม่ประกาศ line-height จะได้ normal เสมอ จึงเป็นจุดที่อาการเกิดบ่อยที่สุด
    const controls = css.match(/input,\s*textarea,\s*select,\s*button\s*\{([^}]*)\}/s)?.[1] ?? '';
    assert.match(controls, /line-height:\s*var\(--thai-line-height\)/);

    // หน้า auth ใช้ชุดสไตล์ของตัวเอง ไม่ได้ import ไฟล์นี้ จึงต้องประกาศค่าเดียวกันซ้ำ
    const auth = await read('resources/css/components/auth/form-base.css');
    const authInput = auth.match(/\.control input \{([^}]*)\}/)?.[1] ?? '';
    assert.ok(
        Number(authInput.match(/line-height:\s*([\d.]+)/)?.[1]) >= MINIMUM,
        'ช่องกรอกหน้าเข้าสู่ระบบยังไม่มีที่ว่างพอสำหรับสระไทย'
    );
});

/*
 * กล่องที่ตัดเนื้อหาส่วนเกินทิ้งคือกล่องที่ตัดสระทิ้งได้ด้วย
 *
 * กฎที่ตั้งทั้ง font-size และ overflow: hidden จึงต้องประกาศ line-height ให้ชัด
 * ไม่ปล่อยเป็น normal
 */
test('no rule clips its own text by combining a font size with hidden overflow', async () => {
    const files = await readdir(new URL('../../resources/css/components/task-workspace/', import.meta.url));
    const offenders = [];

    for (const file of files.filter((name) => name.endsWith('.css'))) {
        const css = await read(`resources/css/components/task-workspace/${file}`);

        for (const match of css.matchAll(/([^{}]+)\{([^}]*)\}/g)) {
            const [, selector, body] = match;

            if (!/font-size:/.test(body) || !/overflow:\s*hidden/.test(body)) continue;
            if (/line-height:/.test(body)) continue;
            // ไอคอนของ bootstrap-icons ไม่มีสระ จึงไม่เข้าข่ายปัญหานี้
            if (/font-family:\s*bootstrap-icons/.test(body)) continue;

            offenders.push(`${file}: ${selector.trim()}`);
        }
    }

    assert.deepEqual(offenders, [], `กฎเหล่านี้ตัดข้อความของตัวเองได้:\n${offenders.join('\n')}`);
});

/*
 * ห้ามมีกฎที่บีบความสูงบรรทัดจนต่ำกว่าที่ภาษาไทยต้องการ
 *
 * ตรวจเฉพาะไฟล์ที่แสดงข้อความไทยเป็นหลัก ไม่ใช่ทั้งโปรเจกต์ เพราะ line-height: 1
 * ยังถูกต้องสำหรับกล่องที่มีแต่ตัวเลขหรือไอคอน
 */
test('the task modal never squeezes a line below what Thai needs', async () => {
    for (const path of [
        'resources/css/components/task-workspace/modal.css',
        'resources/css/components/task-workspace/workspace-modal.css',
    ]) {
        const css = await read(path);

        for (const match of css.matchAll(/line-height:\s*([\d.]+)(?!\w)/g)) {
            const value = Number(match[1]);

            // ค่าที่ไม่มีหน่วยและน้อยกว่า 1.4 คือการบีบจนสระไทยเสี่ยงถูกตัด
            assert.ok(
                value >= MINIMUM,
                `${path} มี line-height: ${value} ซึ่งแคบเกินไปสำหรับสระซ้อนวรรณยุกต์`
            );
        }
    }
});

/*
 * ชื่องานในหัวโมดัลคือจุดที่ผู้ใช้เห็นอาการนี้ชัดที่สุด
 * เป็นทั้งตัวใหญ่ที่สุดและอยู่ในกล่องที่ตัดเนื้อหาส่วนเกิน
 */
test('the modal task title has explicit headroom and is no longer oversized', async () => {
    const css = await read('resources/css/components/task-workspace/workspace-modal.css');
    const rule = css.match(/\.task-workspace__title h2 \{([^}]*)\}/)?.[1] ?? '';

    assert.match(rule, /line-height:\s*var\(--thai-line-height/);
    assert.ok(Number(rule.match(/font-size:\s*(\d+)px/)?.[1]) <= 19);
});

/*
 * รูปโปรไฟล์ในฟองแชทต้องเป็นวงกลมเสมอ
 *
 * .task-timeline-entry เป็น flex container ส่วน avatar เป็น flex item ที่ค่าปริยาย
 * ยอมหดได้ พอข้อความในฟองยาวหรือมีรูปแนบกว้าง ๆ avatar จะถูกบีบเฉพาะแนวนอนโดย
 * ความสูงคงเดิม วงกลมจึงกลายเป็นวงรีและรูปคนถูกบีบแบน
 *
 * กฎที่กำหนดทั้ง width, height และ border-radius: 50% คือกฎที่ตั้งใจให้เป็นวงกลม
 * จึงต้องประกาศ flex-basis คงที่ไว้ด้วยเสมอ
 */
test('circular avatars in the comment timeline can never be squeezed into ovals', async () => {
    const offenders = [];

    for (const path of [
        'resources/css/components/task-workspace/workspace-modal.css',
        'resources/css/components/task-workspace/modal.css',
    ]) {
        const css = await read(path);

        for (const match of css.matchAll(/([^{}]*(?:avatar|reader)[^{}]*)\{([^}]*)\}/gi)) {
            const [, selector, body] = match;

            if (!/border-radius:\s*50%/.test(body)) continue;
            if (!/width:\s*\d+px/.test(body) || !/height:\s*\d+px/.test(body)) continue;
            if (/flex:|flex-shrink:|flex-basis:/.test(body)) continue;

            offenders.push(`${path.split('/').pop()}: ${selector.trim()}`);
        }
    }

    assert.deepEqual(offenders, [], `วงกลมเหล่านี้ถูกบีบเป็นวงรีได้: ${offenders.join(' | ')}`);
});

/*
 * ช่องพิมพ์คอมเมนต์ต้องสูงพอเห็นสิ่งที่พิมพ์ไปแล้ว
 *
 * ของเดิมสูงราวหนึ่งบรรทัดครึ่ง ข้อความจึงเลื่อนหายทันทีที่ขึ้นบรรทัดที่สอง และเพราะ
 * กล่องเตี้ยมาก การลากเลือกข้อความข้ามบรรทัดทำให้เบราว์เซอร์เลื่อนอัตโนมัติกระตุก
 * ขึ้นลงจนคัดลอกบรรทัดที่ต้องการแทบไม่ได้
 */
test('the comment composer shows about four lines before it needs to scroll', async () => {
    const blade = await read('resources/views/tasks/partials/workspace-interactions.blade.php');
    assert.match(blade, /data-task-update-note[^>]*rows="4"/, 'ต้องสูงพอตั้งแต่ก่อน CSS โหลด');

    // จับตัวกฎด้วยการหาตำแหน่งของ selector ตรง ๆ แทนการประกอบ regex จากสตริงที่มี
    // อักขระพิเศษของ CSS ปนอยู่ (จุด, ~, ช่องว่าง) ซึ่ง escape พลาดได้ง่ายและอ่านยาก
    const ruleFor = (css, selector) => {
        const start = css.indexOf(selector + ' {');
        if (start === -1) return '';

        const open = css.indexOf('{', start);

        return css.slice(open + 1, css.indexOf('}', open));
    };

    for (const [path, selector] of [
        ['resources/css/components/task-workspace/workspace-modal.css', '.task-workspace .task-timeline__compose textarea'],
        ['resources/css/components/task-workspace/modal.css', '.my-tasks-page ~ .task-edit-modal .task-timeline__compose textarea'],
    ]) {
        const css = await read(path);
        const rule = ruleFor(css, selector);

        assert.ok(rule, `ไม่พบกฎของ ${selector}`);

        // สี่บรรทัดที่ line-height 1.6 กับฟอนต์ 13px คือราว 84px บวก padding
        const min = Number(rule.match(/min-height:\s*(\d+)px/)?.[1]);
        assert.ok(min >= 100, `${selector} สูง ${min}px ยังเห็นได้ไม่ถึงสี่บรรทัด`);

        // ผู้ใช้ต้องยืดเองได้เมื่อพิมพ์ยาวกว่าที่กล่องรองรับ
        assert.match(rule, /resize:\s*vertical/, `${selector} ต้องให้ผู้ใช้ยืดเองได้`);
        assert.doesNotMatch(rule, /overflow:\s*auto(?!\s)/, 'ต้องระบุสองแกน ไม่ปล่อยให้ overflow-x ถูกอนุมาน');
    }
});

/*
 * กริดของบอร์ดต้องนับคอลัมน์ตามที่ render จริง
 *
 * คอลัมน์ "รอตรวจสอบ" ถูกตัดออกจาก $statuses เมื่อผู้ใช้ดูเฉพาะงานที่เปิดเอง
 * (งานที่เปิดเอง รับผิดชอบเอง อนุมัติเอง ไม่มีวันเข้าขั้นตรวจ) การตรึงกริดไว้ที่
 * ห้าคอลัมน์จึงทิ้งช่องว่างค้างไว้และบังคับให้มีแถบเลื่อนแนวนอนโดยไม่จำเป็น
 */
test('the kanban grid follows however many status columns Blade renders', async () => {
    const css = await read('resources/css/components/task-workspace/kanban.css');
    const blade = await read('resources/views/tasks/partials/table-kanban.blade.php');

    const rule = css.match(/\.mytasks-kanban__columns \{([^}]*)\}/)?.[1] ?? '';

    assert.match(rule, /grid-auto-flow:\s*column/, 'ต้องสร้าง track ตามจำนวนลูกที่มีจริง');
    assert.doesNotMatch(rule, /grid-template-columns:\s*repeat\(\d+,/, 'ห้ามตรึงจำนวนคอลัมน์ไว้ตายตัว');
    assert.doesNotMatch(rule, /min-width:\s*\d+px/, 'ห้ามตรึงความกว้างขั้นต่ำเป็นพิกเซลตายตัว');

    // Blade ยังต้องตัดคอลัมน์ออกจริง ไม่ใช่ซ่อนด้วย CSS
    assert.match(blade, /unset\(\$statuses\[3\]\)/);
});

/*
 * ปุ่มแนบรูปคือ <label> ที่ครอบ input ที่ซ่อนอยู่ จึงไม่ได้รูปลักษณ์ของปุ่มมาจาก
 * เบราว์เซอร์เลย ถ้าไม่ประกาศกรอบเอง จะเหลือแค่ไอคอนลอย ๆ ที่ไม่มีอะไรบอกว่ากดได้
 *
 * Blade ไฟล์เดียวถูกใช้ทั้งสองบริบท (.task-workspace และ .task-edit-modal)
 * สไตล์จึงต้องมีครบทั้งคู่ ไม่ใช่มีแค่ฝั่งเดียว
 */
test('the attach button looks clickable in every context that renders it', async () => {
    const scopes = [
        ['resources/css/components/task-workspace/workspace-modal.css', '.task-workspace'],
        ['resources/css/components/task-workspace/modal.css', '.my-tasks-page ~ .task-edit-modal'],
    ];

    for (const [path, scope] of scopes) {
        const css = await read(path);
        const start = css.indexOf(`${scope} .task-timeline__attach {`);

        assert.ok(start !== -1, `${scope} ยังไม่มีสไตล์ของปุ่มแนบรูป`);

        const rule = css.slice(css.indexOf('{', start) + 1, css.indexOf('}', start));

        assert.match(rule, /border:\s*1px solid/, `${scope} ปุ่มแนบรูปต้องมีกรอบให้เห็นว่ากดได้`);
        assert.match(rule, /cursor:\s*pointer/, `${scope} ปุ่มแนบรูปต้องบอกด้วยเคอร์เซอร์ว่ากดได้`);
        assert.ok(
            css.includes(`${scope} .task-timeline__attach:focus-within`),
            `${scope} ต้องขานรับโฟกัส เพราะ input ที่ซ่อนอยู่เป็นตัวรับโฟกัสแทน label`
        );
    }
});

/*
 * รูปที่แนบและพรีวิวก่อนส่งต้องมีสไตล์ครบทั้งสองบริบทเช่นกัน
 * ไม่งั้นหน้าหนึ่งจะเห็นรูปขนาดเต็มไม่มีขอบเขต ส่วนอีกหน้าปกติ
 */
test('comment image previews and bubbles are styled in both modal contexts', async () => {
    for (const [path, scope] of [
        ['resources/css/components/task-workspace/workspace-modal.css', '.task-workspace'],
        ['resources/css/components/task-workspace/modal.css', '.my-tasks-page ~ .task-edit-modal'],
    ]) {
        const css = await read(path);

        for (const part of ['.task-timeline__previews', '.task-timeline__preview', '.task-timeline-entry__images']) {
            assert.ok(
                css.includes(`${scope} ${part}`),
                `${scope} ยังไม่มีสไตล์ของ ${part}`
            );
        }
    }
});
