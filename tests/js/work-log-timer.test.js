import test from 'node:test';
import assert from 'node:assert/strict';

import {mountDom, click} from './helpers/dom.js';
import {initTimerBanner} from '../../resources/js/pages/daily-logs/timer.js';

/*
 * แถบตัวจับเวลาที่กำลังเดิน
 *
 * ตัวนับที่แสดงเป็นเพียงการคำนวณจาก started_at ที่เซิร์ฟเวอร์ส่งมา จำนวนนาทีจริง
 * ที่ถูกบันทึกคำนวณด้วยเวลาของเซิร์ฟเวอร์เสมอ เทสต์ชุดนี้จึงฉีดนาฬิกาปลอมเข้าไป
 * เพื่อพิสูจน์การแสดงผลแบบกำหนดผลลัพธ์ได้ โดยไม่ต้องรอเวลาจริงเดิน
 */
const STARTED_AT = '2026-09-04T02:00:00Z';

const mountBanner = ({startedAt = STARTED_AT, hidden = false} = {}) => mountDom(`<!doctype html><html><body>
    <div data-daily-log data-date="2026-09-04">
        <section class="timer-banner" data-timer-banner
            data-started-at="${startedAt}" data-log-id="7"${hidden ? ' hidden' : ''}>
            <div class="timer-banner__body">
                <strong class="timer-banner__title" data-timer-title>ตรวจสอบคอมพิวเตอร์</strong>
            </div>
            <span class="timer-banner__clock" data-timer-clock>00:00</span>
            <button type="button" class="timer-banner__stop" data-timer-stop>เสร็จสิ้น</button>
        </section>
    </div>
</body></html>`);

/** นาฬิกาปลอมที่ควบคุมได้ พร้อมตัวจับ callback ของ setInterval */
const fakeClock = (startIso) => {
    let current = Date.parse(startIso);
    const ticks = [];

    return {
        clock: () => current,
        advance(seconds) {
            current += seconds * 1000;
            ticks.forEach((callback) => callback());
        },
        setIntervalImpl: (callback) => {
            ticks.push(callback);

            return ticks.length;
        },
        clearIntervalImpl: () => ticks.splice(0, ticks.length),
        get tickerCount() {
            return ticks.length;
        },
    };
};

test('ตัวนับแสดงเวลาที่ผ่านไปจาก started_at ที่เซิร์ฟเวอร์ส่งมา', () => {
    const dom = mountBanner();
    const timing = fakeClock('2026-09-04T02:01:30Z');

    try {
        initTimerBanner({
            root: dom.document.querySelector('[data-daily-log]'),
            clock: timing.clock,
            setIntervalImpl: timing.setIntervalImpl,
            clearIntervalImpl: timing.clearIntervalImpl,
        });

        assert.equal(dom.document.querySelector('[data-timer-clock]').textContent, '01:30');
    } finally {
        dom.cleanup();
    }
});

test('ตัวนับเดินต่อเมื่อเวลาผ่านไป', () => {
    const dom = mountBanner();
    const timing = fakeClock('2026-09-04T02:00:00Z');

    try {
        initTimerBanner({
            root: dom.document.querySelector('[data-daily-log]'),
            clock: timing.clock,
            setIntervalImpl: timing.setIntervalImpl,
            clearIntervalImpl: timing.clearIntervalImpl,
        });

        assert.equal(dom.document.querySelector('[data-timer-clock]').textContent, '00:00');

        timing.advance(9);
        assert.equal(dom.document.querySelector('[data-timer-clock]').textContent, '00:09');

        timing.advance(3900);
        assert.equal(dom.document.querySelector('[data-timer-clock]').textContent, '1:05:09');
    } finally {
        dom.cleanup();
    }
});

/*
 * ปุ่มเสร็จสิ้นต้องยิงคำขอครั้งเดียวต่อการคลิกหนึ่งครั้ง
 * การผูก listener ซ้ำจะทำให้ส่งสองครั้งและอาจได้ 422 ในครั้งที่สอง
 */
test('กดเสร็จสิ้นหนึ่งครั้งเรียกตัวจัดการหนึ่งครั้งพร้อม id ของงาน', () => {
    const dom = mountBanner();
    const stopped = [];

    try {
        initTimerBanner({
            root: dom.document.querySelector('[data-daily-log]'),
            clock: () => Date.parse(STARTED_AT),
            setIntervalImpl: () => 1,
            clearIntervalImpl: () => {},
            onStop: (id) => stopped.push(id),
        });

        click(dom.document.querySelector('[data-timer-stop]'));

        assert.deepEqual(stopped, ['7']);
    } finally {
        dom.cleanup();
    }
});

test('เรียก initTimerBanner ซ้ำไม่ผูก listener ซ้ำ', () => {
    const dom = mountBanner();
    const stopped = [];

    try {
        const root = dom.document.querySelector('[data-daily-log]');
        const options = {
            root,
            clock: () => Date.parse(STARTED_AT),
            setIntervalImpl: () => 1,
            clearIntervalImpl: () => {},
            onStop: (id) => stopped.push(id),
        };

        initTimerBanner(options);
        const second = initTimerBanner(options);

        assert.equal(second, null, 'การเรียกซ้ำต้องไม่คืน instance ใหม่');

        click(dom.document.querySelector('[data-timer-stop]'));

        assert.equal(stopped.length, 1, 'ต้องยิงคำขอครั้งเดียวเท่านั้น');
    } finally {
        dom.cleanup();
    }
});

test('syncWith แสดงแถบเมื่อมีงานที่กำลังจับเวลา', () => {
    const dom = mountBanner({startedAt: '', hidden: true});
    const timing = fakeClock('2026-09-04T02:00:30Z');

    try {
        const banner = initTimerBanner({
            root: dom.document.querySelector('[data-daily-log]'),
            clock: timing.clock,
            setIntervalImpl: timing.setIntervalImpl,
            clearIntervalImpl: timing.clearIntervalImpl,
        });

        banner.syncWith({id: 12, title: 'Support หน้างาน', started_at: STARTED_AT, is_running: true});

        const node = dom.document.querySelector('[data-timer-banner]');
        assert.equal(node.hidden, false);
        assert.equal(node.dataset.logId, '12');
        assert.equal(dom.document.querySelector('[data-timer-title]').textContent, 'Support หน้างาน');
        assert.equal(dom.document.querySelector('[data-timer-clock]').textContent, '00:30');
    } finally {
        dom.cleanup();
    }
});

test('syncWith ซ่อนแถบเมื่องานที่จับเวลาอยู่ถูกปิด', () => {
    const dom = mountBanner();

    try {
        const banner = initTimerBanner({
            root: dom.document.querySelector('[data-daily-log]'),
            clock: () => Date.parse(STARTED_AT),
            setIntervalImpl: () => 1,
            clearIntervalImpl: () => {},
        });

        banner.syncWith({id: 7, title: 'ตรวจสอบคอมพิวเตอร์', started_at: STARTED_AT, is_running: false});

        assert.equal(dom.document.querySelector('[data-timer-banner]').hidden, true);
    } finally {
        dom.cleanup();
    }
});

/*
 * การบันทึกงานอื่นระหว่างที่ตัวจับเวลายังเดินอยู่ ต้องไม่ทำให้แถบหายไป
 */
test('syncWith ไม่ซ่อนแถบเมื่อผลลัพธ์เป็นของงานอื่น', () => {
    const dom = mountBanner();

    try {
        const banner = initTimerBanner({
            root: dom.document.querySelector('[data-daily-log]'),
            clock: () => Date.parse(STARTED_AT),
            setIntervalImpl: () => 1,
            clearIntervalImpl: () => {},
        });

        banner.syncWith({id: 99, title: 'งานอื่นที่เพิ่งบันทึก', is_running: false});

        assert.equal(dom.document.querySelector('[data-timer-banner]').hidden, false);
        assert.equal(dom.document.querySelector('[data-timer-banner]').dataset.logId, '7');
    } finally {
        dom.cleanup();
    }
});

test('แถบที่ซ่อนอยู่ไม่เริ่มเดินตัวนับโดยไม่จำเป็น', () => {
    const dom = mountBanner({startedAt: '', hidden: true});
    const timing = fakeClock('2026-09-04T02:00:00Z');

    try {
        initTimerBanner({
            root: dom.document.querySelector('[data-daily-log]'),
            clock: timing.clock,
            setIntervalImpl: timing.setIntervalImpl,
            clearIntervalImpl: timing.clearIntervalImpl,
        });

        assert.equal(timing.tickerCount, 0);
    } finally {
        dom.cleanup();
    }
});
