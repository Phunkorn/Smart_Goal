/*
 * ตัวจัดตารางการบันทึกอัตโนมัติ และสถานะที่แสดงให้ผู้ใช้เห็น
 *
 * ระบบไม่ทำ realtime การกันเขียนทับจึงพึ่งกลไกสองอย่าง
 * 1. ฝั่งเซิร์ฟเวอร์ตรวจเลขเวอร์ชันในคำสั่ง UPDATE เดียว (ดู WorkspaceBoardDocumentService)
 * 2. ฝั่งนี้ต้องไม่ยิงคำขอด้วยเวอร์ชันเก่าซ้ำ ๆ และต้องหยุดทันทีเมื่อชนกัน
 *
 * โมดูลนี้ไม่แตะ DOM และไม่เรียก fetch เอง นาฬิกา ตัวตั้งเวลา และฟังก์ชันบันทึก
 * ถูกฉีดเข้ามาทั้งหมด เทสต์จึงรันสถานการณ์ทั้งชุดได้ในเสี้ยววินาทีด้วยนาฬิกาปลอม
 */

/** สถานะที่เป็นไปได้ทั้งหมด ข้อความไทยอยู่ใน WorkspaceDesign ฝั่ง Blade */
export const SAVE_STATES = ['saved', 'dirty', 'saving', 'error', 'conflict'];

/** จังหวะการหน่วงก่อนบันทึก และเพดานที่ยอมให้หน่วงได้นานที่สุด */
export const DEBOUNCE_MS = 1200;

export const MAX_WAIT_MS = 5000;

/** ระยะรอก่อนลองใหม่เมื่อบันทึกไม่สำเร็จ */
export const RETRY_DELAYS_MS = [2000, 5000, 15000];

/**
 * @param {object} options
 * @param {Function} options.save   ฟังก์ชันบันทึกจริง คืน Promise ที่ resolve เมื่อสำเร็จ
 * @param {Function} options.onState เรียกทุกครั้งที่สถานะเปลี่ยน
 * @param {Function} options.now     คืนเวลาปัจจุบันเป็นมิลลิวินาที
 */
export const createSaveScheduler = ({
    save,
    onState,
    now = () => Date.now(),
    setTimeoutImpl = setTimeout,
    clearTimeoutImpl = clearTimeout,
    debounceMs = DEBOUNCE_MS,
    maxWaitMs = MAX_WAIT_MS,
    retryDelaysMs = RETRY_DELAYS_MS,
}) => {
    let state = 'saved';
    let timer = null;
    let firstDirtyAt = null;
    let gestureActive = false;
    let inFlight = false;
    let pendingWhileSaving = false;
    let retryIndex = 0;
    let stopped = false;

    const setState = (next, detail = {}) => {
        state = next;
        onState?.(next, detail);
    };

    const cancelTimer = () => {
        if (timer !== null) {
            clearTimeoutImpl(timer);
            timer = null;
        }
    };

    const schedule = (delay) => {
        cancelTimer();
        timer = setTimeoutImpl(() => {
            timer = null;
            flush();
        }, delay);
    };

    /**
     * คิดว่าควรรออีกกี่มิลลิวินาที
     *
     * ปกติหน่วง debounceMs หลังการแก้ครั้งล่าสุด แต่ถ้ามีการแก้ต่อเนื่องจนเลย
     * maxWaitMs นับจากการแก้ครั้งแรกที่ยังไม่ได้บันทึก ให้บันทึกทันที เพื่อให้
     * คนที่วาดยาว ๆ ไม่จบสักทีมีจุดบันทึกเป็นระยะ ไม่ใช่เสี่ยงเสียงานทั้งหมด
     */
    const nextDelay = () => {
        const elapsed = now() - (firstDirtyAt ?? now());

        return Math.max(0, Math.min(debounceMs, maxWaitMs - elapsed));
    };

    const flush = async () => {
        if (stopped || state === 'conflict') {
            return;
        }

        // ห้ามบันทึกกลางท่าลาก มิฉะนั้นเซิร์ฟเวอร์จะได้เส้นที่วาดค้างครึ่งทาง
        // แล้วคนอื่นที่รีเฟรชจะเห็นงานที่ยังไม่เสร็จ
        if (gestureActive) {
            return;
        }

        if (inFlight) {
            pendingWhileSaving = true;

            return;
        }

        cancelTimer();
        inFlight = true;
        setState('saving');

        try {
            const result = await save();

            inFlight = false;
            firstDirtyAt = null;
            retryIndex = 0;
            setState('saved', result || {});

            // มีการแก้เพิ่มระหว่างที่กำลังบันทึกอยู่ ต้องยิงรอบใหม่ทันที
            if (pendingWhileSaving) {
                pendingWhileSaving = false;
                markDirty();
            }
        } catch (error) {
            inFlight = false;

            if (error?.isVersionConflict) {
                // การชนเวอร์ชันหยุดการบันทึกอัตโนมัติทันที การลองใหม่ด้วย
                // เวอร์ชันเดิมจะชนซ้ำไปเรื่อย ๆ และรบกวนผู้ใช้ด้วยกล่องเตือนซ้ำ
                cancelTimer();
                setState('conflict', {error});

                return;
            }

            setState('error', {error});

            const delay = retryDelaysMs[Math.min(retryIndex, retryDelaysMs.length - 1)];
            retryIndex += 1;
            schedule(delay);
        }
    };

    const markDirty = () => {
        if (stopped || state === 'conflict') {
            return;
        }

        if (firstDirtyAt === null) {
            firstDirtyAt = now();
        }

        // มีคำขอค้างอยู่แล้ว จดไว้ว่ามีของใหม่ตามมา แล้วปล่อยให้รอบที่กำลังบิน
        // อยู่จบก่อน การตั้งตัวจับเวลาเพิ่มตอนนี้ไม่มีประโยชน์ เพราะ flush()
        // จะเห็นว่ายังบินอยู่แล้วกลับออกไปเฉย ๆ และป้ายสถานะจะกระพริบเป็น
        // "ยังไม่ได้บันทึก" ทั้งที่กำลังบันทึกอยู่จริง
        if (inFlight) {
            pendingWhileSaving = true;

            return;
        }

        setState('dirty');

        if (! gestureActive) {
            schedule(nextDelay());
        }
    };

    return {
        /** สถานะปัจจุบัน ใช้โดยเทสต์และโดยตัวบอกสถานะบนหน้าจอ */
        state: () => state,

        isDirty: () => state === 'dirty' || state === 'error' || inFlight,

        /** มีการแก้เนื้อหา */
        markDirty,

        /** เริ่มท่าลาก การบันทึกจะถูกหน่วงไว้จนกว่าจะจบท่า */
        beginGesture() {
            gestureActive = true;
            cancelTimer();
        },

        /** จบท่าลาก ถ้ามีอะไรค้างอยู่ให้จัดตารางบันทึกต่อ */
        endGesture() {
            gestureActive = false;

            if (firstDirtyAt !== null) {
                schedule(nextDelay());
            }
        },

        /** บันทึกเดี๋ยวนี้ (ปุ่มบันทึก และตอนสลับแท็บ) */
        flushNow: () => flush(),

        /**
         * กลับมาทำงานต่อหลังผู้ใช้โหลดเนื้อหาฉบับล่าสุดแล้ว
         *
         * ต้องเรียกหลังจากที่ฝั่งหน้าจอรับเวอร์ชันใหม่ไปแล้วเท่านั้น มิฉะนั้น
         * จะกลับไปยิงด้วยเวอร์ชันเก่าและชนซ้ำทันที
         */
        resume() {
            cancelTimer();
            firstDirtyAt = null;
            retryIndex = 0;
            pendingWhileSaving = false;
            setState('saved');
        },

        /** หยุดถาวร ใช้ตอนออกจากหน้า */
        stop() {
            stopped = true;
            cancelTimer();
        },
    };
};
