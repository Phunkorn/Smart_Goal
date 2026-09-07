import test from 'node:test';
import assert from 'node:assert/strict';
import {createSaveScheduler, DEBOUNCE_MS, MAX_WAIT_MS} from '../../resources/js/pages/workspace/save-state.js';

/*
 * ตัวจัดตารางการบันทึกอัตโนมัติ
 *
 * ระบบไม่ทำ realtime การกันเขียนทับจึงพึ่งกลไกสองอย่าง คือการตรวจเวอร์ชันฝั่ง
 * เซิร์ฟเวอร์ กับการที่ฝั่งนี้ต้องไม่ยิงซ้ำด้วยเวอร์ชันเก่า เทสต์ชุดนี้ดูแล
 * ข้อหลัง
 *
 * นาฬิกาและตัวตั้งเวลาถูกฉีดเข้ามาทั้งหมด สถานการณ์ที่กินเวลาจริงหลายสิบวินาที
 * จึงรันได้ในเสี้ยววินาที และไม่มีความไม่แน่นอนเรื่องจังหวะเวลา
 */

/** นาฬิกาปลอมที่เดินเมื่อสั่งเท่านั้น */
const fakeClock = () => {
    let current = 0;
    let nextId = 1;
    const timers = new Map();

    return {
        now: () => current,
        setTimeoutImpl: (callback, delay) => {
            const id = nextId++;
            timers.set(id, {callback, at: current + delay});

            return id;
        },
        clearTimeoutImpl: (id) => timers.delete(id),
        /** เดินเวลาไปข้างหน้าแล้วยิงตัวตั้งเวลาที่ถึงกำหนด */
        async advance(ms) {
            current += ms;

            const due = Array.from(timers.entries())
                .filter(([, timer]) => timer.at <= current)
                .sort((a, b) => a[1].at - b[1].at);

            for (const [id, timer] of due) {
                timers.delete(id);
                timer.callback();
                // ปล่อยให้ promise ที่ค้างอยู่เดินต่อ (การบันทึกเป็น async)
                await Promise.resolve();
                await Promise.resolve();
            }
        },
        pending: () => timers.size,
    };
};

const build = ({saveImpl} = {}) => {
    const clock = fakeClock();
    const states = [];
    const saves = [];

    const scheduler = createSaveScheduler({
        now: clock.now,
        setTimeoutImpl: clock.setTimeoutImpl,
        clearTimeoutImpl: clock.clearTimeoutImpl,
        onState: (state, detail) => states.push({state, detail}),
        save: saveImpl || (async () => {
            saves.push(clock.now());

            return {version: saves.length + 1};
        }),
    });

    return {clock, states, saves, scheduler, names: () => states.map((entry) => entry.state)};
};

test('การแก้หนึ่งครั้งบันทึกหลังหน่วงครบเวลา ไม่ใช่ทันที', async () => {
    const env = build();

    env.scheduler.markDirty();
    assert.equal(env.scheduler.state(), 'dirty');

    await env.clock.advance(DEBOUNCE_MS - 1);
    assert.equal(env.saves.length, 0, 'ยังไม่ถึงเวลาต้องยังไม่บันทึก');

    await env.clock.advance(2);
    assert.equal(env.saves.length, 1);
    assert.equal(env.scheduler.state(), 'saved');
});

/*
 * การแก้ติด ๆ กันตลอดเวลาจะเลื่อนกำหนดออกไปเรื่อย ๆ ถ้าไม่มีเพดาน คนที่วาดยาว
 * สิบนาทีจะไม่มีจุดบันทึกเลยสักครั้ง แล้วเสียงานทั้งหมดถ้าเบราว์เซอร์ปิดกะทันหัน
 */
test('การแก้ต่อเนื่องยังถูกบันทึกเมื่อครบเพดานเวลารอ', async () => {
    const env = build();

    // แก้ทุก 400 มิลลิวินาที ซึ่งสั้นกว่าเวลาหน่วงปกติเสมอ ถ้าไม่มีเพดานเวลารอ
    // กำหนดบันทึกจะถูกเลื่อนออกไปเรื่อย ๆ และไม่มีวันเกิดขึ้นเลย
    for (let elapsed = 0; elapsed <= MAX_WAIT_MS; elapsed += 400) {
        env.scheduler.markDirty();
        await env.clock.advance(400);
    }

    assert.equal(env.saves.length >= 1, true, 'ต้องมีจุดบันทึกอย่างน้อยหนึ่งครั้ง');
    assert.equal(
        env.saves[0] <= MAX_WAIT_MS + 400,
        true,
        'และต้องเกิดภายในเพดานเวลารอ ไม่ใช่ถูกเลื่อนออกไปไม่รู้จบ'
    );
});

/*
 * ถ้าบันทึกกลางท่าลาก เซิร์ฟเวอร์จะได้เส้นที่วาดค้างครึ่งทาง แล้วคนอื่นที่
 * รีเฟรชจะเห็นงานที่ยังไม่เสร็จ
 */
test('ไม่บันทึกระหว่างที่ยังลากอยู่ และบันทึกเมื่อปล่อยนิ้ว', async () => {
    const env = build();

    env.scheduler.beginGesture();
    env.scheduler.markDirty();

    await env.clock.advance(DEBOUNCE_MS * 3);
    assert.equal(env.saves.length, 0, 'ระหว่างลากต้องไม่บันทึก');

    env.scheduler.endGesture();
    await env.clock.advance(DEBOUNCE_MS + 1);

    assert.equal(env.saves.length, 1);
});

test('มีคำขอค้างได้ทีละหนึ่ง และแก้ระหว่างบันทึกทำให้ยิงรอบใหม่', async () => {
    let resolveSave;
    const env = build({
        saveImpl: () => new Promise((resolve) => {
            resolveSave = () => resolve({version: 2});
        }),
    });

    env.scheduler.markDirty();
    await env.clock.advance(DEBOUNCE_MS + 1);

    assert.equal(env.scheduler.state(), 'saving');

    // แก้เพิ่มระหว่างที่คำขอแรกยังไม่ตอบ
    env.scheduler.markDirty();
    assert.equal(env.scheduler.state(), 'saving', 'ต้องไม่ยิงคำขอที่สองซ้อนเข้าไป');

    resolveSave();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(env.scheduler.state(), 'dirty', 'หลังคำขอแรกจบต้องรู้ว่ายังมีของค้าง');
});

test('บันทึกไม่สำเร็จเข้าสถานะผิดพลาดแล้วลองใหม่โดยหน่วงเพิ่มขึ้น', async () => {
    let attempts = 0;
    const env = build({
        saveImpl: async () => {
            attempts += 1;

            if (attempts < 3) {
                throw new Error('เครือข่ายขัดข้อง');
            }

            return {version: 2};
        },
    });

    env.scheduler.markDirty();
    await env.clock.advance(DEBOUNCE_MS + 1);
    assert.equal(env.scheduler.state(), 'error');

    await env.clock.advance(2000);
    assert.equal(attempts, 2);
    assert.equal(env.scheduler.state(), 'error');

    await env.clock.advance(5000);
    assert.equal(attempts, 3);
    assert.equal(env.scheduler.state(), 'saved');
});

/*
 * การชนเวอร์ชันต้องหยุดการบันทึกอัตโนมัติทันที การลองใหม่ด้วยเวอร์ชันเดิมจะชน
 * ซ้ำไปเรื่อย ๆ และรบกวนผู้ใช้ด้วยกล่องเตือนซ้ำทุกไม่กี่วินาที
 */
test('การชนเวอร์ชันหยุดการบันทึกอัตโนมัติ ไม่ลองใหม่', async () => {
    let attempts = 0;
    const env = build({
        saveImpl: async () => {
            attempts += 1;
            const error = new Error('ชนกัน');
            error.isVersionConflict = true;
            error.payload = {version: 9};

            throw error;
        },
    });

    env.scheduler.markDirty();
    await env.clock.advance(DEBOUNCE_MS + 1);

    assert.equal(env.scheduler.state(), 'conflict');
    assert.equal(attempts, 1);

    // แก้ต่อและปล่อยเวลาผ่านไปนาน ๆ ต้องไม่มีการยิงซ้ำ
    env.scheduler.markDirty();
    await env.clock.advance(60000);

    assert.equal(attempts, 1, 'ห้ามยิงซ้ำด้วยเวอร์ชันเก่า');
    assert.equal(env.scheduler.state(), 'conflict');
});

test('สถานะการชนถูกส่งพร้อมข้อมูลจากเซิร์ฟเวอร์', async () => {
    const env = build({
        saveImpl: async () => {
            const error = new Error('ชนกัน');
            error.isVersionConflict = true;
            error.payload = {version: 9, saved_by: {name: 'สมชาย'}};

            throw error;
        },
    });

    env.scheduler.markDirty();
    await env.clock.advance(DEBOUNCE_MS + 1);

    const conflict = env.states.find((entry) => entry.state === 'conflict');

    assert.equal(conflict.detail.error.payload.saved_by.name, 'สมชาย');
});

test('การกลับมาทำงานต่อล้างสถานะการชนและเปิดให้บันทึกได้อีก', async () => {
    let shouldConflict = true;
    const env = build({
        saveImpl: async () => {
            if (shouldConflict) {
                const error = new Error('ชนกัน');
                error.isVersionConflict = true;
                error.payload = {};

                throw error;
            }

            return {version: 10};
        },
    });

    env.scheduler.markDirty();
    await env.clock.advance(DEBOUNCE_MS + 1);
    assert.equal(env.scheduler.state(), 'conflict');

    shouldConflict = false;
    env.scheduler.resume();
    assert.equal(env.scheduler.state(), 'saved');

    env.scheduler.markDirty();
    await env.clock.advance(DEBOUNCE_MS + 1);

    assert.equal(env.scheduler.state(), 'saved');
});

test('การบันทึกทันทีไม่ต้องรอเวลาหน่วง', async () => {
    const env = build();

    env.scheduler.markDirty();
    await env.scheduler.flushNow();

    assert.equal(env.saves.length, 1);
    assert.equal(env.scheduler.state(), 'saved');
});

test('สถานะบอกได้ว่ายังมีของค้างหรือไม่ ใช้ตอนเตือนก่อนออกจากหน้า', async () => {
    const env = build();

    assert.equal(env.scheduler.isDirty(), false);

    env.scheduler.markDirty();
    assert.equal(env.scheduler.isDirty(), true);

    await env.clock.advance(DEBOUNCE_MS + 1);
    assert.equal(env.scheduler.isDirty(), false);
});

test('การหยุดถาวรยกเลิกตัวตั้งเวลาที่ค้างอยู่', async () => {
    const env = build();

    env.scheduler.markDirty();
    env.scheduler.stop();

    await env.clock.advance(DEBOUNCE_MS * 10);

    assert.equal(env.saves.length, 0);
});

test('ลำดับสถานะที่ผู้ใช้เห็นตรงกับที่เกิดขึ้นจริง', async () => {
    const env = build();

    env.scheduler.markDirty();
    await env.clock.advance(DEBOUNCE_MS + 1);

    assert.deepEqual(env.names(), ['dirty', 'saving', 'saved']);
});
