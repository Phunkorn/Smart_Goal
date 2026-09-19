import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {initDailyLogs} from '../../resources/js/pages/daily-logs/index.js';

/*
 * กดเริ่มงานประจำ — เส้นทางจริงตั้งแต่คลิกปุ่มในไทม์ไลน์จนได้คำตอบจาก server
 *
 * ลำดับคำถามต้องเป็นไปตามที่ server ขอ: เหตุผลวันค้าง/ผู้ร่วมงานมาไหมก่อน แล้วจึงเหตุผลเริ่มช้า
 * และคำตอบทุกขั้นถูกส่งซ้ำไปพร้อมกัน เพราะ server ตรวจทั้งชุดใน transaction เดียว
 */
const page = () => mountDom(`<!doctype html><html><head><meta name="csrf-token" content="t"></head><body>
    <div data-daily-log data-date="2026-09-07">
        <article data-log-card data-log-id="9"><button type="button" data-row-start>เริ่มงาน</button></article>
    </div>
    <script type="application/json" id="work-log-routes">{"start": "/daily-logs/__ID__/start"}</script>
    <script type="application/json" id="work-log-design">{"reasons": {"start": ["รถติด", "ประชุมยาว"], "missed": ["ลางาน"]}}</script>
    <script type="application/json" id="work-log-day">[{"id": 9, "title": "เช็คคอมชั้น 5", "status": "open"}]</script>
</body></html>`);

const response = (status, payload) => ({ok: status < 400, status, json: async () => payload});

/*
 * หน้าบันทึกงานตั้ง setInterval ไว้ (นาฬิกางานประจำ, เช็กสถานะใหม่) ซึ่งจะทำให้ node --test ไม่ยอมจบ
 * จึงเปิดหน้าผ่านตัวนี้ แล้วล้างตัวจับเวลาที่หน้าเปิดไว้ทั้งหมดตอนจบเทสต์
 */
const openPage = (dom, swal) => {
    const timers = [];
    const realSetInterval = globalThis.setInterval;
    globalThis.setInterval = (...args) => {
        const id = realSetInterval(...args);
        timers.push(id);
        return id;
    };

    try {
        initDailyLogs({doc: dom.document, swal});
    } finally {
        globalThis.setInterval = realSetInterval;
    }

    return () => timers.forEach((id) => clearInterval(id));
};

/** Swal จำลองที่ทำงานแบบของจริง: วาด html, เรียก didOpen, ให้ผู้ใช้กรอก แล้วเรียก preConfirm */
const fakeSwal = (dom, answer) => ({
    dialogs: [],
    popup: null,
    async fire(options) {
        if (options.icon === 'error') {
            this.dialogs.push(`error:${options.text}`);
            return {isConfirmed: true};
        }

        this.popup = dom.document.createElement('div');
        this.popup.innerHTML = options.html;
        dom.document.body.appendChild(this.popup);
        options.didOpen?.(this.popup);
        const kind = this.popup.querySelector('[data-routine-start]') ? 'requirements' : 'late-reason';
        this.dialogs.push(kind);
        answer(kind, this.popup);
        const value = options.preConfirm();
        this.popup.remove();

        return value === false ? {isConfirmed: false} : {isConfirmed: true, value};
    },
    getPopup() { return this.popup; },
    showValidationMessage(message) { this.dialogs.push(`invalid:${message}`); },
});

const settle = async (until) => {
    for (let round = 0; round < 50 && ! until(); round += 1) {
        await new Promise((resolve) => setTimeout(resolve, 5));
    }
};

test('กดเริ่มงานสาย: ถามผู้ร่วมงานก่อน แล้วจึงถามเหตุผลเริ่มช้า และส่งคำตอบทั้งหมดพร้อมกัน', async () => {
    const dom = page();
    const previousFetch = globalThis.fetch;
    let stopTimers = () => {};
    const calls = [];

    try {
        globalThis.fetch = async (url, init) => {
            const fields = Object.fromEntries(init.body.entries());
            calls.push({url, fields});

            if (! ('attendance[7]' in fields)) {
                return response(422, {ok: false, requirements: {backlog: [], attendance: [{id: 7, name: 'Anutida', backlog: []}]}});
            }
            if (! fields.late_start_reason) {
                return response(422, {ok: false, message: 'กรุณาระบุเหตุผลที่เริ่มงานช้า', errors: {late_start_reason: ['กรุณาระบุเหตุผลที่เริ่มงานช้า']}});
            }

            return response(200, {ok: true, log: {id: 9, status: 'in_progress'}});
        };

        const swal = fakeSwal(dom, (kind, popup) => {
            if (kind === 'late-reason') {
                popup.querySelector('.sg-select__trigger').click();
                popup.querySelector('.sg-select__option[data-value="รถติด"]').click();
            }
        });

        stopTimers = openPage(dom, swal);
        dom.document.querySelector('[data-row-start]').click();
        await settle(() => calls.length === 3);

        assert.deepEqual(swal.dialogs, ['requirements', 'late-reason'], 'ผู้ร่วมงานก่อน เหตุผลเริ่มช้าทีหลัง');
        assert.equal(calls.length, 3);
        assert.equal(calls[0].url, '/daily-logs/9/start');
        assert.deepEqual(calls[2].fields, {'attendance[7]': 'present', late_start_reason: 'รถติด'});
    } finally {
        stopTimers();
        globalThis.fetch = previousFetch;
        dom.cleanup();
    }
});

test('ไม่ได้เริ่มช้าและไม่มีคำถาม: เริ่มได้ในครั้งเดียวโดยไม่เปิดกล่องใดเลย', async () => {
    const dom = page();
    const previousFetch = globalThis.fetch;
    let stopTimers = () => {};
    let count = 0;

    try {
        globalThis.fetch = async () => {
            count += 1;
            return response(200, {ok: true, log: {id: 9, status: 'in_progress'}});
        };
        const swal = fakeSwal(dom, () => {});

        stopTimers = openPage(dom, swal);
        dom.document.querySelector('[data-row-start]').click();
        await settle(() => count === 1);
        await settle(() => false);

        assert.equal(count, 1);
        assert.deepEqual(swal.dialogs, []);
    } finally {
        stopTimers();
        globalThis.fetch = previousFetch;
        dom.cleanup();
    }
});

test('กดยกเลิกที่กล่องเหตุผลเริ่มช้า = ไม่เริ่มงาน และไม่ส่งซ้ำ', async () => {
    const dom = page();
    const previousFetch = globalThis.fetch;
    let stopTimers = () => {};
    let count = 0;

    try {
        globalThis.fetch = async () => {
            count += 1;
            return response(422, {ok: false, errors: {late_start_reason: ['กรุณาระบุเหตุผลที่เริ่มงานช้า']}});
        };
        const swal = fakeSwal(dom, () => {});
        swal.fire = async function fire(options) {
            this.dialogs.push(options.icon === 'error' ? 'error' : 'late-reason');
            return {isConfirmed: false};
        };

        stopTimers = openPage(dom, swal);
        dom.document.querySelector('[data-row-start]').click();
        await settle(() => swal.dialogs.length === 1);
        await settle(() => false);

        assert.equal(count, 1);
        assert.deepEqual(swal.dialogs, ['late-reason'], 'ยกเลิกแล้วต้องไม่ขึ้นกล่อง error');
    } finally {
        stopTimers();
        globalThis.fetch = previousFetch;
        dom.cleanup();
    }
});

/*
 * หน้าที่เปิดค้างไว้อัปเดตเองเมื่อผู้ร่วมงานกดเริ่ม/เสร็จแทน — ไม่ต้องกด F5
 *
 * เช็ก fingerprint ก่อน ขอการ์ดใหม่เฉพาะเมื่อสถานะเปลี่ยน แล้วแทนการ์ดเดิมในที่เดิม (ไม่ reload หน้า)
 */
const livePage = ({live = '1', fingerprint = 'old'} = {}) => mountDom(`<!doctype html><html><body>
    <div data-daily-log data-date="2026-09-07" data-day-fingerprint="${fingerprint}" data-live-day="${live}">
        <div data-timeline-list>
            <article data-log-card data-log-id="9"><button type="button" data-row-start>เริ่มงาน</button></article>
        </div>
    </div>
    <script type="application/json" id="work-log-routes">{"start": "/daily-logs/__ID__/start", "routineStatus": "http://localhost/daily-logs/routine-status"}</script>
    <script type="application/json" id="work-log-day">[{"id": 9, "title": "เช็คคอมชั้น 5", "status": "open"}]</script>
</body></html>`);

const openLivePage = (dom) => {
    const intervals = [];
    const realSetInterval = globalThis.setInterval;
    globalThis.setInterval = (callback, delay) => {
        intervals.push({callback, delay});
        return 0;
    };
    try {
        initDailyLogs({doc: dom.document, swal: fakeSwal(dom, () => {})});
    } finally {
        globalThis.setInterval = realSetInterval;
    }
    return intervals;
};

test('ผู้ร่วมงานกดเริ่มแทน: การ์ดเปลี่ยนจากปุ่มเริ่มงานเป็นเสร็จงานเองโดยไม่ reload หน้า', async () => {
    const dom = livePage();
    const previousFetch = globalThis.fetch;
    const urls = [];

    try {
        globalThis.fetch = async (url) => {
            urls.push(String(url));
            return String(url).includes('cards=1')
                ? response(200, {fingerprint: 'new', cards: [{
                    log: {id: 9, status: 'in_progress'},
                    html: '<article data-log-card data-log-id="9"><button type="button" data-row-complete>เสร็จงาน</button></article>',
                }]})
                : response(200, {fingerprint: 'new'});
        };
        let changed = 0;
        dom.document.addEventListener('smartgoal:routine-changed', () => { changed += 1; });

        const intervals = openLivePage(dom);
        const sync = intervals.find((interval) => interval.delay === 10_000);
        assert.ok(sync, 'ต้องเช็กสถานะทุก 10 วินาที');

        await sync.callback();

        const cards = dom.document.querySelectorAll('[data-log-card][data-log-id="9"]');
        assert.equal(cards.length, 1, 'แทนการ์ดเดิม ไม่ใช่เพิ่มซ้ำ');
        assert.ok(cards[0].querySelector('[data-row-complete]'));
        assert.equal(cards[0].querySelector('[data-row-start]'), null);
        assert.equal(dom.document.querySelector('[data-daily-log]').dataset.dayFingerprint, 'new');
        assert.equal(changed, 1, 'แถบบนต้องรู้ว่างานประจำเปลี่ยน');
        assert.deepEqual(urls, ['http://localhost/daily-logs/routine-status?live=1', 'http://localhost/daily-logs/routine-status?live=1&cards=1'], 'เช็กสดแบบอ่านอย่างเดียวทั้งสองครั้ง');

        // รอบถัดไป fingerprint เท่าเดิม — เช็กอย่างเดียว ไม่ขอการ์ดซ้ำ
        await sync.callback();
        assert.equal(urls.length, 3);
    } finally {
        globalThis.fetch = previousFetch;
        dom.cleanup();
    }
});

test('แท็บที่ซ่อนอยู่ไม่เช็กสถานะ และหน้าวันก่อนหน้าไม่ตั้งการเช็กสดเลย', async () => {
    const hidden = livePage();
    const previousFetch = globalThis.fetch;
    let calls = 0;

    try {
        globalThis.fetch = async () => {
            calls += 1;
            return response(200, {fingerprint: 'old'});
        };
        Object.defineProperty(hidden.document, 'hidden', {configurable: true, get: () => true});
        const sync = openLivePage(hidden).find((interval) => interval.delay === 10_000);
        await sync.callback();
        assert.equal(calls, 0);
    } finally {
        globalThis.fetch = previousFetch;
        hidden.cleanup();
    }

    const pastDay = livePage({live: '0'});
    try {
        assert.equal(openLivePage(pastDay).some((interval) => interval.delay === 10_000), false);
    } finally {
        pastDay.cleanup();
    }
});
