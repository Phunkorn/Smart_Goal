/**
 * เสียงแจ้งเตือน Ticket ใหม่ของ Smart Ticket — ทำงานได้จากทุกหน้าที่ใช้ layouts.app
 *
 * server โหลดไฟล์นี้เฉพาะ agent ที่เปิดรับแจ้งเตือน (AppServiceProvider → $ticketAlertEnabled)
 * ไฟล์นี้ไม่ยิงคำขอเอง ฟังเฉพาะ event "smartgoal:realtime-notification" จาก realtime-sync.js
 *
 * กติกา
 *   - เล่นเฉพาะ type === 'ticket_created' ที่เพิ่งเกิด (ไม่เกิน STALE_AFTER_MS) — ไม่เล่นย้อนหลัง
 *   - เสียงคือไฟล์ของระบบ (config/tickets.php → alert) เล่นวน 20 วินาทีต่อ Ticket แล้วหยุดเอง
 *     ปิดเสียง (แท็บไหนก็ได้) หยุดทันที · แท็บอื่นรับ Ticket ใหม่กว่า = แท็บนี้หยุด ดังทีละแท็บ
 *   - refresh ไม่เล่นซ้ำ: realtime-sync เริ่ม cursor ที่การแจ้งเตือนล่าสุด และที่นี่จำ id ที่เล่นแล้ว
 *   - หลายแท็บเล่นครั้งเดียว: แท็บที่ได้ Web Lock ก่อนเป็นผู้เล่น (ไม่มี Web Locks = ใช้ localStorage)
 *     เฉพาะแท็บที่เสียงพร้อมเล่นเท่านั้นที่จองได้ — แท็บที่ยังถูกล็อกเสียงต้องไม่กลืนเสียงของแท็บอื่น
 *   - เคารพ autoplay policy: สร้างเสียงตั้งแต่โหลดหน้า (Chrome อนุญาตเมื่อมาจากการคลิกเมนูในเว็บเดียวกัน)
 *     ถ้ายังถูกล็อก (เปิดหน้าตรง/รีเฟรช) ปลดล็อกเมื่อผู้ใช้กดหรือพิมพ์ครั้งแรก ยังไม่ปลดล็อก = เงียบ
 *   - แท็บที่ถูกซ่อนยังได้ event เพราะ realtime-sync ตรวจต่อทุก 10 วินาทีสำหรับ agent ที่ฟังเสียง (layouts.app)
 *   - ไม่มี AudioContext หรือเล่นไม่ได้ = เงียบ ไม่มี error
 *   - ค่าเปิด/ปิดเสียงเป็นของเบราว์เซอร์นี้ (localStorage) และซิงก์ทุกแท็บ — ปุ่มอยู่บนหน้ารับแจ้งปัญหา (tickets/index)
 */

export const PREFERENCE_KEY = 'smartgoal.ticketAlert.sound';
export const LAST_PLAYED_KEY = 'smartgoal.ticketAlert.lastId';
export const STALE_AFTER_MS = 2 * 60 * 1000;
export const MIN_GAP_MS = 2500;
export const DEFAULT_LOOP_SECONDS = 20;

const REALTIME_EVENT = 'smartgoal:realtime-notification';
const LOCK_NAME = 'smartgoal-ticket-alert';

/** event นี้คือ Ticket ใหม่ที่ควรมีเสียงหรือไม่ */
export function isFreshTicketCreated(detail, nowMs) {
    if (detail?.type !== 'ticket_created') return false;

    const id = Number(detail.id);
    if (! Number.isInteger(id) || id <= 0) return false;

    const createdAt = Date.parse(detail.created_at ?? '');

    return Number.isNaN(createdAt) || nowMs - createdAt <= STALE_AFTER_MS;
}

/** localStorage อาจใช้ไม่ได้ (private mode / ถูกบล็อก) — ห้ามทำให้หน้าพัง */
export function safeStorage(storage) {
    return {
        get(key) {
            try {
                return storage?.getItem(key) ?? null;
            } catch {
                return null;
            }
        },
        set(key, value) {
            try {
                storage?.setItem(key, value);
            } catch {
                // เก็บไม่ได้ก็แค่จำค่าไม่ได้ข้ามการโหลดหน้า
            }
        },
    };
}

/**
 * จอง id นี้ให้แท็บนี้เป็นผู้เล่น — คืน true เฉพาะแท็บแรกที่เห็น id ที่ใหม่กว่าที่เคยเล่น
 * Web Locks ทำให้การอ่าน-เขียนของหลายแท็บเรียงกันทีละแท็บ
 */
export async function claimTicket(id, store, locks) {
    const decide = () => {
        const lastPlayed = Number(store.get(LAST_PLAYED_KEY)) || 0;
        if (id <= lastPlayed) return false;
        store.set(LAST_PLAYED_KEY, String(id));

        return true;
    };

    if (typeof locks?.request === 'function') {
        try {
            return await locks.request(LOCK_NAME, decide);
        } catch {
            return decide();
        }
    }

    return decide();
}

/** เสียง "ติ๊ง-ตอง" สั้น ๆ ด้วย Web Audio — ใช้แทนเมื่อยังไม่มีไฟล์เสียงหรือโหลดไฟล์ไม่ได้ */
function playChime(context) {
    const start = context.currentTime;
    [[880, 0], [1175, 0.16]].forEach(([frequency, offset]) => {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start + offset);
        gain.gain.exponentialRampToValueAtTime(0.25, start + offset + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.28);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start(start + offset);
        oscillator.stop(start + offset + 0.3);
    });

    return true;
}

/**
 * เสียงแจ้ง Ticket ใหม่ — ไฟล์เสียงของระบบ (config/tickets.php → alert) เล่นวน loopSeconds วินาทีแล้วหยุดเอง
 *
 * ไฟล์โหลดครั้งเดียวต่อหน้าเมื่อ AudioContext ถูกสร้าง (ไม่ใช่การตรวจ server) แล้ว decode เก็บไว้
 * เวลาหยุดตั้งด้วยนาฬิกาของ AudioContext (source.stop(เวลา)) ไม่ใช้ timer จึงตรงแม้แท็บอยู่เบื้องหลัง
 * Ticket ใหม่ระหว่างที่ยังดังอยู่เริ่มนับใหม่ ไม่ซ้อนเสียง · ไม่มีไฟล์/โหลดไม่ได้ = เสียงสังเคราะห์เดิมหนึ่งครั้ง
 */
export function createChime(AudioContextCtor, {
    soundUrl = '',
    loopSeconds = DEFAULT_LOOP_SECONDS,
    fetchImpl = globalThis.fetch?.bind(globalThis),
} = {}) {
    let context = null;
    let buffer = null;
    let loading = null;
    let ringing = null;

    const load = () => {
        if (loading || ! soundUrl || ! context || typeof fetchImpl !== 'function') return;
        loading = Promise.resolve()
            .then(() => fetchImpl(soundUrl, {credentials: 'same-origin'}))
            .then((response) => (response.ok ? response.arrayBuffer() : Promise.reject(new Error('sound unavailable'))))
            .then((data) => context.decodeAudioData(data))
            .then((decoded) => { buffer = decoded; })
            .catch(() => { buffer = null; });
    };

    const startFile = (loop) => {
        const source = context.createBufferSource();
        source.buffer = buffer;
        source.loop = loop;
        source.connect(context.destination);
        const at = context.currentTime;
        source.start(at);
        source.stop(at + (loop ? loopSeconds : Math.min(buffer.duration, loopSeconds)));

        return source;
    };

    const stop = () => {
        try {
            ringing?.stop();
        } catch {
            // หยุดไปแล้ว
        }
        ringing = null;
    };

    return {
        unlock() {
            try {
                context ??= AudioContextCtor ? new AudioContextCtor() : null;
                if (context?.state === 'suspended') context.resume?.()?.catch?.(() => {});
                load();
            } catch {
                context = null;
            }

            return context !== null;
        },
        canPlay() {
            return context?.state === 'running';
        },
        /** Ticket ใหม่: ไฟล์เสียงเล่นวนจนครบ loopSeconds แล้วหยุดเอง */
        play() {
            try {
                if (! context || context.state !== 'running') return false;
                if (! buffer) return playChime(context);

                stop();
                const source = startFile(true);
                ringing = source;
                source.onended = () => {
                    if (ringing === source) ringing = null;
                };

                return true;
            } catch {
                return false;
            }
        },
        /** ตัวอย่างตอนกดเปิดเสียง: ไฟล์รอบเดียว (รอไฟล์ที่กำลังโหลดอยู่ก่อน) */
        preview() {
            if (! context) return false;
            const run = () => {
                try {
                    if (context.state === 'running') buffer ? startFile(false) : playChime(context);
                } catch {
                    // เล่นตัวอย่างไม่ได้ก็ไม่เป็นไร
                }
            };
            Promise.resolve(context.state === 'running' ? null : context.resume?.())
                .then(() => loading)
                .then(run, run);

            return true;
        },
        stop,
        isRinging: () => ringing !== null,
    };
}

function renderToggle(root, toggle, enabled) {
    root.querySelectorAll('[data-ticket-sound-state]').forEach((state) => {
        state.textContent = enabled ? 'เปิด' : 'ปิด';
    });
    if (! toggle) return;

    const label = enabled ? 'ปิดเสียงแจ้งเตือน Ticket ใหม่' : 'เปิดเสียงแจ้งเตือน Ticket ใหม่';
    toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
    toggle.setAttribute('aria-label', label);
    toggle.title = enabled ? 'เสียงแจ้งเตือน Ticket ใหม่: เปิด' : 'เสียงแจ้งเตือน Ticket ใหม่: ปิด';

    const icon = toggle.querySelector('i');
    icon?.classList.toggle('bi-volume-up-fill', enabled);
    icon?.classList.toggle('bi-volume-mute-fill', ! enabled);
}

/**
 * เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ — คืนตัวควบคุมสำหรับเทสต์ หรือ null เมื่อผูกไปแล้ว
 *
 * @param {Document} root
 */
export function initTicketAlert(root = document, {
    storage = globalThis.window?.localStorage,
    locks = globalThis.navigator?.locks,
    AudioContextCtor = globalThis.window?.AudioContext ?? globalThis.window?.webkitAudioContext,
    now = () => Date.now(),
    chime = null,
} = {}) {
    const body = root.body;
    if (! body || body.dataset.ticketAlertReady === 'true') return null;
    body.dataset.ticketAlertReady = 'true';

    const store = safeStorage(storage);
    // ไฟล์เสียงและเวลาที่เล่นวนมาจาก layouts.app (config/tickets.php → alert)
    const sound = chime ?? createChime(AudioContextCtor, {
        soundUrl: body.dataset.ticketAlertSound ?? '',
        loopSeconds: Number(body.dataset.ticketAlertSeconds) || DEFAULT_LOOP_SECONDS,
    });
    const toggle = root.querySelector('[data-ticket-sound-toggle]');
    const isEnabled = () => store.get(PREFERENCE_KEY) !== 'off';
    let lastPlayedAt = 0;

    // autoplay policy: ลองตั้งแต่โหลด (มาจากการคลิกเมนู = ได้เลย) ไม่ได้ก็ปลุกใหม่เมื่อผู้ใช้โต้ตอบกับหน้าครั้งแรก
    if (isEnabled()) sound.unlock();
    const unlockOnGesture = () => {
        if (isEnabled()) sound.unlock();
    };
    root.addEventListener('pointerdown', unlockOnGesture, {once: true, capture: true});
    root.addEventListener('keydown', unlockOnGesture, {once: true, capture: true});

    toggle?.addEventListener('click', () => {
        const enabled = ! isEnabled();
        store.set(PREFERENCE_KEY, enabled ? 'on' : 'off');
        renderToggle(root, toggle, enabled);

        // การคลิกคือ user gesture — ปลดล็อกและเล่นตัวอย่างให้รู้ว่าเสียงเปิดแล้ว · ปิดเสียง = หยุดเสียงที่กำลังดังทันที
        if (enabled && sound.unlock()) sound.preview();
        if (! enabled) sound.stop();
    });

    root.defaultView?.addEventListener('storage', (event) => {
        // เปลี่ยนค่าจากแท็บอื่น → ปุ่มในแท็บนี้ต้องตรงกัน และปิดเสียงจากแท็บไหนก็หยุดเสียงที่ดังอยู่
        if (event.key === PREFERENCE_KEY) {
            renderToggle(root, toggle, isEnabled());
            if (! isEnabled()) sound.stop();
        }
        // แท็บอื่นจอง Ticket ที่ใหม่กว่าและกำลังดังแทน — แท็บนี้หยุด ไม่ให้สองแท็บดังพร้อมกัน
        if (event.key === LAST_PLAYED_KEY) sound.stop();
    });

    root.addEventListener(REALTIME_EVENT, async (event) => {
        const detail = event.detail;
        if (! isEnabled() || ! isFreshTicketCreated(detail, now())) return;
        // แท็บที่เสียงยังถูกล็อกไม่จอง — ปล่อยให้แท็บที่เล่นได้เป็นผู้เล่น
        if (! sound.canPlay() || ! await claimTicket(Number(detail.id), store, locks)) return;

        // Ticket หลายใบที่มาพร้อมกันได้เสียงเดียว
        if (now() - lastPlayedAt < MIN_GAP_MS) return;
        if (sound.play()) lastPlayedAt = now();
    });

    renderToggle(root, toggle, isEnabled());

    return {isEnabled, toggle};
}

if (typeof document !== 'undefined') initTicketAlert(document);
