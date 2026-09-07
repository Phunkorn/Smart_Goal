/*
 * แถบตัวจับเวลาที่กำลังเดิน
 *
 * ตัวเลขที่เห็นเป็นเพียงการแสดงผลจาก started_at ที่เซิร์ฟเวอร์ส่งมา จำนวนนาที
 * ที่ถูกบันทึกจริงคำนวณด้วยเวลาของเซิร์ฟเวอร์เสมอตอนกด "เสร็จสิ้น" เพราะนาฬิกา
 * ของเครื่องผู้ใช้ตั้งเองได้ และตัวเลขนี้ถูกใช้ต่อในรายงานภาระงาน
 *
 * ตัวจับเวลาถูกฉีดเข้ามาได้ (clock, setIntervalImpl) เพื่อให้ทดสอบได้แบบกำหนด
 * ผลลัพธ์แน่นอน โดยไม่ต้องรอเวลาจริงเดิน
 */
import {elapsedSeconds, stopwatchLabel} from './duration.js';

export function initTimerBanner({
    root = document.querySelector('[data-daily-log]'),
    clock = () => Date.now(),
    setIntervalImpl = globalThis.setInterval,
    clearIntervalImpl = globalThis.clearInterval,
    onStop = () => {},
} = {}) {
    const banner = root?.querySelector('[data-timer-banner]');

    if (! root || ! banner || banner.dataset.timerReady === 'on') return null;

    banner.dataset.timerReady = 'on';

    const clockNode = banner.querySelector('[data-timer-clock]');
    const titleNode = banner.querySelector('[data-timer-title]');
    let ticker = null;

    const render = () => {
        const startedAt = banner.dataset.startedAt;

        if (! startedAt) return;

        if (clockNode) clockNode.textContent = stopwatchLabel(elapsedSeconds(startedAt, clock()));
    };

    const stopTicking = () => {
        if (ticker === null) return;

        clearIntervalImpl(ticker);
        ticker = null;
    };

    const startTicking = () => {
        stopTicking();
        render();
        ticker = setIntervalImpl(render, 1000);
    };

    const show = (log) => {
        banner.dataset.startedAt = log?.started_at || '';
        banner.dataset.logId = log?.id ? String(log.id) : '';
        if (titleNode) titleNode.textContent = log?.title || '';
        banner.hidden = false;
        startTicking();
    };

    const hide = () => {
        stopTicking();
        banner.hidden = true;
        banner.dataset.startedAt = '';
        banner.dataset.logId = '';
    };

    banner.addEventListener('click', (event) => {
        if (! event.target.closest('[data-timer-stop]')) return;

        event.preventDefault();
        onStop(banner.dataset.logId);
    });

    if (! banner.hidden && banner.dataset.startedAt) startTicking();

    return {
        show,
        hide,
        render,
        /** ปรับแถบให้ตรงกับรายการที่เพิ่งเปลี่ยนสถานะ */
        syncWith(log) {
            if (log?.is_running) show(log);
            else if (! log?.id || String(log.id) === banner.dataset.logId) hide();
        },
        destroy() {
            stopTicking();
            delete banner.dataset.timerReady;
        },
    };
}
