import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {
    DEFAULT_LOOP_SECONDS,
    LAST_PLAYED_KEY,
    PREFERENCE_KEY,
    STALE_AFTER_MS,
    claimTicket,
    createChime,
    initTicketAlert,
    isFreshTicketCreated,
    safeStorage,
} from '../../resources/js/components/ticket-alert.js';
import {click, mountDom} from './helpers/dom.js';

const NOW = Date.parse('2026-10-23T03:00:00Z');
const fresh = (id, extra = {}) => ({id, type: 'ticket_created', category: 'ticket', created_at: new Date(NOW - 5000).toISOString(), ...extra});

/** localStorage จำลองที่หลายแท็บใช้ร่วมกันได้ */
function sharedStorage(initial = {}) {
    const map = new Map(Object.entries(initial));

    return {getItem: (key) => (map.has(key) ? map.get(key) : null), setItem: (key, value) => map.set(key, String(value)), map};
}

/** Web Locks จำลอง: เรียง callback ทีละตัวตามลำดับคำขอ */
function fakeLocks() {
    let chain = Promise.resolve();

    return {
        request(name, callback) {
            const run = chain.then(() => callback());
            chain = run.catch(() => {});

            return run;
        },
    };
}

/** playable = AudioContext ของแท็บนี้ running แล้ว (false = ยังถูก autoplay policy ล็อก) */
function fakeChime(playable = true) {
    const calls = {unlock: 0, play: 0, preview: 0, stop: 0};

    return {
        calls,
        unlock() { calls.unlock += 1; return true; },
        canPlay: () => playable,
        play() { calls.play += 1; return true; },
        preview() { calls.preview += 1; return true; },
        stop() { calls.stop += 1; },
    };
}

const markup = `<!doctype html><html><body>
<span>เสียงแจ้งเตือน Ticket ใหม่: <strong data-ticket-sound-state>เปิด</strong></span>
<button type="button" data-ticket-sound-toggle aria-pressed="true"><i class="bi bi-volume-up-fill"></i></button>
</body></html>`;

function openTab(storage, locks = fakeLocks(), {playable = true} = {}) {
    const dom = mountDom(markup);
    const chime = fakeChime(playable);
    let clock = NOW;
    const controller = initTicketAlert(dom.document, {storage, locks, chime, now: () => clock});
    const notify = (detail) => dom.document.dispatchEvent(new dom.window.CustomEvent('smartgoal:realtime-notification', {detail}));

    return {...dom, chime, controller, notify, advance: (ms) => { clock += ms; }};
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

test('only fresh ticket_created events count', () => {
    assert.equal(isFreshTicketCreated(fresh(7), NOW), true);
    assert.equal(isFreshTicketCreated(fresh(7, {type: 'task_assigned', category: 'task'}), NOW), false);
    assert.equal(isFreshTicketCreated(fresh(7, {type: 'ticket_note_added'}), NOW), false);
    assert.equal(isFreshTicketCreated(fresh(7, {created_at: new Date(NOW - STALE_AFTER_MS - 1000).toISOString()}), NOW), false, 'old ticket');
    assert.equal(isFreshTicketCreated(fresh('abc'), NOW), false);
    assert.equal(isFreshTicketCreated(null, NOW), false);
});

test('a new ticket plays once, and the same id never plays again (refresh, repeat event)', async () => {
    const tab = openTab(sharedStorage());
    try {
        tab.notify(fresh(41));
        await flush();
        tab.advance(10000);
        tab.notify(fresh(41));
        await flush();

        assert.equal(tab.chime.calls.play, 1);
    } finally {
        tab.cleanup();
    }

    // "refresh": แท็บใหม่ storage เดิม — event เดิมที่มาซ้ำไม่ดังอีก
    const reloaded = openTab(sharedStorage({[LAST_PLAYED_KEY]: '41'}));
    try {
        reloaded.notify(fresh(41));
        await flush();
        assert.equal(reloaded.chime.calls.play, 0);
    } finally {
        reloaded.cleanup();
    }
});

test('other notification types and old tickets stay silent', async () => {
    const tab = openTab(sharedStorage());
    try {
        tab.notify(fresh(5, {type: 'meeting_scheduled', category: 'meeting'}));
        tab.notify(fresh(6, {type: 'ticket_status_changed'}));
        tab.notify(fresh(7, {created_at: new Date(NOW - STALE_AFTER_MS - 1).toISOString()}));
        await flush();

        assert.equal(tab.chime.calls.play, 0);
    } finally {
        tab.cleanup();
    }
});

test('muted means silent, and the toggle switches state, label and icon', async () => {
    const storage = sharedStorage({[PREFERENCE_KEY]: 'off'});
    const tab = openTab(storage);
    try {
        const toggle = tab.document.querySelector('[data-ticket-sound-toggle]');
        const state = tab.document.querySelector('[data-ticket-sound-state]');
        assert.equal(toggle.getAttribute('aria-pressed'), 'false');
        assert.equal(state.textContent, 'ปิด', 'the visible on/off text follows the stored choice');
        assert.ok(toggle.querySelector('i').classList.contains('bi-volume-mute-fill'));

        tab.notify(fresh(9));
        await flush();
        assert.equal(tab.chime.calls.play, 0);

        click(toggle);
        assert.equal(storage.getItem(PREFERENCE_KEY), 'on');
        assert.equal(toggle.getAttribute('aria-pressed'), 'true');
        assert.equal(state.textContent, 'เปิด');
        assert.match(toggle.getAttribute('aria-label'), /ปิดเสียง/);
        assert.ok(toggle.querySelector('i').classList.contains('bi-volume-up-fill'));
        assert.equal(tab.chime.calls.unlock, 1, 'the click is the user gesture that unlocks audio');
        assert.equal(tab.chime.calls.preview, 1, 'a one-pass preview confirms sound is on');
        assert.equal(tab.chime.calls.play, 0, 'the preview is not the 20-second alert');

        click(toggle);
        assert.equal(storage.getItem(PREFERENCE_KEY), 'off');
        assert.equal(toggle.getAttribute('aria-pressed'), 'false');
        assert.equal(tab.chime.calls.stop, 1, 'muting stops a sound that is still ringing');
    } finally {
        tab.cleanup();
    }
});

test('two open tabs play the same ticket only once', async () => {
    const storage = sharedStorage();
    const locks = fakeLocks();
    const first = openTab(storage, locks);
    const second = openTab(storage, locks);
    try {
        first.notify(fresh(77));
        second.notify(fresh(77));
        await flush();
        await flush();

        assert.equal(first.chime.calls.play + second.chime.calls.play, 1);
        assert.equal(storage.getItem(LAST_PLAYED_KEY), '77');
    } finally {
        first.cleanup();
        second.cleanup();
    }
});

test('a tab whose audio is still locked never claims the ticket, so an unlocked tab plays it', async () => {
    const storage = sharedStorage();
    const locks = fakeLocks();
    // แท็บที่เปิดค้างไว้โดยไม่เคยคลิก (เสียงถูกล็อก) ได้ event ก่อน
    const locked = openTab(storage, locks, {playable: false});
    const unlocked = openTab(storage, locks);
    try {
        locked.notify(fresh(88));
        await flush();
        unlocked.notify(fresh(88));
        await flush();
        await flush();

        assert.equal(locked.chime.calls.play, 0);
        assert.equal(unlocked.chime.calls.play, 1);
        assert.equal(storage.getItem(LAST_PLAYED_KEY), '88');
    } finally {
        locked.cleanup();
        unlocked.cleanup();
    }
});

test('without Web Locks the shared storage still prevents a double chime', async () => {
    const storage = safeStorage(sharedStorage());
    assert.equal(await claimTicket(12, storage, undefined), true);
    assert.equal(await claimTicket(12, storage, undefined), false);
    assert.equal(await claimTicket(11, storage, undefined), false, 'older ids never play');
    assert.equal(await claimTicket(13, storage, {request: () => Promise.reject(new Error('locks broken'))}), true);
});

test('several tickets in one poll make one chime', async () => {
    const tab = openTab(sharedStorage());
    try {
        tab.notify(fresh(1));
        tab.notify(fresh(2));
        tab.notify(fresh(3));
        await flush();

        assert.equal(tab.chime.calls.play, 1);
    } finally {
        tab.cleanup();
    }
});

test('audio is tried at page load and retried on the first gesture (autoplay policy)', () => {
    // Chrome ให้ AudioContext ที่สร้างตอนโหลด running ได้เมื่อมาจากการคลิกเมนูในเว็บเดียวกัน (วัดในเบราว์เซอร์จริง)
    // เปิดหน้าตรง/รีเฟรชยังถูกล็อก — การกดหรือพิมพ์ครั้งแรกของหน้านี้ปลุกใหม่
    const tab = openTab(sharedStorage());
    try {
        assert.equal(tab.chime.calls.unlock, 1, 'tried once at load');
        tab.document.dispatchEvent(new tab.window.Event('pointerdown', {bubbles: true}));
        assert.equal(tab.chime.calls.unlock, 2);
        tab.document.dispatchEvent(new tab.window.Event('pointerdown', {bubbles: true}));
        assert.equal(tab.chime.calls.unlock, 2, 'listener runs once');
    } finally {
        tab.cleanup();
    }

    const muted = openTab(sharedStorage({[PREFERENCE_KEY]: 'off'}));
    try {
        assert.equal(muted.chime.calls.unlock, 0, 'muted never creates audio at load');
    } finally {
        muted.cleanup();
    }
});

test('a missing, broken or locked AudioContext never throws', () => {
    const none = createChime(undefined);
    assert.equal(none.unlock(), false);
    assert.equal(none.play(), false);

    const broken = createChime(class { constructor() { throw new Error('not allowed'); } });
    assert.equal(broken.unlock(), false);
    assert.equal(broken.play(), false);

    const suspended = createChime(class { constructor() { this.state = 'suspended'; } resume() { return Promise.reject(new Error('gesture required')); } });
    assert.equal(suspended.unlock(), true);
    assert.equal(suspended.play(), false, 'still suspended → silent');
    assert.equal(suspended.canPlay(), false);
});

test('a running AudioContext plays the two-tone chime', () => {
    const started = [];
    class FakeContext {
        constructor() { this.state = 'running'; this.currentTime = 0; this.destination = {}; }
        createOscillator() { return {type: '', frequency: {}, connect() {}, start: (at) => started.push(at), stop() {}}; }
        createGain() { return {gain: {setValueAtTime() {}, exponentialRampToValueAtTime() {}}, connect() {}}; }
    }

    const chime = createChime(FakeContext);
    assert.equal(chime.canPlay(), false, 'nothing created yet');
    assert.equal(chime.unlock(), true);
    assert.equal(chime.canPlay(), true);
    assert.equal(chime.play(), true);
    assert.equal(started.length, 2);
});

/** AudioContext จำลองที่ decode ไฟล์ได้ — บันทึก buffer source ที่ถูกสร้าง (loop / เวลาเริ่ม / เวลาหยุด) */
class FakeFileContext {
    constructor() { this.state = 'running'; this.currentTime = 100; this.destination = {}; this.sources = []; this.oscillators = 0; }
    decodeAudioData() { return Promise.resolve({duration: 1.6}); }
    createBufferSource() {
        const source = {loop: false, started: null, stopAt: null, stopped: 0, connect() {},
            start(at) { this.started = at; }, stop(at) { if (at === undefined) this.stopped += 1; else this.stopAt = at; }};
        this.sources.push(source);
        return source;
    }
    createOscillator() { this.oscillators += 1; return {type: '', frequency: {}, connect() {}, start() {}, stop() {}}; }
    createGain() { return {gain: {setValueAtTime() {}, exponentialRampToValueAtTime() {}}, connect() {}}; }
}

const soundFetch = (calls) => async (url, init) => {
    calls.push({url, init});
    return {ok: true, arrayBuffer: async () => new ArrayBuffer(8)};
};

test('a new ticket loops the system sound file for 20 seconds, then it stops by itself', async () => {
    let context;
    const fetches = [];
    const sound = createChime(class extends FakeFileContext { constructor() { super(); context = this; } }, {
        soundUrl: '/sounds/alert%20-1.mp3', loopSeconds: DEFAULT_LOOP_SECONDS, fetchImpl: soundFetch(fetches),
    });
    assert.equal(DEFAULT_LOOP_SECONDS, 20);

    sound.unlock();
    sound.unlock();
    await flush();
    assert.deepEqual(fetches.map((call) => call.url), ['/sounds/alert%20-1.mp3'], 'the file is loaded once per page');

    assert.equal(sound.play(), true);
    const [ring] = context.sources;
    assert.equal(ring.loop, true);
    assert.equal(ring.started, 100);
    assert.equal(ring.stopAt, 120, 'stop is scheduled on the audio clock, 20 s after the start (no timer)');
    assert.equal(sound.isRinging(), true);
    assert.equal(context.oscillators, 0, 'the synthetic chime is not used when the file is ready');

    // Ticket ใหม่ระหว่างที่ยังดัง: ตัวเก่าหยุด ตัวใหม่เริ่มนับ 20 วินาทีใหม่ — ไม่ซ้อนกัน
    context.currentTime = 108;
    sound.play();
    assert.equal(ring.stopped, 1);
    assert.equal(context.sources[1].stopAt, 128);

    sound.stop();
    assert.equal(context.sources[1].stopped, 1);
    assert.equal(sound.isRinging(), false);
});

test('the toggle preview plays the file once, not the 20-second loop', async () => {
    let context;
    const sound = createChime(class extends FakeFileContext { constructor() { super(); context = this; } }, {
        soundUrl: '/sounds/alert.mp3', fetchImpl: soundFetch([]),
    });
    sound.unlock();
    sound.preview();
    await flush();
    await flush();

    assert.equal(context.sources.length, 1);
    assert.equal(context.sources[0].loop, false);
    assert.equal(context.sources[0].stopAt, 101.6, 'one pass of the 1.6 s file');
    assert.equal(sound.isRinging(), false);
});

test('a missing or broken sound file falls back to the synthetic chime instead of silence', async () => {
    for (const fetchImpl of [
        async () => ({ok: false, arrayBuffer: async () => new ArrayBuffer(0)}),
        async () => { throw new Error('offline'); },
        undefined,
    ]) {
        let context;
        const sound = createChime(class extends FakeFileContext { constructor() { super(); context = this; } }, {soundUrl: '/sounds/missing.mp3', fetchImpl});
        sound.unlock();
        await flush();
        await flush();

        assert.equal(sound.play(), true);
        assert.equal(context.sources.length, 0);
        assert.equal(context.oscillators, 2);
    }
});

test('muting in another tab, or another tab taking a newer ticket, stops this tab ringing', () => {
    const tab = openTab(sharedStorage());
    try {
        tab.window.dispatchEvent(new tab.window.StorageEvent('storage', {key: LAST_PLAYED_KEY, newValue: '90'}));
        assert.equal(tab.chime.calls.stop, 1, 'another tab rings the newer ticket');
    } finally {
        tab.cleanup();
    }

    const storage = sharedStorage();
    const muted = openTab(storage);
    try {
        storage.setItem(PREFERENCE_KEY, 'off');
        muted.window.dispatchEvent(new muted.window.StorageEvent('storage', {key: PREFERENCE_KEY, newValue: 'off'}));
        assert.equal(muted.chime.calls.stop, 1);
        assert.equal(muted.document.querySelector('[data-ticket-sound-toggle]').getAttribute('aria-pressed'), 'false');
    } finally {
        muted.cleanup();
    }
});

test('blocked storage does not break the page', () => {
    const blocked = {getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); }};
    const store = safeStorage(blocked);
    assert.equal(store.get(PREFERENCE_KEY), null);
    assert.doesNotThrow(() => store.set(PREFERENCE_KEY, 'off'));
});

test('init is idempotent', () => {
    const tab = openTab(sharedStorage());
    try {
        assert.equal(initTicketAlert(tab.document, {chime: fakeChime()}), null);
    } finally {
        tab.cleanup();
    }
});

test('the alert never polls: its only request is the one sound file, it uses no timers and no native dialogs', () => {
    const source = readFileSync(new URL('../../resources/js/components/ticket-alert.js', import.meta.url), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/\s\/\/.*$/gm, '');

    assert.doesNotMatch(source, /\bfetch\(|XMLHttpRequest|setInterval|setTimeout\(/);
    // คำขอเดียวคือไฟล์เสียง (ครั้งเดียวต่อหน้า) — การหาเหตุการณ์ใหม่ยังเป็นของ realtime-sync
    assert.equal(source.match(/fetchImpl\(/g).length, 1);
    assert.match(source, /fetchImpl\(soundUrl,/);
    assert.doesNotMatch(source, /\b(alert|confirm|prompt)\(/);
    assert.match(source, /smartgoal:realtime-notification/);
});

test('the sound toggle lives on the ticket desk with a 44px touch target, not in the topbar', () => {
    const layout = readFileSync(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
    const desk = readFileSync(new URL('../../resources/views/tickets/index.blade.php', import.meta.url), 'utf8');
    const deskCss = readFileSync(new URL('../../resources/css/pages/tickets/desk.css', import.meta.url), 'utf8');
    const topbar = readFileSync(new URL('../../resources/css/components/layout/topbar.css', import.meta.url), 'utf8');

    assert.doesNotMatch(layout, /data-ticket-sound-toggle/);
    assert.doesNotMatch(topbar, /icon-btn--touch-target/);
    assert.match(desk, /@if \(\$ticketSoundToggle\)[\s\S]*?class="icon-btn icon-btn--touch-target" data-ticket-sound-toggle/);
    assert.match(desk, /<strong data-ticket-sound-state>/);
    // ขนาดที่มองเห็นยังเป็น .icon-btn 36px — 34px (ภายในขอบ 1px) + 5px × 2 = 44px
    assert.match(topbar, /\.icon-btn \{\s*width: 36px;\s*height: 36px;/);
    assert.match(deskCss, /\.icon-btn--touch-target::before \{[^}]*position: absolute;[^}]*inset: -5px;/);
});

test('the layout loads the alert on every page and keeps hidden tabs polling only behind the server-side flag', () => {
    const layout = readFileSync(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');

    assert.match(layout, /@if \(\$ticketAlertEnabled \?\? false\)\s*@vite\('resources\/js\/components\/ticket-alert\.js'\)/);
    assert.match(layout, /@if \(\$ticketAlertEnabled \?\? false\) data-realtime-background-interval="10000" data-ticket-alert-sound="\{\{ \$ticketAlertSound \}\}" data-ticket-alert-seconds="\{\{ \(int\) config\('tickets\.alert\.loop_seconds', 20\) \}\}" @endif/);
});
