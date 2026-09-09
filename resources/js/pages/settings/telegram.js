/**
 * การเชื่อมต่อ Telegram ในหน้าตั้งค่า
 *
 * การผูกบัญชีสำเร็จเกิดขึ้นที่ webhook ฝั่งเซิร์ฟเวอร์ ไม่ใช่ที่ request ของเบราว์เซอร์
 * หน้าเว็บจึงต้องถามสถานะเป็นระยะขณะที่หน้าต่างเชื่อมต่อเปิดอยู่ แล้วรีโหลดเมื่อผูกสำเร็จ
 *
 * หยุดถามเมื่อปิดหน้าต่างหรือครบเพดานเวลา เพื่อไม่ให้แท็บที่ถูกลืมเปิดทิ้งไว้ยิงตลอดไป
 */
export const POLL_INTERVAL_MS = 3000;
export const POLL_TIMEOUT_MS = 120000;

export function createTelegramLinkPoller({statusUrl, fetcher, onLinked, now = () => Date.now()}) {
    let timer = null;
    let startedAt = 0;

    const stop = () => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const tick = async () => {
        if (now() - startedAt >= POLL_TIMEOUT_MS) {
            stop();
            return;
        }

        try {
            const response = await fetcher(statusUrl, {headers: {Accept: 'application/json'}});
            const payload = await response.json();

            if (payload?.linked) {
                stop();
                onLinked(payload);
                return;
            }
        } catch {
            // เครือข่ายสะดุดชั่วคราวไม่ใช่เหตุให้เลิกรอ รอบถัดไปลองใหม่เอง
        }

        timer = setTimeout(tick, POLL_INTERVAL_MS);
    };

    return {
        start() {
            stop();
            startedAt = now();
            timer = setTimeout(tick, POLL_INTERVAL_MS);
        },
        stop,
        get running() {
            return timer !== null;
        },
    };
}

export function initializeTelegramSettings(root = document, options = {}) {
    const card = root.querySelector('[data-telegram-settings]');
    if (! card) return false;

    const {
        fetcher = globalThis.fetch?.bind(globalThis),
        bootstrapApi = globalThis.window?.bootstrap,
        swal = globalThis.window?.Swal,
        reload = () => globalThis.window?.location.reload(),
    } = options;

    bindToggle(card);
    bindUnlink(card, swal);
    bindConnect(root, card, {fetcher, bootstrapApi, swal, reload});

    return true;
}

function bindToggle(card) {
    const form = card.querySelector('[data-telegram-toggle-form]');
    const checkbox = form?.querySelector('input[type="checkbox"]');
    if (! form || ! checkbox) return;

    // ช่องติ๊กเป็นตัวควบคุมสายตาอย่างเดียว ค่าที่ส่งจริงอยู่ใน hidden input
    // ซึ่งเซิร์ฟเวอร์เรนเดอร์มาเป็นค่าตรงข้ามของสถานะปัจจุบันแล้ว
    checkbox.addEventListener('change', () => {
        checkbox.disabled = true;
        form.submit();
    });
}

function bindUnlink(card, swal) {
    const form = card.querySelector('[data-telegram-unlink-form]');
    if (! form) return;

    let confirmed = false;

    form.addEventListener('submit', (event) => {
        if (confirmed || typeof swal?.fire !== 'function') return;

        event.preventDefault();

        swal.fire({
            icon: 'warning',
            title: 'ยกเลิกการเชื่อมต่อ Telegram?',
            text: 'คุณจะไม่ได้รับการแจ้งเตือนทาง Telegram อีกจนกว่าจะเชื่อมต่อใหม่',
            showCancelButton: true,
            confirmButtonText: 'ยกเลิกการเชื่อมต่อ',
            cancelButtonText: 'ไม่ใช่ตอนนี้',
        }).then((result) => {
            if (! result.isConfirmed) return;
            confirmed = true;
            form.submit();
        });
    });
}

function bindConnect(root, card, {fetcher, bootstrapApi, swal, reload}) {
    const trigger = card.querySelector('[data-telegram-connect]');
    const modalElement = root.querySelector('[data-telegram-modal]');
    if (! trigger || ! modalElement || typeof fetcher !== 'function') return;

    const deepLink = modalElement.querySelector('[data-telegram-deep-link]');
    const waiting = modalElement.querySelector('[data-telegram-waiting]');

    const poller = createTelegramLinkPoller({
        statusUrl: card.dataset.statusUrl,
        fetcher,
        onLinked: () => reload(),
    });

    // ปิดหน้าต่างเมื่อไรก็หยุดถาม ไม่ว่าจะปิดด้วยปุ่ม Escape หรือคลิกฉากหลัง
    modalElement.addEventListener('hidden.bs.modal', () => poller.stop());

    trigger.addEventListener('click', async () => {
        trigger.disabled = true;

        try {
            const response = await fetcher(card.dataset.linkUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': root.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
            });
            const payload = await response.json();

            if (! payload?.ok || ! payload.deep_link) {
                throw new Error(payload?.message ?? 'ขอรหัสเชื่อมต่อไม่สำเร็จ');
            }

            if (deepLink) {
                deepLink.href = payload.deep_link;
                deepLink.hidden = false;
            }
            if (waiting) waiting.hidden = false;

            bootstrapApi?.Modal?.getOrCreateInstance(modalElement).show();
            poller.start();
        } catch (error) {
            swal?.fire({
                icon: 'error',
                title: 'เชื่อมต่อไม่สำเร็จ',
                text: error.message,
                confirmButtonText: 'ตกลง',
            });
        } finally {
            trigger.disabled = false;
        }
    });
}
