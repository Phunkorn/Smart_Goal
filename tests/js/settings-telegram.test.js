import test from 'node:test';
import assert from 'node:assert/strict';
import {createTelegramLinkPoller, initializeTelegramSettings, POLL_INTERVAL_MS, POLL_TIMEOUT_MS} from '../../resources/js/pages/settings/telegram.js';
import {click, clickCheckbox, mountDom} from './helpers/dom.js';

const STATUS_URL = '/settings/telegram/status';
const LINK_URL = '/settings/telegram/link';

function cardMarkup({linked = false} = {}) {
    const actions = linked
        ? `<form action="/settings/telegram" method="POST" data-telegram-unlink-form>
                <button type="submit">ยกเลิกการเชื่อมต่อ</button>
            </form>
            <form action="/settings/telegram" method="POST" class="settings-telegram__toggle" data-telegram-toggle-form>
                <input type="hidden" name="telegram_notifications_enabled" value="0">
                <input class="form-check-input" type="checkbox" id="telegramEnabledSwitch" checked>
            </form>`
        : '<button type="button" data-telegram-connect>เชื่อมต่อ Telegram</button>';

    return `<!doctype html><html><head><meta name="csrf-token" content="test-csrf"></head><body>
        <div class="settings-page" data-settings-page>
            <section data-telegram-settings data-status-url="${STATUS_URL}" data-link-url="${LINK_URL}"
                data-linked="${linked}">${actions}</section>
            <div class="modal" data-telegram-modal>
                <a href="#" data-telegram-deep-link hidden>เปิดบอท</a>
                <div data-telegram-waiting hidden>รอ…</div>
            </div>
        </div>
    </body></html>`;
}

function fakeModalApi() {
    const calls = {shown: 0};

    return {
        calls,
        api: {
            Modal: {
                getOrCreateInstance: () => ({
                    show() {
                        calls.shown++;
                    },
                }),
            },
        },
    };
}

test('การกดเชื่อมต่อขอลิงก์ลึก เปิดหน้าต่าง แล้วเริ่มรอการยืนยัน', async () => {
    const dom = mountDom(cardMarkup());
    const {calls, api} = fakeModalApi();
    const requests = [];

    const fetcher = async (url, options = {}) => {
        requests.push({url, options});

        return {
            json: async () => url === LINK_URL
                ? {ok: true, deep_link: 'https://t.me/SmartGoalTestBot?start=abc123'}
                : {ok: true, linked: false},
        };
    };

    assert.equal(initializeTelegramSettings(dom.document, {fetcher, bootstrapApi: api}), true);

    click(dom.document.querySelector('[data-telegram-connect]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    const deepLink = dom.document.querySelector('[data-telegram-deep-link]');
    assert.equal(deepLink.getAttribute('href'), 'https://t.me/SmartGoalTestBot?start=abc123');
    assert.equal(deepLink.hidden, false);
    assert.equal(dom.document.querySelector('[data-telegram-waiting]').hidden, false);
    assert.equal(calls.shown, 1);

    // รหัสต้องขอผ่าน POST พร้อม CSRF token ไม่ใช่ GET ที่ prefetch ของเบราว์เซอร์ยิงเองได้
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'test-csrf');

    dom.cleanup();
});

test('การขอลิงก์ที่ล้มเหลวแจ้งผู้ใช้และคืนปุ่มให้กดใหม่ได้', async () => {
    const dom = mountDom(cardMarkup());
    const alerts = [];
    const fetcher = async () => ({json: async () => ({ok: false, message: 'ยังไม่ได้ตั้งค่าบอท'})});

    initializeTelegramSettings(dom.document, {
        fetcher,
        bootstrapApi: fakeModalApi().api,
        swal: {fire: (options) => alerts.push(options)},
    });

    const trigger = dom.document.querySelector('[data-telegram-connect]');
    click(trigger);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(alerts.length, 1);
    assert.equal(alerts[0].text, 'ยังไม่ได้ตั้งค่าบอท');
    assert.equal(trigger.disabled, false);
    assert.equal(dom.document.querySelector('[data-telegram-deep-link]').hidden, true);

    dom.cleanup();
});

test('การสลับสวิตช์ส่งฟอร์มค่าตรงข้ามที่เซิร์ฟเวอร์เตรียมไว้', () => {
    const dom = mountDom(cardMarkup({linked: true}));
    let submitted = null;

    initializeTelegramSettings(dom.document, {fetcher: async () => ({json: async () => ({})})});

    const form = dom.document.querySelector('[data-telegram-toggle-form]');
    form.submit = () => {
        submitted = form.querySelector('input[name="telegram_notifications_enabled"]').value;
    };

    clickCheckbox(form.querySelector('input[type="checkbox"]'));

    assert.equal(submitted, '0');

    dom.cleanup();
});

test('การยกเลิกการเชื่อมต่อต้องยืนยันก่อน และไม่ส่งฟอร์มเมื่อผู้ใช้ปฏิเสธ', async () => {
    const dom = mountDom(cardMarkup({linked: true}));
    let submitCount = 0;
    let answer = false;

    initializeTelegramSettings(dom.document, {
        fetcher: async () => ({json: async () => ({})}),
        swal: {fire: async () => ({isConfirmed: answer})},
    });

    const form = dom.document.querySelector('[data-telegram-unlink-form]');
    form.submit = () => {
        submitCount++;
    };

    form.dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(submitCount, 0);

    answer = true;
    form.dispatchEvent(new dom.window.Event('submit', {bubbles: true, cancelable: true}));
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(submitCount, 1);

    dom.cleanup();
});

test('ตัวถามสถานะหยุดเองเมื่อผูกสำเร็จ', async () => {
    let linked = false;
    let reloaded = 0;

    const poller = createTelegramLinkPoller({
        statusUrl: STATUS_URL,
        fetcher: async () => ({json: async () => ({ok: true, linked})}),
        onLinked: () => {
            reloaded++;
        },
    });

    poller.start();
    linked = true;

    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS + 60));

    assert.equal(reloaded, 1);
    assert.equal(poller.running, false);
});

test('ตัวถามสถานะเลิกถามเมื่อครบเพดานเวลา แม้ยังไม่ผูกสำเร็จ', async () => {
    let now = 0;
    let calls = 0;

    const poller = createTelegramLinkPoller({
        statusUrl: STATUS_URL,
        fetcher: async () => {
            calls++;

            return {json: async () => ({ok: true, linked: false})};
        },
        onLinked: () => {
            throw new Error('ต้องไม่ถูกเรียกเมื่อยังไม่ผูก');
        },
        now: () => now,
    });

    poller.start();
    now = POLL_TIMEOUT_MS;

    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS + 60));

    assert.equal(calls, 0);
    assert.equal(poller.running, false);
});

test('เครือข่ายสะดุดไม่ทำให้เลิกรอ', async () => {
    let attempts = 0;
    let reloaded = 0;

    const poller = createTelegramLinkPoller({
        statusUrl: STATUS_URL,
        fetcher: async () => {
            attempts++;
            if (attempts === 1) throw new Error('network down');

            return {json: async () => ({ok: true, linked: true})};
        },
        onLinked: () => {
            reloaded++;
        },
    });

    poller.start();

    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS * 2 + 120));

    assert.equal(attempts, 2);
    assert.equal(reloaded, 1);

    poller.stop();
});

test('การ์ด Telegram ใน Blade มี hook ที่โมดูลนี้ต้องใช้ครบ', async () => {
    const {readFile} = await import('node:fs/promises');
    const markup = await readFile(new URL('../../resources/views/settings/components/telegram-card.blade.php', import.meta.url), 'utf8');

    for (const hook of [
        'data-telegram-settings',
        'data-status-url',
        'data-link-url',
        'data-telegram-connect',
        'data-telegram-modal',
        'data-telegram-deep-link',
        'data-telegram-waiting',
        'data-telegram-toggle-form',
        'data-telegram-unlink-form',
    ]) {
        assert.ok(markup.includes(hook), `Blade ต้องมี ${hook}`);
    }
});
